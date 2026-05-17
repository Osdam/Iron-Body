import 'dart:async';
import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:image_picker/image_picker.dart';
import 'package:permission_handler/permission_handler.dart';

import '../../../core/theme/app_colors.dart';
import '../models/document_quality.dart';
import '../services/document_frame_processor.dart';
import '../services/document_image_processor.dart';
import '../services/document_quality_service.dart';
import '../services/identity_verification_service.dart';

enum DocumentSide { front, back }

extension DocumentSideX on DocumentSide {
  String get titleEs =>
      this == DocumentSide.front ? 'Frente del documento' : 'Reverso del documento';
  String get shortEs => this == DocumentSide.front ? 'frente' : 'reverso';
  String get validatedEs =>
      this == DocumentSide.front ? 'Frente validado' : 'Reverso validado';
}

/// Captura guiada de una cara del documento, estilo app bancaria:
///  - cámara con marco guía e instrucciones;
///  - detección en vivo (texto + luz + nitidez + encuadre) y CAPTURA
///    AUTOMÁTICA cuando el documento está bien ubicado y estable ~1 s;
///  - validación de calidad de la foto resultante (no decorativa);
///  - solo devuelve la ruta cuando la imagen es aceptable.
///
/// Devuelve la ruta del archivo (temporal del SO) vía [Navigator.pop], o null.
class DocumentCaptureScreen extends StatefulWidget {
  final DocumentSide side;
  const DocumentCaptureScreen({super.key, required this.side});

  @override
  State<DocumentCaptureScreen> createState() => _DocumentCaptureScreenState();
}

enum _Stage {
  initializing,
  permissionDenied,
  permissionPermanent,
  cameraError,
  scanning,
  capturing,
  analyzing,
  reviewOk,
  reviewBad,
}

