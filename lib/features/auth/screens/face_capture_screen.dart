import 'dart:async';
import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:permission_handler/permission_handler.dart';

import '../../../core/theme/app_colors.dart';
import '../services/face_frame_processor.dart';
import '../services/face_quality_analyzer.dart';

/// Full-screen camera with real-time face detection. Returns the captured temp
/// file path via [Navigator.pop], or null if the user cancels / fails.
class FaceCaptureScreen extends StatefulWidget {
  const FaceCaptureScreen({super.key});

  @override
  State<FaceCaptureScreen> createState() => _FaceCaptureScreenState();
}

enum _ScreenStage {
  initializing,
  permissionDenied,
  permissionPermanent,
  cameraError,
  scanning,
  capturing,
}

class _FaceCaptureScreenState extends State<FaceCaptureScreen>
    with WidgetsBindingObserver {
  CameraController? _controller;
  CameraDescription? _camera;
  final FaceFrameProcessor _processor = FaceFrameProcessor();

  _ScreenStage _stage = _ScreenStage.initializing;
  String? _errorDetail;

  FaceQualityResult _quality = const FaceQualityResult(
    FaceQualityCode.noFace,
    'Coloca tu rostro dentro del óvalo',
  );
  bool _streaming = false;

  DateTime? _readySince;
  double _holdProgress = 0;
  Timer? _holdTicker;
  bool _hapticFiredForReady = false;

  static const Duration _holdDuration = Duration(milliseconds: 1500);

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
    SystemChrome.setPreferredOrientations(DeviceOrientation.values);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final controller = _controller;
    if (controller == null) return;
    if (state == AppLifecycleState.inactive ||
        state == AppLifecycleState.paused) {
      _disposeCamera();
    } else if (state == AppLifecycleState.resumed &&
        _stage == _ScreenStage.scanning) {
      _initCamera();
    }
  }

  Future<void> _bootstrap() async {
    final status = await Permission.camera.status;
    if (status.isGranted) {
      await _initCamera();
      return;
    }
    if (status.isPermanentlyDenied) {
      _setStage(_ScreenStage.permissionPermanent);
      return;
    }
    final result = await Permission.camera.request();
    if (!mounted) return;
    if (result.isGranted) {
      await _initCamera();
    } else if (result.isPermanentlyDenied) {
      _setStage(_ScreenStage.permissionPermanent);
    } else {
      _setStage(_ScreenStage.permissionDenied);
    }
  }

  Future<void> _initCamera() async {
    setState(() {
      _stage = _ScreenStage.initializing;
      _errorDetail = null;
    });
    try {
      final cameras = await availableCameras();
      if (cameras.isEmpty) {
        throw CameraException(
          'no_camera',
          'No hay cámaras disponibles en el dispositivo',
        );
      }
      _camera = cameras.firstWhere(
        (c) => c.lensDirection == CameraLensDirection.front,
        orElse: () => cameras.first,
      );
      final controller = CameraController(
        _camera!,
        ResolutionPreset.medium,
        enableAudio: false,
        imageFormatGroup: Platform.isAndroid
            ? ImageFormatGroup.nv21
            : ImageFormatGroup.bgra8888,
      );
      await controller.initialize();
      if (!mounted) {
        await controller.dispose();
        return;
      }
      _controller = controller;
      setState(() => _stage = _ScreenStage.scanning);
      await _startStream();
    } on CameraException catch (e) {
      if (!mounted) return;
      setState(() {
        _stage = _ScreenStage.cameraError;
        _errorDetail = _mapCameraError(e);
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _stage = _ScreenStage.cameraError;
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
    if (_stage != _ScreenStage.scanning) return;
    final result = await _processor.process(
      image,
      _camera!,
      DeviceOrientation.portraitUp,
    );
    if (result == null || !mounted || _stage != _ScreenStage.scanning) return;

    setState(() => _quality = result);

    if (result.isReady) {
      _readySince ??= DateTime.now();
      if (!_hapticFiredForReady) {
        _hapticFiredForReady = true;
        unawaited(HapticFeedback.selectionClick());
      }
      _holdTicker ??= Timer.periodic(const Duration(milliseconds: 60), (_) {
        if (!mounted || _readySince == null) return;
        final elapsed = DateTime.now().difference(_readySince!);
        final ratio = (elapsed.inMilliseconds / _holdDuration.inMilliseconds)
            .clamp(0.0, 1.0);
        if (ratio != _holdProgress) {
          setState(() => _holdProgress = ratio);
        }
        if (elapsed >= _holdDuration && _stage == _ScreenStage.scanning) {
          _holdTicker?.cancel();
          _holdTicker = null;
          unawaited(_capture());
        }
      });
    } else {
      _readySince = null;
      _holdProgress = 0;
      _hapticFiredForReady = false;
      _holdTicker?.cancel();
      _holdTicker = null;
    }
  }

  Future<void> _capture() async {
    if (_stage == _ScreenStage.capturing) return;
    setState(() => _stage = _ScreenStage.capturing);
    unawaited(HapticFeedback.mediumImpact());
    await _stopStream();
    try {
      final shot = await _controller!.takePicture();
      if (!mounted) return;
      Navigator.of(context).pop(shot.path);
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _stage = _ScreenStage.scanning;
        _errorDetail = null;
        _readySince = null;
        _holdProgress = 0;
        _hapticFiredForReady = false;
      });
      await _startStream();
    }
  }

  void _setStage(_ScreenStage stage) {
    if (!mounted) return;
    setState(() => _stage = stage);
  }

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
              top: 4,
              left: 4,
              child: IconButton(
                onPressed: () => Navigator.of(context).pop(),
                icon: const Icon(
                  Icons.close_rounded,
                  color: Colors.white,
                  size: 26,
                ),
              ),
            ),
            const Positioned(top: 14, right: 14, child: _SecureChip()),
          ],
        ),
      ),
    );
  }

  Widget _buildBody() {
    switch (_stage) {
      case _ScreenStage.initializing:
        return const _LoadingState();
      case _ScreenStage.permissionDenied:
        return _PermissionDeniedState(
          onRetry: _bootstrap,
          permanent: false,
        );
      case _ScreenStage.permissionPermanent:
        return _PermissionDeniedState(
          onRetry: () async {
            await openAppSettings();
            if (!mounted) return;
            await _bootstrap();
          },
          permanent: true,
        );
      case _ScreenStage.cameraError:
        return _CameraErrorState(
          message: _errorDetail ?? '',
          onRetry: _initCamera,
        );
      case _ScreenStage.scanning:
      case _ScreenStage.capturing:
        return _buildScanning();
    }
  }

  Widget _buildScanning() {
    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) {
      return const _LoadingState();
    }
    final preview = controller.value.previewSize ?? const Size(720, 1280);
    final isReady = _quality.isReady;
    final overlayState = _resolveOverlayState(isReady);

    return Stack(
      fit: StackFit.expand,
      children: [
        FittedBox(
          fit: BoxFit.cover,
          child: SizedBox(
            width: preview.height,
            height: preview.width,
            child: CameraPreview(controller),
          ),
        ),
        Positioned.fill(
          child: _OvalGuideOverlay(
            state: overlayState,
            holdProgress: _holdProgress,
          ),
        ),
        Positioned(
          left: 24,
          right: 24,
          top: 56,
          child: Center(
            child: _MessagePill(
              text: _quality.message,
              accent: _accentForState(overlayState),
            ),
          ),
        ),
        Positioned(
          left: 0,
          right: 0,
          bottom: 28,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              _StabilityBar(progress: _holdProgress, ready: isReady),
              const Gap(10),
              Text(
                isReady
                    ? 'Mantén la pose para capturar automáticamente'
                    : 'Captura automática cuando todo esté correcto',
                textAlign: TextAlign.center,
                style: GoogleFonts.inter(
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                  color: Colors.white.withValues(alpha: 0.85),
                ),
              ),
              if (_stage == _ScreenStage.capturing) ...[
                const Gap(14),
                const SizedBox(
                  width: 22,
                  height: 22,
                  child: CircularProgressIndicator(
                    strokeWidth: 2.4,
                    valueColor:
                        AlwaysStoppedAnimation<Color>(AppColors.primary),
                  ),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }

  _OverlayState _resolveOverlayState(bool ready) {
    if (ready) return _OverlayState.ready;
    switch (_quality.code) {
      case FaceQualityCode.noFace:
      case FaceQualityCode.multipleFaces:
        return _OverlayState.searching;
      default:
        return _OverlayState.adjusting;
    }
  }

  Color _accentForState(_OverlayState state) {
    switch (state) {
      case _OverlayState.ready:
        return const Color(0xFF22C55E);
      case _OverlayState.adjusting:
        return AppColors.primary;
      case _OverlayState.searching:
        return Colors.white;
    }
  }
}

// ─── Sub-widgets ────────────────────────────────────────────────────────────

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
          Text(
            'Captura segura',
            style: GoogleFonts.inter(
              fontSize: 11,
              fontWeight: FontWeight.w600,
              color: Colors.white,
            ),
          ),
        ],
      ),
    );
  }
}

