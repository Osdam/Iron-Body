import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:local_auth/local_auth.dart';

import '../../../app_shell.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../../shared/widgets/auth_background.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../../shared/widgets/iron_input.dart';
import '../services/biometric_session_service.dart';
import 'register_screen.dart';

/// Login with two independent access paths:
///   1. Manual: document number + "INGRESAR" (validates and signs in).
///   2. Biometric quick access: opens the OS prompt; only succeeds when a
///      session was previously bound to this device via secure storage.
///
/// If a stored session exists, the biometric prompt is launched once on
/// startup, like banking apps. Cancellation never re-triggers the prompt and
/// never blocks manual access.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _docCtrl = TextEditingController();

  // Bootstrap
  bool _bootstrapping = true;

  // Capabilities + session
  bool _supportsBiometric = false;
  bool _hasStoredSession = false;
  String _bioLabel = 'Ingresar con biometría';
  IconData _bioIcon = Icons.fingerprint_rounded;

  // Auth state
  bool _autoLaunched = false;
  bool _bioAuthInProgress = false;
  bool _manualAuthInProgress = false;

  // Errors / info
  String? _docError; // shown only after tapping INGRESAR
  String? _bioError; // biometric flow errors
  String? _bioInfo;  // informational notice (e.g., need to log in first)

  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  @override
  void dispose() {
    _docCtrl.dispose();
    super.dispose();
  }

  Future<void> _bootstrap() async {
    final svc = BiometricSessionService.instance;
    final supports = await svc.hasDeviceSupport;
    final list = supports ? await svc.availableBiometrics() : <BiometricType>[];
    final label = await svc.resolveBiometricLabel();
    final hasSession = await svc.hasStoredSession();
    if (!mounted) return;
    setState(() {
      _supportsBiometric = supports;
      _bioLabel = label;
      _bioIcon = _iconFor(list);
      _hasStoredSession = hasSession;
      _bootstrapping = false;
    });
    if (hasSession && supports && !_autoLaunched) {
      _autoLaunched = true;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _runBiometric(autoTriggered: true);
      });
    }
  }

  IconData _iconFor(List<BiometricType> list) {
    if (Platform.isIOS && list.contains(BiometricType.face)) {
      return Icons.face_rounded;
    }
    if (list.contains(BiometricType.fingerprint) ||
        list.contains(BiometricType.strong) ||
        list.contains(BiometricType.weak)) {
      return Icons.fingerprint_rounded;
    }
    if (list.contains(BiometricType.face)) return Icons.face_rounded;
    return Icons.fingerprint_rounded;
  }

  // ── Manual flow ────────────────────────────────────────────────────────────

  Future<void> _onTapManualLogin() async {
    if (_manualAuthInProgress) return;
    final doc = _docCtrl.text.trim();
    if (doc.isEmpty) {
      setState(() {
        _docError = 'Ingresa tu número de documento';
        _bioError = null;
        _bioInfo = null;
      });
      return;
    }
    setState(() {
      _docError = null;
      _bioError = null;
      _bioInfo = null;
      _manualAuthInProgress = true;
    });

    // TODO(backend): replace with real auth endpoint.
    await Future.delayed(const Duration(milliseconds: 600));
    if (!mounted) return;

    if (doc != mockUser.document) {
      setState(() {
        _manualAuthInProgress = false;
        _docError = 'Documento no encontrado. Verifica e intenta nuevamente.';
      });
      return;
    }

    // Bind this doc + an opaque token to the device for future biometric access.
    // Replacing any previous session ensures we never reuse another user's token.
    final token = 'sess-${DateTime.now().millisecondsSinceEpoch}';
    await BiometricSessionService.instance.persistSession(
      document: doc,
      token: token,
    );

    AppSession.login(mockUser);
    if (!mounted) return;
    Navigator.pushAndRemoveUntil(
      context,
      MaterialPageRoute(builder: (_) => const AppShell()),
      (_) => false,
    );
  }

  // ── Biometric flow ─────────────────────────────────────────────────────────

  Future<void> _onTapBiometric() async {
    setState(() {
      _docError = null;
      _bioError = null;
      _bioInfo = null;
    });
    if (!_supportsBiometric) {
      setState(() =>
          _bioError = 'Este dispositivo no tiene biometría configurada.');
      return;
    }
    if (!_hasStoredSession) {
      setState(() => _bioInfo =
          'Primero inicia sesión con tu documento para activar el acceso biométrico en este dispositivo.');
      return;
    }
    await _runBiometric(autoTriggered: false);
  }

  Future<void> _runBiometric({required bool autoTriggered}) async {
    if (_bioAuthInProgress) return;
    setState(() {
      _bioAuthInProgress = true;
      _bioError = null;
      _bioInfo = null;
    });
    final result = await BiometricSessionService.instance.authenticate(
      reason: 'Confirma tu identidad para entrar a Iron Body',
    );
    if (!mounted) return;
    if (result.isSuccess) {
      AppSession.login(mockUser);
      if (!mounted) return;
      Navigator.pushAndRemoveUntil(
        context,
        MaterialPageRoute(builder: (_) => const AppShell()),
        (_) => false,
      );
      return;
    }
    setState(() {
      _bioAuthInProgress = false;
      _bioError = _resolveBioError(result, autoTriggered: autoTriggered);
    });
  }

  String? _resolveBioError(
    BiometricAuthResult r, {
    required bool autoTriggered,
  }) {
    switch (r.outcome) {
      case BiometricOutcome.success:
        return null;
      case BiometricOutcome.userCancelled:
        // Silent on auto launch — user dismissed the prompt intentionally.
        return autoTriggered
            ? null
            : 'Verificación cancelada. Inténtalo nuevamente.';
      case BiometricOutcome.notAvailable:
      case BiometricOutcome.notEnrolled:
        return r.message;
      case BiometricOutcome.lockedOut:
        return r.message;
      case BiometricOutcome.failed:
        return 'No se pudo verificar tu identidad. Inténtalo nuevamente.';
    }
  }

  // ── Build ──────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface0,
      body: AuthBackground(
        child: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                const Gap(40),
                Image.asset(AppAssets.logo, height: 84, fit: BoxFit.contain)
                    .animate()
                    .fadeIn(duration: 600.ms)
                    .scale(begin: const Offset(0.85, 0.85)),
                const Gap(24),
                Align(
                  alignment: Alignment.centerLeft,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Iniciar sesión',
                        style: GoogleFonts.lexend(
                          fontSize: 26,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                        ),
                      ),
                      const Gap(2),
                      Text(
                        'Accede con tu documento o biometría',
                        style: GoogleFonts.inter(
                          fontSize: 14,
                          color: AppColors.textSecondary,
                        ),
                      ),
                    ],
                  ),
                ).animate().fadeIn(delay: 150.ms).slideY(begin: 0.2),
                const Gap(22),

                // ── Manual ─────────────────────────────────────────────────
                IronInput(
                  label: 'Número de documento',
                  hint: 'Ej: 123456789',
                  controller: _docCtrl,
                  prefixLottie: AppAssets.lottieDocumento,
                  keyboardType: TextInputType.number,
                ).animate().fadeIn(delay: 250.ms).slideY(begin: 0.15),
                if (_docError != null) ...[
                  const Gap(6),
                  _InlineFieldError(message: _docError!),
                ],
                const Gap(16),
                Opacity(
                  opacity: _manualAuthInProgress ? 0.7 : 1,
                  child: IronButton(
                    label: _manualAuthInProgress ? 'INGRESANDO…' : 'INGRESAR',
                    onPressed:
                        _manualAuthInProgress ? () {} : _onTapManualLogin,
                  ),
                ).animate().fadeIn(delay: 350.ms).slideY(begin: 0.15),

                const Gap(22),

                // ── Separator ──────────────────────────────────────────────
                if (!_bootstrapping)
                  const _OrDivider().animate().fadeIn(delay: 420.ms),

                if (!_bootstrapping) const Gap(18),

                // ── Biometric quick access ────────────────────────────────
                if (_bootstrapping)
                  const _BootstrapPlaceholder()
                else
                  _BiometricQuickAccess(
                    icon: _bioIcon,
                    label: _bioLabel,
                    enabled: _supportsBiometric,
                    busy: _bioAuthInProgress,
                    hasStoredSession: _hasStoredSession,
                    onTap: _onTapBiometric,
                  ).animate().fadeIn(delay: 460.ms).slideY(begin: 0.15),

                if (_bioError != null) ...[
                  const Gap(14),
                  _NoticeBanner.error(message: _bioError!),
                ],
                if (_bioInfo != null) ...[
                  const Gap(14),
                  _NoticeBanner.info(message: _bioInfo!),
                ],

                const Gap(28),
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text(
                      '¿No tienes cuenta?  ',
                      style: GoogleFonts.inter(
                        fontSize: 13,
                        color: AppColors.textSecondary,
                      ),
                    ),
                    GestureDetector(
                      onTap: () => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => const RegisterScreen(),
                        ),
                      ),
                      child: Text(
                        'Crear cuenta',
                        style: GoogleFonts.inter(
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                        ),
                      ),
                    ),
                  ],
                ).animate().fadeIn(delay: 600.ms),
                const Gap(28),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