class _DocumentCaptureScreenState extends State<DocumentCaptureScreen>
    with WidgetsBindingObserver {
  CameraController? _controller;
  CameraDescription? _camera;
  // Mismo motor de detección para frente y reverso (misma validación base).
  final DocumentFrameProcessor _processor = DocumentFrameProcessor();
  bool _streaming = false;

  _Stage _stage = _Stage.initializing;
  String? _errorDetail;

  DocFrameResult? _frame;
  DateTime? _readySince;
  double _holdProgress = 0;
  Timer? _holdTicker;
  bool _hapticFired = false;
  int _notReadyStreak = 0; // tolera glitches de 1 frame sin reiniciar la cuenta
  static const Duration _holdDuration = Duration(milliseconds: 600);

  String? _shotPath;
  DocumentQualityResult? _quality;
  bool _notDocument = false; // la foto no parece un documento de identidad
  final _picker = ImagePicker();

  static const _instructions = <(IconData, String)>[
    (Icons.crop_free_rounded, 'Ubica el documento dentro del recuadro'),
    (Icons.wb_sunny_outlined, 'Hazlo en un lugar bien iluminado'),
    (Icons.flare_outlined, 'Evita reflejos y brillos sobre el documento'),
    (Icons.straighten_rounded, 'Mantén el documento recto y completo'),
    (Icons.touch_app_outlined, 'No necesitas tocar nada: la foto se toma sola'),
  ];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    SystemChrome.setPreferredOrientations(const [DeviceOrientation.portraitUp]);
    _bootstrap();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _holdTicker?.cancel();
    _disposeCamera();
    _processor.close();
    _deleteShot();
    SystemChrome.setPreferredOrientations(DeviceOrientation.values);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final c = _controller;
    if (c == null) return;
    if (state == AppLifecycleState.inactive ||
        state == AppLifecycleState.paused) {
      _disposeCamera();
    } else if (state == AppLifecycleState.resumed && _stage == _Stage.scanning) {
      _initCamera();
    }
  }

  // ── Permisos / cámara ─────────────────────────────────────────────────────
  Future<void> _bootstrap() async {
    final status = await Permission.camera.status;
    if (status.isGranted) {
      await _initCamera();
      return;
    }
    if (status.isPermanentlyDenied) {
      _setStage(_Stage.permissionPermanent);
      return;
    }
    final result = await Permission.camera.request();
    if (!mounted) return;
    if (result.isGranted) {
      await _initCamera();
    } else if (result.isPermanentlyDenied) {
      _setStage(_Stage.permissionPermanent);
    } else {
      _setStage(_Stage.permissionDenied);
    }
  }

  Future<void> _initCamera() async {
    setState(() {
      _stage = _Stage.initializing;
      _errorDetail = null;
      _resetHold();
      _frame = null;
    });
    try {
      final cameras = await availableCameras();
      if (cameras.isEmpty) {
        throw CameraException('no_camera', 'No hay cámaras disponibles.');
      }
      _camera = cameras.firstWhere(
        (c) => c.lensDirection == CameraLensDirection.back,
        orElse: () => cameras.first,
      );
      final controller = CameraController(
        _camera!,
        ResolutionPreset.high,
        enableAudio: false,
        imageFormatGroup: Platform.isAndroid
            ? ImageFormatGroup.nv21
            : ImageFormatGroup.bgra8888,
      );
      await controller.initialize();
      try {
        await controller.setFlashMode(FlashMode.off);
      } catch (_) {/* no soportado en algunos equipos */}
      if (!mounted) {
        await controller.dispose();
        return;
      }
      _controller = controller;
      setState(() => _stage = _Stage.scanning);
      await _startStream();
    } on CameraException catch (e) {
      if (!mounted) return;
      setState(() {
        _stage = _Stage.cameraError;
        _errorDetail = _mapCameraError(e);
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _stage = _Stage.cameraError;
        _errorDetail = 'No se pudo iniciar la cámara. Inténtalo de nuevo.';
      });
    }
  }

  String _mapCameraError(CameraException e) {
    switch (e.code) {
      case 'CameraAccessDenied':
      case 'CameraAccessDeniedWithoutPrompt':
      case 'CameraAccessRestricted':
        return 'Permiso de cámara denegado. Habilítalo en Ajustes para continuar.';
      case 'no_camera':
        return e.description ?? 'No hay cámaras disponibles.';
      default:
        return 'No se pudo iniciar la cámara. Inténtalo de nuevo.';
    }
  }

  Future<void> _startStream() async {
    final c = _controller;
    if (c == null || !c.value.isInitialized || _streaming) return;
    _streaming = true;
    await c.startImageStream(_onFrame);
  }

  Future<void> _stopStream() async {
    final c = _controller;
    if (c == null || !_streaming) return;
    try {
      await c.stopImageStream();
    } catch (_) {/* ignore */}
    _streaming = false;
  }

  Future<void> _disposeCamera() async {
    await _stopStream();
    final c = _controller;
    _controller = null;
    if (c != null) {
      try {
        await c.dispose();
      } catch (_) {/* ignore */}
    }
  }

  void _onFrame(CameraImage image) async {
    if (_stage != _Stage.scanning) return;
    final result = await _processor.process(
      image,
      _camera!,
      DeviceOrientation.portraitUp,
    );
    if (result == null || !mounted || _stage != _Stage.scanning) return;
    setState(() => _frame = result);

    if (result.status.isReady) {
      _notReadyStreak = 0;
      _readySince ??= DateTime.now();
      if (!_hapticFired) {
        _hapticFired = true;
        unawaited(HapticFeedback.selectionClick());
      }
      _holdTicker ??= Timer.periodic(const Duration(milliseconds: 60), (_) {
        if (!mounted || _readySince == null) return;
        final elapsed = DateTime.now().difference(_readySince!);
        final ratio = (elapsed.inMilliseconds / _holdDuration.inMilliseconds)
            .clamp(0.0, 1.0);
        if (ratio != _holdProgress) setState(() => _holdProgress = ratio);
        if (elapsed >= _holdDuration && _stage == _Stage.scanning) {
          _holdTicker?.cancel();
          _holdTicker = null;
          unawaited(_autoCapture());
        }
      });
    } else {
      // Tolera un frame "malo" suelto: solo se reinicia la cuenta si hay dos
      // frames no-listos seguidos (evita que un pequeño temblor la cancele).
      _notReadyStreak++;
      if (_notReadyStreak >= 2) _resetHold();
    }
  }

  void _resetHold() {
    _readySince = null;
    _holdProgress = 0;
    _hapticFired = false;
    _notReadyStreak = 0;
    _holdTicker?.cancel();
    _holdTicker = null;
  }

  // ── Captura automática / selección / análisis ─────────────────────────────
  Future<void> _autoCapture() async {
    if (_stage != _Stage.scanning) return;
    setState(() => _stage = _Stage.capturing);
    unawaited(HapticFeedback.mediumImpact());
    await _stopStream();
    try {
      final shot = await _controller!.takePicture();
      await _disposeCamera();
      if (!mounted) return;
      await _analyzeShot(shot.path);
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _stage = _Stage.scanning;
        _resetHold();
      });
      await _startStream();
    }
  }

  Future<void> _pickFromGallery() async {
    if (_stage != _Stage.scanning) return;
    try {
      final file = await _picker.pickImage(
        source: ImageSource.gallery,
        imageQuality: 92,
        maxWidth: 2200,
      );
      if (file == null) return;
      await _disposeCamera();
      if (!mounted) return;
      await _analyzeShot(file.path);
    } catch (_) {
      if (mounted) _toast('No se pudo abrir la galería.');
    }
  }

  Future<void> _analyzeShot(String rawPath) async {
    setState(() {
      _shotPath = rawPath;
      _stage = _Stage.analyzing;
      _quality = null;
      _notDocument = false;
    });
    // 0) Postproceso: recorta el documento (deja fuera mesa/teclado/sombras del
    //    entorno) y mejora la imagen para OCR (normaliza brillo/contraste).
    final path = await DocumentImageProcessor.instance.processCapture(rawPath);
    if (path != rawPath) {
      // El raw ya no se necesita.
      try {
        final f = File(rawPath);
        if (await f.exists()) await f.delete();
      } catch (_) {/* ignore */}
    }
    if (!mounted) {
      // Limpieza si el usuario salió mientras procesábamos.
      if (path != rawPath) {
        try {
          final f = File(path);
          if (await f.exists()) await f.delete();
        } catch (_) {/* ignore */}
      }
      return;
    }
    setState(() => _shotPath = path);
    // 1) ¿Realmente parece un documento? (mismo criterio que la guía en vivo;
    //    cubre sobre todo el caso de "subir desde galería").
    final looksDoc =
        await IdentityVerificationService.instance.looksLikeDocument(path);
    if (!mounted) return;
    if (!looksDoc) {
      setState(() {
        _notDocument = true;
        _stage = _Stage.reviewBad;
      });
      return;
    }
    // 2) Validación de calidad de la imagen (ya recortada y mejorada).
    final result = await DocumentQualityService.instance.analyze(path);
    if (!mounted) return;
    setState(() {
      _quality = result;
      _stage = result.ok ? _Stage.reviewOk : _Stage.reviewBad;
    });
  }

  Future<void> _retake() async {
    await _deleteShot();
    setState(() {
      _shotPath = null;
      _quality = null;
      _notDocument = false;
      _frame = null;
      _stage = _Stage.scanning;
    });
    await _initCamera();
  }

  void _accept() {
    final path = _shotPath;
    if (path == null) return;
    _shotPath = null; // ya lo "entregamos": dispose no debe borrarlo
    Navigator.of(context).pop(path);
  }

  Future<void> _deleteShot() async {
    final p = _shotPath;
    if (p == null) return;
    final f = File(p);
    if (await f.exists()) {
      try {
        await f.delete();
      } catch (_) {/* ignore */}
    }
  }

  void _setStage(_Stage s) {
    if (mounted) setState(() => _stage = s);
  }

  void _toast(String msg) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(
        content: Text(msg),
        behavior: SnackBarBehavior.floating,
        backgroundColor: AppColors.dark,
      ));
  }

  // ── Build ─────────────────────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.dark,
      body: SafeArea(
        child: Stack(
          fit: StackFit.expand,
          children: [
            _buildBody(),
            Positioned(
              top: 2,
              left: 2,
              child: IconButton(
                onPressed: () => Navigator.of(context).pop(),
                icon: const Icon(Icons.close_rounded,
                    color: Colors.white, size: 26),
              ),
            ),
            Positioned(top: 14, right: 14, child: const _SecureChip()),
          ],
        ),
      ),
    );
  }

  Widget _buildBody() {
    switch (_stage) {
      case _Stage.initializing:
        return const _CenterSpinner(label: 'Preparando cámara…');
      case _Stage.permissionDenied:
        return _PermissionState(permanent: false, onRetry: _bootstrap);
      case _Stage.permissionPermanent:
        return _PermissionState(
          permanent: true,
          onRetry: () async {
            await openAppSettings();
            if (!mounted) return;
            await _bootstrap();
          },
        );
      case _Stage.cameraError:
        return _ErrorBlock(
          icon: Icons.error_outline_rounded,
          title: 'No pudimos iniciar la cámara',
          message: _errorDetail ?? '',
          actionLabel: 'Reintentar',
          onAction: _initCamera,
          secondaryLabel: 'Subir desde galería',
          onSecondary: () async {
            final file = await _picker.pickImage(
                source: ImageSource.gallery, imageQuality: 92, maxWidth: 2200);
            if (file != null && mounted) await _analyzeShot(file.path);
          },
        );
      case _Stage.scanning:
      case _Stage.capturing:
        return _buildScanning();
      case _Stage.analyzing:
        return _buildAnalyzing();
      case _Stage.reviewOk:
      case _Stage.reviewBad:
        return _buildReview();
    }
  }

  // ── Scanning (cámara + overlay + auto-detección) ──────────────────────────
  Widget _buildScanning() {
    final c = _controller;
    if (c == null || !c.value.isInitialized) {
      return const _CenterSpinner(label: 'Preparando cámara…');
    }
    final preview = c.value.previewSize ?? const Size(1280, 720);
    final capturing = _stage == _Stage.capturing;
    final ready = capturing || (_frame?.status.isReady ?? false);
    final frameState = capturing
        ? _FrameState.ready
        : ready
            ? _FrameState.ready
            : (_frame == null || _frame!.status == DocFrameStatus.noDocument)
                ? _FrameState.searching
                : _FrameState.detecting;

    final detectedLabel =
        widget.side == DocumentSide.back ? 'Reverso detectado' : 'Frente detectado';
    final msg = capturing
        ? 'Capturando automáticamente…'
        : !ready
            ? (_frame?.status.message ?? 'Ubica el documento dentro del marco')
            : _holdProgress >= 0.95
                ? 'Capturando automáticamente…'
                : _holdProgress >= 0.25
                    ? 'Mantén quieto un momento'
                    : detectedLabel;

    return Stack(
      fit: StackFit.expand,
      children: [
        FittedBox(
          fit: BoxFit.cover,
          child: SizedBox(
            width: preview.height,
            height: preview.width,
            child: CameraPreview(c),
          ),
        ),
        Positioned.fill(child: _DocFrameOverlay(state: frameState)),
        // Encabezado.
        Positioned(
          left: 20,
          right: 20,
          top: 48,
          child: Column(
            children: [
              _Pill(
                text: msg,
                icon: ready ? Icons.check_circle_rounded : Icons.badge_outlined,
                accent: _accentFor(frameState),
              ),
              const Gap(6),
              Text(
                widget.side.titleEs,
                style: GoogleFonts.inter(
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: Colors.white.withValues(alpha: 0.9),
                ),
              ),
            ],
          ),
        ),
        // Instrucciones + barra de estabilidad + galería.
        Positioned(
          left: 0,
          right: 0,
          bottom: 16,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 22),
                child: _InstructionsCard(items: _instructions),
              ),
              const Gap(12),
              _StabilityBar(progress: capturing ? 1 : _holdProgress, ready: ready),
              const Gap(8),
              Text(
                ready
                    ? 'Mantén el documento quieto…'
                    : 'La foto se toma sola cuando todo esté correcto',
                style: GoogleFonts.inter(
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                  color: Colors.white.withValues(alpha: 0.85),
                ),
              ),
              const Gap(8),
              TextButton.icon(
                onPressed: capturing ? null : _pickFromGallery,
                icon: const Icon(Icons.photo_library_outlined,
                    size: 16, color: Colors.white),
                label: Text('Subir desde galería',
                    style: GoogleFonts.inter(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: Colors.white)),
              ),
            ],
          ),
        ),
        if (capturing)
          const Center(
            child: SizedBox(
              width: 26,
              height: 26,
              child: CircularProgressIndicator(
                  strokeWidth: 2.6,
                  valueColor: AlwaysStoppedAnimation<Color>(AppColors.primary)),
            ),
          ),
      ],
    );
  }

  Color _accentFor(_FrameState s) => switch (s) {
        _FrameState.searching => Colors.white,
        _FrameState.detecting => AppColors.primary,
        _FrameState.ready => const Color(0xFF22C55E),
      };

  Widget _buildAnalyzing() {
    return Stack(
      fit: StackFit.expand,
      children: [
        if (_shotPath != null) Image.file(File(_shotPath!), fit: BoxFit.cover),
        Container(color: AppColors.dark.withValues(alpha: 0.78)),
        Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const SizedBox(
                width: 30,
                height: 30,
                child: CircularProgressIndicator(
                    strokeWidth: 2.6,
                    valueColor:
                        AlwaysStoppedAnimation<Color>(AppColors.primary)),
              ),
              const Gap(16),
              Text('Analizando imagen…',
                  style: GoogleFonts.lexend(
                      fontSize: 15,
                      fontWeight: FontWeight.w700,
                      color: Colors.white)),
              const Gap(4),
              Text('Verificando nitidez, iluminación y encuadre',
                  style: GoogleFonts.inter(
                      fontSize: 12,
                      color: Colors.white.withValues(alpha: 0.7))),
            ],
          ),
        ),
      ],
    );
  }

  // ── Review (foto + resultado de calidad) ──────────────────────────────────
  Widget _buildReview() {
    final q = _quality;
    final ok = _stage == _Stage.reviewOk;
    final accent = ok ? const Color(0xFF22C55E) : AppColors.error;
    final pillText = ok
        ? widget.side.validatedEs
        : _notDocument
            ? 'No parece un documento'
            : 'Imagen no válida';

    return Column(
      children: [
        const Gap(48),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 20),
          child: _Pill(
            text: pillText,
            icon: ok ? Icons.check_circle_rounded : Icons.error_rounded,
            accent: accent,
          ),
        ),
        const Gap(14),
        Expanded(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(18),
              child: Stack(
                fit: StackFit.expand,
                children: [
                  if (_shotPath != null)
                    Image.file(File(_shotPath!), fit: BoxFit.contain),
                  Container(
                    decoration: BoxDecoration(
                      border:
                          Border.all(color: accent.withValues(alpha: 0.8), width: 2),
                      borderRadius: BorderRadius.circular(18),
                      color: ok
                          ? Colors.transparent
                          : AppColors.dark.withValues(alpha: 0.18),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
        const Gap(12),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 20),
          child: _ReviewMessages(result: q, ok: ok, notDocument: _notDocument),
        ),
        const Gap(12),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
          child: Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: _retake,
                  icon: const Icon(Icons.replay_rounded, size: 18),
                  label: const Text('Reintentar'),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.white,
                    side: BorderSide(color: Colors.white.withValues(alpha: 0.5)),
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(14)),
                  ),
                ),
              ),
              if (ok) ...[
                const Gap(12),
                Expanded(
                  child: FilledButton.icon(
                    onPressed: _accept,
                    icon: const Icon(Icons.check_rounded, size: 18),
                    label: const Text('Usar esta foto'),
                    style: FilledButton.styleFrom(
                      backgroundColor: AppColors.primary,
                      foregroundColor: AppColors.dark,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(14)),
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }
}

// ════════════════════════════════════════════════════════════════════════════
// Sub-widgets
// ════════════════════════════════════════════════════════════════════════════

enum _FrameState { searching, detecting, ready }

class _CenterSpinner extends StatelessWidget {
  final String? label;
  const _CenterSpinner({this.label});
  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const CircularProgressIndicator(
              valueColor: AlwaysStoppedAnimation<Color>(AppColors.primary)),
          if (label != null) ...[
            const Gap(14),
            Text(label!,
                style: GoogleFonts.inter(
                    fontSize: 13, color: Colors.white.withValues(alpha: 0.8))),
          ],
        ],
      ),
    );
  }
}