class _LoadingState extends StatelessWidget {
  const _LoadingState();

  @override
  Widget build(BuildContext context) {
    return const Center(
      child: CircularProgressIndicator(
        valueColor: AlwaysStoppedAnimation<Color>(AppColors.primary),
      ),
    );
  }
}

class _MessagePill extends StatelessWidget {
  final String text;
  final Color accent;
  const _MessagePill({required this.text, required this.accent});

  @override
  Widget build(BuildContext context) {
    return AnimatedContainer(
      duration: const Duration(milliseconds: 220),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
      decoration: BoxDecoration(
        color: AppColors.dark.withValues(alpha: 0.72),
        borderRadius: BorderRadius.circular(99),
        border: Border.all(color: accent.withValues(alpha: 0.7), width: 1.2),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 8,
            height: 8,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: accent,
              boxShadow: [
                BoxShadow(
                  color: accent.withValues(alpha: 0.6),
                  blurRadius: 8,
                  spreadRadius: 1,
                ),
              ],
            ),
          ),
          const Gap(8),
          Flexible(
            child: Text(
              text,
              textAlign: TextAlign.center,
              style: GoogleFonts.lexend(
                fontSize: 13,
                fontWeight: FontWeight.w600,
                color: Colors.white,
              ),
            ),
          ),
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
      width: 200,
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
            duration: const Duration(milliseconds: 120),
            widthFactor: progress,
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

enum _OverlayState { searching, adjusting, ready }

class _OvalGuideOverlay extends StatelessWidget {
  final _OverlayState state;
  final double holdProgress;

  const _OvalGuideOverlay({
    required this.state,
    required this.holdProgress,
  });

  Color get _borderColor {
    switch (state) {
      case _OverlayState.searching:
        return Colors.white.withValues(alpha: 0.55);
      case _OverlayState.adjusting:
        return AppColors.primary;
      case _OverlayState.ready:
        return const Color(0xFF22C55E);
    }
  }

  double get _borderWidth => state == _OverlayState.ready ? 3 : 2;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final ovalWidth = (constraints.maxWidth * 0.66).clamp(180.0, 280.0);
        final ovalHeight = (ovalWidth * 1.32).clamp(240.0, 380.0);
        return Stack(
          children: [
            Positioned.fill(
              child: ClipPath(
                clipper: _OvalCutoutClipper(
                  ovalSize: Size(ovalWidth, ovalHeight),
                ),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 220),
                  color: state == _OverlayState.ready
                      ? AppColors.dark.withValues(alpha: 0.45)
                      : AppColors.dark.withValues(alpha: 0.62),
                ),
              ),
            ),
            IgnorePointer(
              child: Center(
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 220),
                  width: ovalWidth,
                  height: ovalHeight,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.all(
                      Radius.elliptical(ovalWidth / 2, ovalHeight / 2),
                    ),
                    border: Border.all(
                      color: _borderColor.withValues(alpha: 0.9),
                      width: _borderWidth,
                    ),
                    boxShadow: state == _OverlayState.ready
                        ? [
                            BoxShadow(
                              color: const Color(0xFF22C55E)
                                  .withValues(alpha: 0.4),
                              blurRadius: 24,
                              spreadRadius: 2,
                            ),
                          ]
                        : null,
                  ),
                ),
              ),
            ),
          ],
        );
      },
    );
  }
}

