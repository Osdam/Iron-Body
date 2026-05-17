import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/workout_model.dart';
import '../../../shared/widgets/app_lottie_icon.dart';
import '../services/exercise_reference_service.dart';
import '../widgets/routine_flip_card.dart';
import 'active_workout_screen.dart';
import 'exercise_library_screen.dart';

double _lerpW(double a, double b, double t) => a + (b - a) * t;

const _kLevelColors = <String, (Color, Color)>{
  'Principiante': (AppColors.surfaceContainerLow, AppColors.textSecondary),
  'Intermedio': (Color(0xFFFFF3CC), AppColors.dark),
  'Avanzado': (AppColors.dark, AppColors.primary),
};

// ── WorkoutsScreen ────────────────────────────────────────────────────────────

class WorkoutsScreen extends StatefulWidget {
  const WorkoutsScreen({super.key});

  @override
  State<WorkoutsScreen> createState() => _WorkoutsScreenState();
}

class _WorkoutsScreenState extends State<WorkoutsScreen> {
  int _tab = 0;

  @override
  void initState() {
    super.initState();
    // Cache de imágenes más grande: todos los GIFs de las rutinas caben
    // decodificados en memoria → al reabrir/voltear cards NO recargan desde
    // cero ni reinician la animación. (Solo se sube, nunca se baja.)
    final imgCache = PaintingBinding.instance.imageCache;
    if (imgCache.maximumSizeBytes < 256 << 20) {
      imgCache.maximumSizeBytes = 256 << 20; // 256 MB
    }
    if (imgCache.maximumSize < 200) {
      imgCache.maximumSize = 200;
    }
    // Precalienta las referencias visuales de TODOS los ejercicios de las
    // rutinas al abrir Entrenar: el backend responde desde su caché (sin
    // llamar a FitGif), así el flip muestra el GIF al instante.
    final names = <String>[
      for (final w in mockWorkouts)
        for (final e in w.exercises) e.exercise.name,
    ];
    ExerciseReferenceService.instance.prewarm(names);
  }

  void _play(WorkoutModel w) => Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => ActiveWorkoutScreen(workout: w)),
      );

  @override
  Widget build(BuildContext context) {
    final all = mockWorkouts;
    final assigned = all.where((w) => w.isAssigned).toList();
    final more = all.where((w) => !w.isAssigned).toList();

    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: AppBar(
        backgroundColor: AppColors.surface0,
        elevation: 0,
        scrolledUnderElevation: 0,
        title: Text(
          'Entrenar',
          style: GoogleFonts.lexend(
            fontSize: 20,
            fontWeight: FontWeight.w700,
            color: AppColors.textPrimary,
          ),
        ),
        actions: [
          TextButton.icon(
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const ExerciseLibraryScreen()),
            ),
            icon: AppLottieIcon(path: AppAssets.lottieEvaluacion, size: 22),
            label: Text(
              'Biblioteca',
              style: GoogleFonts.inter(fontSize: 13, color: AppColors.textSecondary),
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 12),
            child: _TabSelector(
              selected: _tab,
              onSelect: (i) => setState(() => _tab = i),
            ),
          ),
          Expanded(
            child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 280),
              switchInCurve: Curves.easeOut,
              switchOutCurve: Curves.easeIn,
              child: _tab == 0
                  ? _WorkoutDeck(
                      key: const ValueKey('assigned'),
                      workouts: assigned,
                      onPlay: _play,
                    )
                  : _WorkoutDeck(
                      key: const ValueKey('more'),
                      workouts: more,
                      onPlay: _play,
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Tab Selector ──────────────────────────────────────────────────────────────

class _TabSelector extends StatelessWidget {
  final int selected;
  final ValueChanged<int> onSelect;
  const _TabSelector({required this.selected, required this.onSelect});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLow,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.border),
      ),
      padding: const EdgeInsets.all(4),
      child: Row(
        children: [
          _TabItem(label: 'Mis rutinas', selected: selected == 0, onTap: () => onSelect(0)),
          _TabItem(label: 'Más rutinas', selected: selected == 1, onTap: () => onSelect(1)),
        ],
      ),
    );
  }
}

class _TabItem extends StatelessWidget {
  final String label;
  final bool selected;
  final VoidCallback onTap;
  const _TabItem({required this.label, required this.selected, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: GestureDetector(
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 200),
          padding: const EdgeInsets.symmetric(vertical: 10),
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: selected ? AppColors.dark : Colors.transparent,
            borderRadius: BorderRadius.circular(10),
          ),
          child: Text(
            label,
            textAlign: TextAlign.center,
            style: GoogleFonts.lexend(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: selected ? AppColors.primary : AppColors.textSecondary,
            ),
          ),
        ),
      ),
    );
  }
}