class _SecureChip extends StatelessWidget {
  const _SecureChip();
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(99),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.lock_rounded, color: AppColors.primary, size: 12),
          const Gap(4),
          Text('Captura segura',
              style: GoogleFonts.inter(
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                  color: Colors.white)),
        ],
      ),
    );
  }
}

class _Pill extends StatelessWidget {
  final String text;
  final IconData icon;
  final Color accent;
  const _Pill(
      {required this.text, required this.icon, this.accent = AppColors.primary});

  @override
  Widget build(BuildContext context) {
    return AnimatedContainer(
      duration: const Duration(milliseconds: 200),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
      decoration: BoxDecoration(
        color: AppColors.dark.withValues(alpha: 0.74),
        borderRadius: BorderRadius.circular(99),
        border: Border.all(color: accent.withValues(alpha: 0.75), width: 1.2),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 15, color: accent),
          const Gap(8),
          Flexible(
            child: Text(text,
                textAlign: TextAlign.center,
                style: GoogleFonts.lexend(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: Colors.white)),
          ),
        ],
      ),
    );
  }
}

class _InstructionsCard extends StatelessWidget {
  final List<(IconData, String)> items;
  const _InstructionsCard({required this.items});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: AppColors.dark.withValues(alpha: 0.66),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white.withValues(alpha: 0.12)),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var i = 0; i < items.length; i++) ...[
            Row(
              children: [
                Icon(items[i].$1, size: 15, color: AppColors.primary),
                const Gap(10),
                Expanded(
                  child: Text(items[i].$2,
                      style: GoogleFonts.inter(
                          fontSize: 11.5,
                          fontWeight: FontWeight.w500,
                          color: Colors.white.withValues(alpha: 0.92))),
                ),
              ],
            ),
            if (i < items.length - 1) const Gap(7),
          ],
        ],
      ),
    );
  }
}