class _OvalCutoutClipper extends CustomClipper<Path> {
  final Size ovalSize;
  const _OvalCutoutClipper({required this.ovalSize});

  @override
  Path getClip(Size size) {
    final path = Path()..fillType = PathFillType.evenOdd;
    path.addRect(Rect.fromLTWH(0, 0, size.width, size.height));
    final ovalRect = Rect.fromCenter(
      center: Offset(size.width / 2, size.height / 2),
      width: ovalSize.width,
      height: ovalSize.height,
    );
    path.addOval(ovalRect);
    return path;
  }

  @override
  bool shouldReclip(covariant _OvalCutoutClipper oldClipper) =>
      oldClipper.ovalSize != ovalSize;
}

// ─── Error / permission states ──────────────────────────────────────────────

class _PermissionDeniedState extends StatelessWidget {
  final bool permanent;
  final Future<void> Function() onRetry;

  const _PermissionDeniedState({
    required this.permanent,
    required this.onRetry,
  });

  @override
  Widget build(BuildContext context) {
    return _CenteredErrorBlock(
      icon: Icons.no_photography_rounded,
      title: 'Necesitamos acceso a la cámara',
      message: permanent
          ? 'Habilita la cámara para Iron Body desde Ajustes y vuelve a intentarlo.'
          : 'Sin permiso de cámara no podemos verificar tu identidad.',
      actionLabel: permanent ? 'Abrir Ajustes' : 'Permitir cámara',
      onAction: onRetry,
    );
  }
}