// ─── Sub-widgets ────────────────────────────────────────────────────────────

class _InlineFieldError extends StatelessWidget {
  final String message;
  const _InlineFieldError({required this.message});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(
            Icons.error_outline_rounded,
            size: 14,
            color: Color(0xFFD32F2F),
          ),
          const Gap(6),
          Expanded(
            child: Text(
              message,
              style: GoogleFonts.inter(
                fontSize: 12,
                fontWeight: FontWeight.w500,
                color: const Color(0xFFB91C1C),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _OrDivider extends StatelessWidget {
  const _OrDivider();

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        const Expanded(child: Divider(color: AppColors.border, height: 1)),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: Text(
            'o',
            style: GoogleFonts.inter(
              fontSize: 11,
              fontWeight: FontWeight.w700,
              color: AppColors.textDisabled,
              letterSpacing: 1.2,
            ),
          ),
        ),
        const Expanded(child: Divider(color: AppColors.border, height: 1)),
      ],
    );
  }
}

class _BootstrapPlaceholder extends StatelessWidget {
  const _BootstrapPlaceholder();

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 90,
      child: Center(
        child: Container(
          width: 70,
          height: 70,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: AppColors.surface1,
            border: Border.all(color: AppColors.border),
          ),
          child: const Center(
            child: SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(
                strokeWidth: 2.4,
                valueColor: AlwaysStoppedAnimation<Color>(AppColors.primary),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _BiometricQuickAccess extends StatelessWidget {
  final IconData icon;
  final String label;
  final bool enabled;
  final bool busy;
  final bool hasStoredSession;
  final VoidCallback onTap;

  const _BiometricQuickAccess({
    required this.icon,
    required this.label,
    required this.enabled,
    required this.busy,
    required this.hasStoredSession,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final hint = !enabled
        ? 'Biometría no disponible en este dispositivo'
        : hasStoredSession
            ? 'Acceso biométrico disponible'
            : 'Activa el acceso biométrico iniciando con tu documento';

    final disabledLook = !enabled || busy;

    return Column(
      children: [
        Material(
          color: Colors.transparent,
          shape: const CircleBorder(),
          child: InkWell(
            onTap: disabledLook ? null : onTap,
            customBorder: const CircleBorder(),
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 220),
              width: 76,
              height: 76,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.primary
                    .withValues(alpha: disabledLook ? 0.06 : 0.14),
                border: Border.all(
                  color: AppColors.primary
                      .withValues(alpha: disabledLook ? 0.25 : 0.55),
                  width: busy ? 2 : 1.4,
                ),
                boxShadow: disabledLook
                    ? null
                    : [
                        BoxShadow(
                          color: AppColors.primary.withValues(alpha: 0.18),
                          blurRadius: 14,
                          spreadRadius: 1,
                        ),
                      ],
              ),
              child: Center(
                child: busy
                    ? const SizedBox(
                        width: 26,
                        height: 26,
                        child: CircularProgressIndicator(
                          strokeWidth: 2.4,
                          valueColor:
                              AlwaysStoppedAnimation<Color>(AppColors.primary),
                        ),
                      )
                    : Icon(
                        icon,
                        size: 38,
                        color: AppColors.primary
                            .withValues(alpha: disabledLook ? 0.55 : 1),
                      ),
              ),
            ),
          ),
        ),
        const Gap(10),
        Text(
          label,
          style: GoogleFonts.lexend(
            fontSize: 13,
            fontWeight: FontWeight.w700,
            color: enabled ? AppColors.textPrimary : AppColors.textDisabled,
            letterSpacing: 0.2,
          ),
        ),
        const Gap(2),
        Text(
          hint,
          textAlign: TextAlign.center,
          style: GoogleFonts.inter(
            fontSize: 11,
            color: AppColors.textSecondary,
          ),
        ),
      ],
    );
  }
}

class _NoticeBanner extends StatelessWidget {
  final String message;
  final Color background;
  final Color border;
  final Color textColor;
  final IconData icon;

  const _NoticeBanner._({
    required this.message,
    required this.background,
    required this.border,
    required this.textColor,
    required this.icon,
  });

  factory _NoticeBanner.error({required String message}) =>
      _NoticeBanner._(
        message: message,
        background: const Color(0xFFFFEBEB),
        border: const Color(0xFFD32F2F).withValues(alpha: 0.25),
        textColor: const Color(0xFFB91C1C),
        icon: Icons.error_outline_rounded,
      );

  factory _NoticeBanner.info({required String message}) =>
      _NoticeBanner._(
        message: message,
        background: AppColors.primary.withValues(alpha: 0.08),
        border: AppColors.primary.withValues(alpha: 0.3),
        textColor: AppColors.textPrimary,
        icon: Icons.info_outline_rounded,
      );

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: border),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 16, color: textColor),
          const Gap(8),
          Expanded(
            child: Text(
              message,
              style: GoogleFonts.inter(
                fontSize: 12,
                fontWeight: FontWeight.w500,
                color: textColor,
                height: 1.35,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