class _StabilityBar extends StatelessWidget {
  final double progress;
  final bool ready;
  const _StabilityBar({required this.progress, required this.ready});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 210,
      height: 6,
      child: Stack(
        children: [
          Container(
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.15),
              borderRadius: BorderRadius.circular(99),
            ),
          ),
          AnimatedFractionallySizedBox(
            duration: const Duration(milliseconds: 110),
            widthFactor: progress.clamp(0.0, 1.0),
            heightFactor: 1,
            child: Container(
              decoration: BoxDecoration(
                color: ready ? const Color(0xFF22C55E) : AppColors.primary,
                borderRadius: BorderRadius.circular(99),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ReviewMessages extends StatelessWidget {
  final DocumentQualityResult? result;
  final bool ok;
  final bool notDocument;
  const _ReviewMessages(
      {required this.result, required this.ok, this.notDocument = false});

  @override
  Widget build(BuildContext context) {
    final r = result;
    final lines = <(IconData, Color, String)>[];
    if (notDocument) {
      lines.add((
        Icons.report_gmailerrorred_rounded,
        AppColors.error,
        'Esto no parece un documento de identidad. Captura tu cédula o '
            'tarjeta de identidad dentro del recuadro.'
      ));
    }
    if (r != null) {
      for (final issue in r.blockingIssues) {
        lines.add((Icons.error_outline_rounded, AppColors.error, issue.message));
      }
      for (final issue in r.warnings) {
        lines.add(
            (Icons.info_outline_rounded, AppColors.primary, issue.message));
      }
    }
    if (ok && lines.isEmpty) {
      lines.add((Icons.check_circle_outline_rounded, const Color(0xFF22C55E),
          'La imagen se ve bien. Revisa que los datos sean legibles.'));
    } else if (ok) {
      lines.insert(0, (Icons.check_circle_outline_rounded,
          const Color(0xFF22C55E), 'La imagen es aceptable. Revisa estos detalles:'));
    }
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: AppColors.dark.withValues(alpha: 0.6),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.white.withValues(alpha: 0.12)),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var i = 0; i < lines.length; i++) ...[
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(lines[i].$1, size: 16, color: lines[i].$2),
                const Gap(8),
                Expanded(
                  child: Text(lines[i].$3,
                      style: GoogleFonts.inter(
                          fontSize: 12,
                          height: 1.35,
                          color: Colors.white.withValues(alpha: 0.92))),
                ),
              ],
            ),
            if (i < lines.length - 1) const Gap(7),
          ],
        ],
      ),
    );
  }
}