class _CameraErrorState extends StatelessWidget {
  final String message;
  final Future<void> Function() onRetry;

  const _CameraErrorState({required this.message, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return _CenteredErrorBlock(
      icon: Icons.error_outline_rounded,
      title: 'No pudimos iniciar la cámara',
      message: message,
      actionLabel: 'Reintentar',
      onAction: onRetry,
    );
  }
}

class _CenteredErrorBlock extends StatelessWidget {
  final IconData icon;
  final String title;
  final String message;
  final String actionLabel;
  final Future<void> Function() onAction;

  const _CenteredErrorBlock({
    required this.icon,
    required this.title,
    required this.message,
    required this.actionLabel,
    required this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 32),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 56, color: AppColors.primary),
            const Gap(16),
            Text(
              title,
              textAlign: TextAlign.center,
              style: GoogleFonts.lexend(
                fontSize: 17,
                fontWeight: FontWeight.w700,
                color: Colors.white,
              ),
            ),
            const Gap(8),
            Text(
              message,
              textAlign: TextAlign.center,
              style: GoogleFonts.inter(
                fontSize: 13,
                color: Colors.white.withValues(alpha: 0.75),
              ),
            ),
            const Gap(20),
            FilledButton(
              onPressed: onAction,
              style: FilledButton.styleFrom(
                backgroundColor: AppColors.primary,
                foregroundColor: AppColors.dark,
                padding: const EdgeInsets.symmetric(
                  horizontal: 24,
                  vertical: 14,
                ),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
              ),
              child: Text(
                actionLabel,
                style: GoogleFonts.lexend(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
