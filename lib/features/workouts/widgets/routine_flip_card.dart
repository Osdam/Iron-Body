import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/foundation.dart' show kDebugMode;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:shimmer/shimmer.dart';
import 'package:video_player/video_player.dart';

import '../../../core/theme/app_colors.dart';
import '../../../data/models/exercise_reference.dart';
import '../../../data/models/workout_model.dart';
import '../services/exercise_reference_service.dart';

/// Card con flip 3D premium reutilizable.
///
/// - Front: el diseño actual de la rutina (se recibe tal cual) + una pista
///   sutil de que hay vista trasera.
/// - Back: referencia visual del ejercicio (GIF) servida por el backend.
///
/// El flip se dispara tocando la card. El botón "Iniciar rutina" del front
/// conserva su propio gesto (gana el arena de gestos), así un toque sobre él
/// no voltea la card.
class RoutineFlipCard extends StatefulWidget {
  final WorkoutModel workout;
  final Widget front;

  const RoutineFlipCard({
    super.key,
    required this.workout,
    required this.front,
  });

  @override
  State<RoutineFlipCard> createState() => _RoutineFlipCardState();
}

class _RoutineFlipCardState extends State<RoutineFlipCard>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  late final Animation<double> _anim;

  bool _showingBack = false;
  int _exIdx = 0;

  // Cache de la referencia por nombre de ejercicio (evita re-fetch al girar).
  final Map<String, Future<ExerciseReference?>> _refFutures = {};

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 540),
    );
    _anim = CurvedAnimation(parent: _ctrl, curve: Curves.easeInOutCubic);
  }

  @override
  void didUpdateWidget(RoutineFlipCard old) {
    super.didUpdateWidget(old);
    // Si la card pasa a representar otra rutina (deck swipe), volver al front.
    if (old.workout.id != widget.workout.id) {
      _showingBack = false;
      _exIdx = 0;
      _ctrl.value = 0;
    }
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  void _flip() {
    HapticFeedback.selectionClick();
    setState(() => _showingBack = !_showingBack);
    if (_showingBack) {
      _prefetchAround();
      _ctrl.forward();
    } else {
      _ctrl.reverse();
    }
  }

  Future<ExerciseReference?> _refFor(String name) {
    return _refFutures.putIfAbsent(name, () async {
      final list =
          await ExerciseReferenceService.instance.searchByName(name);
      final ref = (list == null || list.isEmpty) ? null : list.first;
      if (kDebugMode) {
        debugPrint('[FitGif] "$name" → gif=${ref?.hasGif ?? false} '
            'url=${ref?.gifUrl ?? "null"}');
      }
      return ref;
    });
  }

  /// Prefetch + precache del ejercicio actual y sus vecinos (carrusel 1/3),
  /// así navegar entre ejercicios no muestra carga.
  void _prefetchAround() {
    final ex = widget.workout.exercises;
    for (final d in const [0, 1, -1]) {
      final i = _exIdx + d;
      if (i < 0 || i >= ex.length) continue;
      _refFor(ex[i].exercise.name).then((ref) {
        if (ref != null && ref.hasGif && mounted) {
          precacheImage(NetworkImage(ref.gifUrl!), context);
        }
      });
    }
  }

  void _changeExercise(int delta) {
    final n = widget.workout.exercises.length;
    if (n <= 1) return;
    HapticFeedback.selectionClick();
    // El operador % de Dart con divisor positivo siempre da 0..n-1.
    setState(() => _exIdx = (_exIdx + delta) % n);
    _prefetchAround();
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: _flip,
      child: AnimatedBuilder(
        animation: _anim,
        builder: (context, _) {
          final t = _anim.value; // 0 = front, 1 = back
          final angle = t * math.pi;
          final isBack = t >= 0.5;

          final transform = Matrix4.identity()
            ..setEntry(3, 2, 0.0014) // perspectiva
            ..rotateY(angle);

          return Transform(
            alignment: Alignment.center,
            transform: transform,
            child: isBack
                ? Transform(
                    alignment: Alignment.center,
                    transform: Matrix4.identity()..rotateY(math.pi),
                    child: _back(),
                  )
                : _frontWithHint(),
          );
        },
      ),
    );
  }

  // ── Front ────────────────────────────────────────────────────────────────

  Widget _frontWithHint() {
    return Stack(
      children: [
        widget.front,
        // Pista sutil: chip "ver referencia" debajo del badge de nivel,
        // alineado a su borde izquierdo. Card padding = 22; el badge ocupa
        // ~24px (y=22..46) → top:66 deja ~20px de aire bajo el badge: bloque
        // ordenado (Nivel · espacio · chip) sin montarse sobre el ícono
        // (derecha) ni el título (más abajo tras un Spacer).
        Positioned(
          top: 66,
          left: 22,
          child: IgnorePointer(
            child: Container(
              padding:
                  const EdgeInsets.symmetric(horizontal: 11, vertical: 6),
              decoration: BoxDecoration(
                color: AppColors.dark.withValues(alpha: 0.86),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.visibility_rounded,
                      size: 13, color: AppColors.primary),
                  const Gap(6),
                  Text(
                    'Toca para ver referencia',
                    style: GoogleFonts.inter(
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                      color: AppColors.primary,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }

  // ── Back ─────────────────────────────────────────────────────────────────

  Widget _back() {
    final exercises = widget.workout.exercises;
    final current = exercises[_exIdx.clamp(0, exercises.length - 1)];
    final name = current.exercise.name;
    final multi = exercises.length > 1;

    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 20),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: const Color(0xFFD4CFC7), width: 1.2),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.13),
            blurRadius: 28,
            offset: const Offset(0, 10),
          ),
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.04),
            blurRadius: 6,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(24),
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  const Icon(Icons.movie_filter_rounded,
                      size: 16, color: AppColors.textSecondary),
                  const Gap(6),
                  Text(
                    'Referencia visual',
                    style: GoogleFonts.lexend(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: AppColors.textSecondary,
                      letterSpacing: 0.3,
                    ),
                  ),
                  const Spacer(),
                  if (multi) _carouselIndicator(exercises.length),
                ],
              ),
              const Gap(12),
              Expanded(
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(16),
                  child: FutureBuilder<ExerciseReference?>(
                    future: _refFor(name),
                    builder: (context, snap) {
                      if (snap.connectionState == ConnectionState.waiting) {
                        return const _GifSkeleton();
                      }
                      final ref = snap.data;
                      if (ref == null || (!ref.hasGif && !ref.hasVideo)) {
                        return const _GifUnavailable();
                      }
                      return _ExerciseVisual(
                        key: ValueKey(ref.externalId),
                        ref: ref,
                      );
                    },
                  ),
                ),
              ),
              const Gap(14),
              FutureBuilder<ExerciseReference?>(
                future: _refFor(name),
                builder: (context, snap) {
                  final ref = snap.data;
                  return _meta(
                    title: current.exercise.name,
                    target: ref?.target ?? current.exercise.muscleGroup,
                    equipment: ref?.equipment ?? current.exercise.equipment,
                    source: ref?.source,
                    instruction: ref?.shortInstruction.isNotEmpty == true
                        ? ref!.shortInstruction
                        : (current.exercise.steps.isNotEmpty
                            ? current.exercise.steps.first
                            : ''),
                  );
                },
              ),
              const Gap(14),
              Row(
                children: [
                  if (multi) ...[
                    _circleBtn(
                      icon: Icons.chevron_left_rounded,
                      onTap: () => _changeExercise(-1),
                    ),
                    const Gap(8),
                    _circleBtn(
                      icon: Icons.chevron_right_rounded,
                      onTap: () => _changeExercise(1),
                    ),
                    const Gap(12),
                  ],
                  Expanded(
                    child: GestureDetector(
                      onTap: _flip,
                      child: Container(
                        height: 46,
                        decoration: BoxDecoration(
                          color: AppColors.dark,
                          borderRadius: BorderRadius.circular(13),
                        ),
                        child: Center(
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              const Icon(Icons.flip_to_front_rounded,
                                  size: 18, color: AppColors.primary),
                              const Gap(8),
                              Text(
                                'Volver',
                                style: GoogleFonts.lexend(
                                  fontSize: 14,
                                  fontWeight: FontWeight.w700,
                                  color: AppColors.primary,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _carouselIndicator(int total) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLow,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.border),
      ),
      child: Text(
        '${_exIdx + 1}/$total',
        style: GoogleFonts.lexend(
          fontSize: 11,
          fontWeight: FontWeight.w700,
          color: AppColors.textSecondary,
        ),
      ),
    );
  }

  Widget _meta({
    required String title,
    required String? target,
    required String? equipment,
    required String instruction,
    String? source,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          style: GoogleFonts.lexend(
            fontSize: 18,
            fontWeight: FontWeight.w800,
            color: AppColors.textPrimary,
          ),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        const Gap(8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            if (target != null && target.isNotEmpty)
              _pill(Icons.my_location_rounded, 'Músculo: $target'),
            if (equipment != null && equipment.isNotEmpty)
              _pill(Icons.fitness_center_rounded, 'Equipo: $equipment'),
          ],
        ),
        if (instruction.isNotEmpty) ...[
          const Gap(10),
          Text(
            instruction,
            style: GoogleFonts.inter(
              fontSize: 12.5,
              height: 1.35,
              color: AppColors.textSecondary,
            ),
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
          ),
        ],
        if (source != null && source.isNotEmpty) ...[
          const Gap(8),
          Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.verified_outlined,
                  size: 12, color: AppColors.textDisabled),
              const Gap(5),
              Flexible(
                child: Text(
                  'Referencia visual · Fuente: $source',
                  style: GoogleFonts.inter(
                    fontSize: 10.5,
                    color: AppColors.textDisabled,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
        ],
      ],
    );
  }

  Widget _pill(IconData icon, String label) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLow,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 13, color: AppColors.textSecondary),
          const Gap(5),
          Text(
            label,
            style: GoogleFonts.inter(
                fontSize: 12, color: AppColors.textSecondary),
          ),
        ],
      ),
    );
  }

  Widget _circleBtn({required IconData icon, required VoidCallback onTap}) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: 46,
        height: 46,
        decoration: BoxDecoration(
          color: AppColors.surfaceContainerLow,
          borderRadius: BorderRadius.circular(13),
          border: Border.all(color: AppColors.border),
        ),
        child: Icon(icon, size: 22, color: AppColors.textPrimary),
      ),
    );
  }
}