/// Overlay con scrim oscuro y recuadro recortado (proporción tarjeta de ID),
/// con borde que cambia de color según el estado de detección.
class _DocFrameOverlay extends StatelessWidget {
  final _FrameState state;
  const _DocFrameOverlay({required this.state});

  static const double _aspect = 1.586; // ISO/IEC 7810 ID-1

  Color get _color => switch (state) {
        _FrameState.searching => Colors.white.withValues(alpha: 0.7),
        _FrameState.detecting => AppColors.primary,
        _FrameState.ready => const Color(0xFF22C55E),
      };

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(builder: (context, c) {
      final frameW = (c.maxWidth * 0.88).clamp(220.0, 480.0);
      final frameH = frameW / _aspect;
      final rect = Rect.fromCenter(
        center: Offset(c.maxWidth / 2, c.maxHeight * 0.44),
        width: frameW,
        height: frameH,
      );
      return Stack(
        children: [
          Positioned.fill(
            child: ClipPath(
              clipper: _RoundedRectCutoutClipper(rect: rect, radius: 18),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 200),
                color: state == _FrameState.ready
                    ? AppColors.dark.withValues(alpha: 0.46)
                    : AppColors.dark.withValues(alpha: 0.62),
              ),
            ),
          ),
          IgnorePointer(
            child: CustomPaint(
              size: Size(c.maxWidth, c.maxHeight),
              painter: _FrameBorderPainter(
                  rect: rect, radius: 18, color: _color, bold: state == _FrameState.ready),
            ),
          ),
        ],
      );
    });
  }
}