// ── Workout Deck ──────────────────────────────────────────────────────────────

class _WorkoutDeck extends StatefulWidget {
  final List<WorkoutModel> workouts;
  final ValueChanged<WorkoutModel> onPlay;

  const _WorkoutDeck({
    super.key,
    required this.workouts,
    required this.onPlay,
  });

  @override
  State<_WorkoutDeck> createState() => _WorkoutDeckState();
}

class _WorkoutDeckState extends State<_WorkoutDeck> with TickerProviderStateMixin {
  late final AnimationController _commitCtrl;
  late final AnimationController _snapCtrl;

  int _idx = 0;
  double _drag = 0;
  double _dragSnapshot = 0;
  bool _committing = false;
  bool _snapping = false;
  double _snapStart = 0;
  int _dir = 0;

  static const _thresh = 72.0;
  // Front card at ty=88; ghost1 at ty=36; ghost2 at ty=0 — peeks 88px above front.
  static const _kTY = [88.0, 36.0, 0.0];
  static const _kSX = [1.00, 0.93, 0.86];
  static const _kOP = [1.00, 0.72, 0.44];

  @override
  void initState() {
    super.initState();
    _commitCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 460),
    )
      ..addListener(() => setState(() {}))
      ..addStatusListener((s) {
        if (s == AnimationStatus.completed) {
          setState(() {
            _idx = (_idx + _dir).clamp(0, widget.workouts.length - 1);
            _drag = 0;
            _dragSnapshot = 0;
            _committing = false;
            _dir = 0;
          });
          _commitCtrl.reset();
        }
      });

    _snapCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 320),
    )
      ..addListener(() {
        if (!_snapping) return;
        setState(() {
          _drag = _lerpW(_snapStart, 0, Curves.easeOutQuart.transform(_snapCtrl.value));
        });
      })
      ..addStatusListener((s) {
        if (s == AnimationStatus.completed && _snapping) {
          setState(() {
            _drag = 0;
            _snapping = false;
          });
          _snapCtrl.reset();
        }
      });
  }

  @override
  void dispose() {
    _commitCtrl.dispose();
    _snapCtrl.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(_WorkoutDeck old) {
    super.didUpdateWidget(old);
    if (old.workouts != widget.workouts) {
      _idx = 0;
      _drag = 0;
      _committing = false;
      _snapping = false;
      _dir = 0;
      _commitCtrl.reset();
      _snapCtrl.reset();
    }
  }

  void _onDragUpdate(DragUpdateDetails d) {
    if (_committing || _snapping) return;
    setState(() => _drag += d.delta.dy * 0.88);
  }

  void _onDragEnd(DragEndDetails d) {
    if (_committing || _snapping) return;
    if (_drag > _thresh && _idx < widget.workouts.length - 1) {
      _commit(1);
    } else if (_drag < -_thresh && _idx > 0) {
      _commit(-1);
    } else {
      _snapStart = _drag;
      _snapping = true;
      _snapCtrl.forward(from: 0);
    }
  }

  void _commit(int dir) {
    HapticFeedback.lightImpact();
    _dragSnapshot = _drag;
    setState(() {
      _committing = true;
      _dir = dir;
    });
    _commitCtrl.forward(from: 0);
  }

  double get _animP      => Curves.easeInOutCubic.transform(_commitCtrl.value);
  double get _animPExit  => Curves.easeInCubic.transform(_commitCtrl.value);
  double get _animPEnter => Curves.easeOutCubic.transform(_commitCtrl.value);
  double get _dragP {
    final raw = (_drag.abs() / _thresh).clamp(0.0, 1.0);
    return Curves.easeOut.transform(raw);
  }

  @override
  Widget build(BuildContext context) {
    final ws = widget.workouts;
    if (ws.isEmpty) {
      return Center(
        child: Text(
          'Sin rutinas',
          style: GoogleFonts.lexend(fontSize: 15, color: AppColors.textSecondary),
        ),
      );
    }
    return LayoutBuilder(builder: (_, box) {
      final cardH = (box.maxHeight * 0.65).clamp(260.0, 440.0);
      return Stack(
        children: [
          Positioned.fill(
            child: GestureDetector(
              behavior: HitTestBehavior.translucent,
              onVerticalDragUpdate: _onDragUpdate,
              onVerticalDragEnd: _onDragEnd,
              child: Stack(
                clipBehavior: Clip.none,
                alignment: Alignment.topCenter,
                children: _buildLayers(ws, cardH),
              ),
            ),
          ),
          Positioned(
            right: 0,
            top: 0,
            bottom: 0,
            child: Center(
              child: _WorkoutSwipeIndicator(
                canGoUp: _idx > 0,
                canGoDown: _idx < ws.length - 1,
              ),
            ),
          ),
        ],
      );
    });
  }

  List<Widget> _buildLayers(List<WorkoutModel> ws, double cardH) {
    final layers = <({int z, Widget w})>[];
    final goingNext = _dir == 1 || (_dir == 0 && _drag >= 0);

    if (goingNext) {
      final p = _committing ? _animP : _dragP;

      for (int slot = 0; slot < 3; slot++) {
        final wi = _idx + slot;
        if (wi >= ws.length) break;

        double ty, sx, op;
        if (slot == 0) {
          if (_committing) {
            ty = _lerpW(_kTY[0] + _dragSnapshot, cardH + 120, _animPExit);
            sx = _lerpW(_kSX[0], _kSX[1], _animPExit);
            op = (1.0 - _animPExit * 2.5).clamp(0.0, 1.0);
          } else {
            ty = _kTY[0] + _drag;
            sx = _lerpW(_kSX[0], _kSX[1], p);
            op = _lerpW(1.0, 0.0, (p * 1.3).clamp(0, 1));
          }
        } else {
          final ep = _committing ? _animPEnter : p;
          ty = _lerpW(_kTY[slot], _kTY[slot - 1], ep);
          sx = _lerpW(_kSX[slot], _kSX[slot - 1], ep);
          op = _lerpW(_kOP[slot], _kOP[slot - 1], ep);
        }

        layers.add((
          z: 2 - slot,
          w: _card(ws[wi], ty, sx, op, cardH, interactive: slot == 0 && !_committing),
        ));
      }

      if (_committing && _idx + 3 < ws.length) {
        final ty = _lerpW(_kTY[2] - 30, _kTY[2], _animPEnter);
        final sx = _lerpW(_kSX[2] - 0.08, _kSX[2], _animPEnter);
        final op = _lerpW(0.0, _kOP[2], _animPEnter);
        layers.add((z: 0, w: _card(ws[_idx + 3], ty, sx, op, cardH)));
      }
    } else {
      final p = _committing ? _animP : _dragP;

      if (_idx > 0) {
        final ty = _lerpW(cardH * 0.6, _kTY[0], p);
        final sx = _lerpW(_kSX[1], _kSX[0], p);
        final op = _lerpW(0.0, _kOP[0], p);
        layers.add((
          z: 3,
          w: _card(ws[_idx - 1], ty, sx, op, cardH,
              interactive: _committing && _commitCtrl.value > 0.7),
        ));
      }

      for (int slot = 0; slot < 3; slot++) {
        final wi = _idx + slot;
        if (wi >= ws.length) break;

        double ty, sx, op;
        if (slot == 2) {
          ty = _lerpW(_kTY[2], _kTY[2] - 8, p);
          sx = _lerpW(_kSX[2], _kSX[2] - 0.05, p);
          op = _lerpW(_kOP[2], 0.0, p);
        } else {
          ty = _lerpW(_kTY[slot], _kTY[slot + 1], p);
          sx = _lerpW(_kSX[slot], _kSX[slot + 1], p);
          op = _lerpW(_kOP[slot], _kOP[slot + 1], p);
        }

        layers.add((z: 2 - slot, w: _card(ws[wi], ty, sx, op, cardH)));
      }
    }

    layers.sort((a, b) => a.z.compareTo(b.z));
    return layers.map((l) => l.w).toList();
  }

  Widget _card(WorkoutModel w, double ty, double sx, double op, double cardH,
      {bool interactive = false}) {
    return Transform.translate(
      offset: Offset(0, ty),
      child: Transform.scale(
        scaleX: sx,
        scaleY: 1.0,
        alignment: Alignment.topCenter,
        child: Opacity(
          opacity: op.clamp(0.0, 1.0),
          child: IgnorePointer(
            ignoring: !interactive,
            child: SizedBox(
              width: double.infinity,
              height: cardH,
              child: RoutineFlipCard(
                workout: w,
                front: _WorkoutCard(workout: w, onPlay: () => widget.onPlay(w)),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

// ── Workout Card ──────────────────────────────────────────────────────────────

class _WorkoutCard extends StatefulWidget {
  final WorkoutModel workout;
  final VoidCallback onPlay;
  const _WorkoutCard({required this.workout, required this.onPlay});

  @override
  State<_WorkoutCard> createState() => _WorkoutCardState();
}

class _WorkoutCardState extends State<_WorkoutCard> {

  @override
  Widget build(BuildContext context) {
    final w = widget.workout;
    final colors = _kLevelColors[w.level] ??
        (AppColors.surfaceContainerLow, AppColors.textSecondary);

    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 20),
      decoration: BoxDecoration(
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
        child: Stack(
          children: [
            // ── Background image (fills card completely) ──────────────
            Positioned.fill(
              child: Image.asset(
                'assets/images/entrenar3.png',
                fit: BoxFit.cover,
              ),
            ),
            Positioned.fill(
              child: Container(
                color: Colors.white.withValues(alpha: 0.52),
              ),
            ),
            // ── Card content ─────────────────────────────────────────
            Positioned.fill(
              child: Padding(
            padding: const EdgeInsets.all(22),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(
                        color: colors.$1,
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        w.level,
                        style: GoogleFonts.lexend(
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                          color: colors.$2,
                        ),
                      ),
                    ),
                    Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(
                        color: AppColors.surfaceContainerLow,
                        borderRadius: BorderRadius.circular(13),
                        border: Border.all(color: AppColors.border),
                      ),
                      child: Center(
                        child: Lottie.asset(
                          AppAssets.lottieGym,
                          width: 28,
                          height: 28,
                          repeat: true,
                          fit: BoxFit.contain,
                        ),
                      ),
                    ),
                  ],
                ),
                const Spacer(),
                Text(
                  w.name,
                  style: GoogleFonts.lexend(
                    fontSize: 22,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                  ),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
                const Gap(4),
                Text(
                  w.muscleGroup,
                  style: GoogleFonts.inter(fontSize: 13, color: AppColors.textSecondary),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
                const Gap(14),
                Row(
                  children: [
                    _StatChip(icon: Icons.timer_outlined, label: '${w.estimatedMinutes} min'),
                    const Gap(10),
                    _StatChip(
                      icon: Icons.fitness_center_rounded,
                      label: '${w.exerciseCount} ejerc.',
                    ),
                  ],
                ),
                const Spacer(),
                // Play button — icon + text perfectly centered
                GestureDetector(
                  onTap: widget.onPlay,
                  child: Container(
                    width: double.infinity,
                    height: 52,
                    decoration: BoxDecoration(
                      color: AppColors.dark,
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Center(
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.center,
                        children: [
                          const Icon(
                            Icons.play_arrow_rounded,
                            size: 22,
                            color: AppColors.primary,
                          ),
                          const Gap(8),
                          Text(
                            'Iniciar rutina',
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
              ],
            ),
          ),
        ),     // Positioned.fill (content)
        ],
      ),       // Stack
    ),         // ClipRRect
    );
  }
}

class _StatChip extends StatelessWidget {
  final IconData icon;
  final String label;
  const _StatChip({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) {
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
          Text(label, style: GoogleFonts.inter(fontSize: 12, color: AppColors.textSecondary)),
        ],
      ),
    );
  }
}

// ── Swipe Indicator ───────────────────────────────────────────────────────────

class _WorkoutSwipeIndicator extends StatefulWidget {
  final bool canGoUp;
  final bool canGoDown;
  const _WorkoutSwipeIndicator({required this.canGoUp, required this.canGoDown});

  @override
  State<_WorkoutSwipeIndicator> createState() => _WorkoutSwipeIndicatorState();
}

class _WorkoutSwipeIndicatorState extends State<_WorkoutSwipeIndicator>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1800),
    )..repeat(reverse: true);
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.canGoUp && !widget.canGoDown) return const SizedBox.shrink();
    return AnimatedBuilder(
      animation: _ctrl,
      builder: (context, _) {
        final t = Curves.easeInOut.transform(_ctrl.value);
        final opacity = 0.32 + t * 0.52;
        final shift = -2.5 + t * 5.0;
        return Opacity(
          opacity: opacity,
          child: Transform.translate(
            offset: Offset(0, shift),
            child: Container(
              margin: const EdgeInsets.only(right: 8),
              padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 10),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: AppColors.border),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.06),
                    blurRadius: 8,
                    offset: const Offset(0, 2),
                  ),
                ],
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (widget.canGoUp) ...[
                    const Icon(Icons.keyboard_arrow_up_rounded,
                        size: 18, color: AppColors.textSecondary),
                    const Gap(2),
                  ],
                  _dot(),
                  const Gap(2),
                  _dot(),
                  const Gap(2),
                  _dot(),
                  if (widget.canGoDown) ...[
                    const Gap(2),
                    const Icon(Icons.keyboard_arrow_down_rounded,
                        size: 18, color: AppColors.textSecondary),
                  ],
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _dot() => Container(
        width: 3,
        height: 3,
        decoration: const BoxDecoration(
          color: AppColors.textDisabled,
          shape: BoxShape.circle,
        ),
      );
}