// ── GIF helpers ──────────────────────────────────────────────────────────────

/// Visual del ejercicio.
///
/// Prioridad: **MP4 optimizado** (FitGif, 1.3x H.264 → más rápido y fluido)
/// vía `video_player` en loop y sin audio. Si no hay MP4 (o falla), cae al
/// **GIF** (fallback) con las optimizaciones de cache/preload existentes.
/// Contenedor de tamaño fijo + `BoxFit.contain`; no rediseña el flip 3D.
class _ExerciseVisual extends StatefulWidget {
  final ExerciseReference ref;
  const _ExerciseVisual({super.key, required this.ref});

  @override
  State<_ExerciseVisual> createState() => _ExerciseVisualState();
}

class _ExerciseVisualState extends State<_ExerciseVisual> {
  Timer? _timer;
  int _frame = 0;

  // Providers ESTABLES (creados una sola vez). Misma instancia/clave de
  // ImageCache que usa el precache → al volver a la card el GIF está ya
  // decodificado y NO recarga desde cero ni reinicia la animación.
  late final List<ImageProvider> _providers;

  // Video: solo se crea el controller para la card visible (este widget solo
  // se monta cuando el reverso está activo) y se libera en dispose → sin fugas.
  VideoPlayerController? _vc;
  bool _videoReady = false;
  bool _videoFailed = false;