class _RoundedRectCutoutClipper extends CustomClipper<Path> {
  final Rect rect;
  final double radius;
  const _RoundedRectCutoutClipper({required this.rect, required this.radius});

  @override
  Path getClip(Size size) => Path()
    ..fillType = PathFillType.evenOdd
    ..addRect(Rect.fromLTWH(0, 0, size.width, size.height))
    ..addRRect(RRect.fromRectAndRadius(rect, Radius.circular(radius)));

  @override
  bool shouldReclip(covariant _RoundedRectCutoutClipper old) =>
      old.rect != rect || old.radius != radius;
}

class _FrameBorderPainter extends CustomPainter {
  final Rect rect;
  final double radius;
  final Color color;
  final bool bold;
  const _FrameBorderPainter(
      {required this.rect, required this.radius, required this.color, required this.bold});

  @override
  void paint(Canvas canvas, Size size) {
    final rrect = RRect.fromRectAndRadius(rect, Radius.circular(radius));
    canvas.drawRRect(
      rrect,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = bold ? 3 : 2
        ..color = color,
    );
    final corner = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = bold ? 5 : 4
      ..strokeCap = StrokeCap.round
      ..color = color;
    const len = 26.0;
    canvas.drawLine(rect.topLeft, rect.topLeft + const Offset(len, 0), corner);
    canvas.drawLine(rect.topLeft, rect.topLeft + const Offset(0, len), corner);
    canvas.drawLine(rect.topRight, rect.topRight + const Offset(-len, 0), corner);
    canvas.drawLine(rect.topRight, rect.topRight + const Offset(0, len), corner);
    canvas.drawLine(rect.bottomLeft, rect.bottomLeft + const Offset(len, 0), corner);
    canvas.drawLine(rect.bottomLeft, rect.bottomLeft + const Offset(0, -len), corner);
    canvas.drawLine(rect.bottomRight, rect.bottomRight + const Offset(-len, 0), corner);
    canvas.drawLine(rect.bottomRight, rect.bottomRight + const Offset(0, -len), corner);
  }