  bool get _multiFrame => widget.ref.hasFrames;
  bool get _useVideo => widget.ref.hasVideo && !_videoFailed;

  @override
  void initState() {
    super.initState();
    final r = widget.ref;
    final urls = _multiFrame
        ? [r.gifUrl!, r.thumbnailUrl!]
        : (r.hasGif ? [r.gifUrl!] : <String>[]);
    _providers = urls.map<ImageProvider>((u) => NetworkImage(u)).toList();

    if (r.hasVideo) {
      _initVideo(r.videoUrl!);
    } else if (_multiFrame) {
      _timer = Timer.periodic(const Duration(milliseconds: 900), (_) {
        if (mounted) setState(() => _frame = _frame == 0 ? 1 : 0);
      });
    }
  }

  Future<void> _initVideo(String url) async {
    final c = VideoPlayerController.networkUrl(Uri.parse(url));
    _vc = c;
    try {
      await c.initialize();
      await c.setLooping(true);
      await c.setVolume(0); // sin audio
      if (!mounted) {
        await c.dispose();
        return;
      }
      await c.play();
      setState(() => _videoReady = true);
    } catch (_) {
      // MP4 no disponible → fallback al GIF sin romper la UI.
      await c.dispose();
      _vc = null;
      if (mounted) setState(() => _videoFailed = true);
    }
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    for (final p in _providers) {
      precacheImage(p, context); // idempotente: si ya está, no hace nada
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _vc?.dispose();
    super.dispose();
  }

  Widget _img(ImageProvider provider) => Image(
        image: provider,
        fit: BoxFit.contain,
        // gaplessPlayback: conserva el frame anterior mientras decodifica el
        // siguiente → sin parpadeo/flash blanco entre aperturas del flip.
        gaplessPlayback: true,
        filterQuality: FilterQuality.medium,
        frameBuilder: (context, child, frame, wasSync) {
          if (wasSync || frame != null) return child; // ya cacheado → directo
          return const _GifSkeleton();
        },
        errorBuilder: (context, _, _) => const _GifUnavailable(),
      );

  Widget _buildInner() {
    // 1) MP4 optimizado (más rápido/fluido).
    if (_useVideo) {
      if (_videoReady && _vc != null) {
        return Center(
          child: AspectRatio(
            aspectRatio: _vc!.value.aspectRatio == 0
                ? 1
                : _vc!.value.aspectRatio,
            child: VideoPlayer(_vc!),
          ),
        );
      }
      return const _GifSkeleton(); // cargando el primer frame del video
    }
    // 2) Fallback GIF (sin video o falló).
    if (_providers.isEmpty) return const _GifUnavailable();
    if (!_multiFrame) return _img(_providers.first);
    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 650),
      child: KeyedSubtree(
        key: ValueKey(_frame),
        child: _img(_providers[_frame % _providers.length]),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    // RepaintBoundary: aísla el repintado del media de la sombra/blur de la
    // card y del flip → no fuerza a repintar la tarjeta y se ve más fluido.
    return RepaintBoundary(
      child: Container(
        color: Colors.white,
        width: double.infinity,
        height: double.infinity,
        child: _buildInner(),
      ),
    );
  }
}

class _GifSkeleton extends StatelessWidget {
  const _GifSkeleton();

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor: AppColors.surfaceContainerLow,
      highlightColor: Colors.white,
      child: Container(
        color: AppColors.surfaceContainerLow,
        width: double.infinity,
        height: double.infinity,
        child: const Center(
          child: Icon(Icons.image_rounded, size: 40, color: Colors.white),
        ),
      ),
    );
  }
}

class _GifUnavailable extends StatelessWidget {
  const _GifUnavailable();

  @override
  Widget build(BuildContext context) {
    return Container(
      color: AppColors.surfaceContainerLow,
      width: double.infinity,
      height: double.infinity,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          const Icon(Icons.movie_creation_outlined,
              size: 38, color: AppColors.textDisabled),
          const Gap(10),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24),
            child: Text(
              'Referencia visual no disponible temporalmente',
              textAlign: TextAlign.center,
              maxLines: 2,
              style: GoogleFonts.inter(
                fontSize: 12.5,
                color: AppColors.textSecondary,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