  @override
  bool shouldRepaint(covariant _FrameBorderPainter old) =>
      old.rect != rect || old.color != color || old.bold != bold || old.radius != radius;
}

// ── Estados de error / permisos ─────────────────────────────────────────────

class _PermissionState extends StatelessWidget {
  final bool permanent;
  final Future<void> Function() onRetry;
  const _PermissionState({required this.permanent, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return _ErrorBlock(
      icon: Icons.no_photography_rounded,
      title: 'Necesitamos acceso a la cámara',
      message: permanent
          ? 'Habilita la cámara para Iron Body desde Ajustes y vuelve a intentarlo.'
          : 'Sin permiso de cámara no podemos capturar tu documento.',
      actionLabel: permanent ? 'Abrir Ajustes' : 'Permitir cámara',
      onAction: onRetry,
    );
  }
}

class _ErrorBlock extends StatelessWidget {
  final IconData icon;
  final String title;
  final String message;
  final String actionLabel;
  final Future<void> Function() onAction;
  final String? secondaryLabel;
  final Future<void> Function()? onSecondary;

  const _ErrorBlock({
    required this.icon,
    required this.title,
    required this.message,
    required this.actionLabel,
    required this.onAction,
    this.secondaryLabel,
    this.onSecondary,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 32),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 54, color: AppColors.primary),
            const Gap(16),
            Text(title,
                textAlign: TextAlign.center,
                style: GoogleFonts.lexend(
                    fontSize: 17,
                    fontWeight: FontWeight.w700,
                    color: Colors.white)),
            const Gap(8),
            Text(message,
                textAlign: TextAlign.center,
                style: GoogleFonts.inter(
                    fontSize: 13, color: Colors.white.withValues(alpha: 0.75))),
            const Gap(20),
            FilledButton(
              onPressed: onAction,
              style: FilledButton.styleFrom(
                backgroundColor: AppColors.primary,
                foregroundColor: AppColors.dark,
                padding:
                    const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12)),
              ),
              child: Text(actionLabel,
                  style: GoogleFonts.lexend(
                      fontSize: 13, fontWeight: FontWeight.w700)),
            ),
            if (secondaryLabel != null && onSecondary != null) ...[
              const Gap(8),
              TextButton(
                onPressed: onSecondary,
                child: Text(secondaryLabel!,
                    style: GoogleFonts.inter(
                        fontSize: 12,
                        fontWeight: FontWeight.w600,
                        color: Colors.white.withValues(alpha: 0.85))),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
