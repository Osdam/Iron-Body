import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../shared/widgets/auth_background.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../../shared/widgets/iron_input.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/user_model.dart';
import '../../memberships/screens/memberships_screen.dart';
import '../services/face_verification_service.dart';
import 'face_capture_screen.dart';

class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _pageController = PageController();
  int _step = 0;

  // Step 1
  final _docCtrl = TextEditingController();
  final _nameCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  String _gender = 'Masculino';

  // Step 2
  final _weightCtrl = TextEditingController();
  final _heightCtrl = TextEditingController();
  String _goal = 'Hipertrofia muscular';
  String _level = 'Principiante';

  // Step 3
  static const String _sede = 'Sede Sur';
  bool _terms = false;
  bool _bioConsent = false;
  FaceCapture? _faceCapture;
  String? _faceError;
  bool _processingCapture = false;
  bool _submitting = false;

  final _goals = const [
    'Hipertrofia muscular',
    'Pérdida de grasa',
    'Resistencia',
    'Fuerza',
    'Bienestar general',
  ];
  final _levels = const ['Principiante', 'Intermedio', 'Avanzado'];

  static const _stepTitles = [
    'Datos personales',
    'Datos físicos',
    'Verificación',
  ];

  bool get _consentGiven => _terms && _bioConsent;
  bool get _step3Ready => _consentGiven && _faceCapture?.isVerified == true;

  void _next() {
    if (_step < 2) {
      setState(() => _step++);
      _pageController.animateToPage(
        _step,
        duration: 400.ms,
        curve: Curves.easeOutCubic,
      );
    } else {
      _submitFinalStep();
    }
  }

  void _back() {
    if (_step > 0) {
      setState(() => _step--);
      _pageController.animateToPage(
        _step,
        duration: 400.ms,
        curve: Curves.easeOutCubic,
      );
    } else {
      Navigator.pop(context);
    }
  }

  Future<void> _captureFace() async {
    if (!_consentGiven || _processingCapture) return;
    setState(() {
      _processingCapture = true;
      _faceError = null;
    });

    final tempPath = await Navigator.of(context).push<String?>(
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => const FaceCaptureScreen(),
      ),
    );

    if (!mounted) return;

    if (tempPath == null) {
      setState(() => _processingCapture = false);
      return;
    }

    try {
      // Move from OS temp into app-private storage and prepare payload.
      final previous = _faceCapture;
      final capture =
          await FaceVerificationService.instance.persistCapture(tempPath);
      if (previous != null) {
        await FaceVerificationService.instance.dispose(previous);
      }
      if (!mounted) return;
      // Evict any stale ImageProvider cache for the previous file path.
      if (previous != null) {
        FileImage(File(previous.localPath)).evict();
      }
      setState(() {
        _faceCapture = capture;
        _faceError = null;
        _processingCapture = false;
      });
    } on FaceVerificationException catch (e) {
      if (!mounted) return;
      setState(() {
        _faceError = e.message;
        _processingCapture = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _faceError = 'No se pudo registrar la captura. Inténtalo de nuevo.';
        _processingCapture = false;
      });
    }
  }

  Future<void> _submitFinalStep() async {
    if (!_consentGiven) {
      _showSnack('Acepta los términos y el consentimiento biométrico.');
      return;
    }
    if (_faceCapture == null) {
      _showSnack('Completa la verificación facial para continuar.');
      return;
    }
    if (_submitting) return;
    setState(() => _submitting = true);

    final pendingId = 'pending-${DateTime.now().millisecondsSinceEpoch}';
    final pending = UserModel(
      id: pendingId,
      fullName: _nameCtrl.text.trim().isEmpty
          ? 'Nuevo usuario'
          : _nameCtrl.text.trim(),
      email: _emailCtrl.text.trim(),
      document: _docCtrl.text.trim(),
      phone: _phoneCtrl.text.trim(),
      goal: _goal,
      weight: double.tryParse(_weightCtrl.text.replaceAll(',', '.')) ?? 0,
      height: double.tryParse(_heightCtrl.text.replaceAll(',', '.')) ?? 0,
      planName: 'Sin plan activo',
      membershipExpiry: DateTime.now(),
      avatarUrl: _faceCapture!.localPath,
    );

    try {
      final registered = await FaceVerificationService.instance
          .submitToBackend(_faceCapture!, userId: pendingId);
      if (!mounted) return;
      setState(() => _faceCapture = registered);

      AppSession.login(pending);

      if (!mounted) return;
      Navigator.pushAndRemoveUntil(
        context,
        MaterialPageRoute(builder: (_) => const MembershipsScreen()),
        (_) => false,
      );
    } on FaceVerificationException catch (e) {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _faceCapture = _faceCapture?.copyWith(status: BiometricStatus.failed);
        _faceError = e.message;
      });
      _showSnack(e.message);
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _faceCapture = _faceCapture?.copyWith(status: BiometricStatus.failed);
        _faceError = 'No se pudo enviar la verificación. Inténtalo de nuevo.';
      });
      _showSnack(_faceError!);
    }
  }

  void _showSnack(String message) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(message),
          behavior: SnackBarBehavior.floating,
          backgroundColor: AppColors.dark,
        ),
      );
  }

  @override
  void dispose() {
    _pageController.dispose();
    _docCtrl.dispose();
    _nameCtrl.dispose();
    _emailCtrl.dispose();
    _phoneCtrl.dispose();
    _weightCtrl.dispose();
    _heightCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isFinal = _step == 2;
    final continueEnabled = isFinal ? _step3Ready && !_submitting : true;

    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: AppBar(
        backgroundColor: AppColors.surface0,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(
            Icons.arrow_back_ios_new_rounded,
            size: 20,
            color: AppColors.textPrimary,
          ),
          onPressed: _back,
        ),
        title: Text(
          'Crear cuenta',
          style: GoogleFonts.lexend(
            fontSize: 18,
            fontWeight: FontWeight.w700,
            color: AppColors.textPrimary,
          ),
        ),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(1),
          child: Container(height: 1, color: AppColors.border),
        ),
      ),
      body: AuthBackground(
        child: Column(
          children: [
            Padding(
              padding:
                  const EdgeInsets.symmetric(vertical: 20, horizontal: 24),
              child: Column(
                children: [
                  Row(
                    children: List.generate(
                      3,
                      (i) => Expanded(
                        child: Container(
                          height: 4,
                          margin: EdgeInsets.only(right: i < 2 ? 6 : 0),
                          decoration: BoxDecoration(
                            color: i <= _step
                                ? AppColors.primary
                                : AppColors.border,
                            borderRadius: BorderRadius.circular(99),
                          ),
                        ),
                      ),
                    ),
                  ),
                  const Gap(8),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        'Paso ${_step + 1} de 3',
                        style: GoogleFonts.lexend(
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textSecondary,
                        ),
                      ),
                      Text(
                        _stepTitles[_step],
                        style: GoogleFonts.inter(
                          fontSize: 12,
                          color: AppColors.textSecondary,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            Expanded(
              child: PageView(
                controller: _pageController,
                physics: const NeverScrollableScrollPhysics(),
                children: [_buildStep1(), _buildStep2(), _buildStep3()],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 8, 24, 32),
              child: Opacity(
                opacity: continueEnabled ? 1 : 0.55,
                child: IronButton(
                  label: _submitting
                      ? 'PROCESANDO...'
                      : isFinal
                          ? 'CONTINUAR A MEMBRESÍA'
                          : 'CONTINUAR',
                  onPressed: _submitting ? () {} : _next,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // ── Step 1 ──────────────────────────────────────────────────────────────
  Widget _buildStep1() => SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 24),
        child: Column(
          children: [
            IronInput(
              label: 'Documento',
              hint: 'Número de documento',
              controller: _docCtrl,
              prefixLottie: AppAssets.lottieDocumento,
            ),
            const Gap(16),
            IronInput(
              label: 'Nombre completo',
              hint: 'Ej: Juan Pérez García',
              controller: _nameCtrl,
              prefixLottie: AppAssets.lottieUser,
            ),
            const Gap(16),
            IronInput(
              label: 'Correo electrónico',
              hint: 'correo@ejemplo.com',
              controller: _emailCtrl,
              prefixLottie: AppAssets.lottieEmail,
              keyboardType: TextInputType.emailAddress,
            ),
            const Gap(16),
            IronInput(
              label: 'Teléfono',
              hint: '300 000 0000',
              controller: _phoneCtrl,
              prefixLottie: AppAssets.lottieTelefono,
              keyboardType: TextInputType.phone,
            ),
            const Gap(16),
            _buildDropdown(
              'Género',
              _gender,
              const ['Masculino', 'Femenino', 'Otro'],
              (v) => setState(() => _gender = v!),
            ),
            const Gap(24),
          ],
        ),
      );

  // ── Step 2 ──────────────────────────────────────────────────────────────
  Widget _buildStep2() => SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 24),
        child: Column(
          children: [
            Row(
              children: [
                Expanded(
                  child: IronInput(
                    label: 'Peso (kg)',
                    hint: '75',
                    controller: _weightCtrl,
                    keyboardType: TextInputType.number,
                  ),
                ),
                const Gap(12),
                Expanded(
                  child: IronInput(
                    label: 'Estatura (cm)',
                    hint: '175',
                    controller: _heightCtrl,
                    keyboardType: TextInputType.number,
                  ),
                ),
              ],
            ),
            const Gap(16),
            _buildDropdown(
              'Objetivo físico',
              _goal,
              _goals,
              (v) => setState(() => _goal = v!),
            ),
            const Gap(16),
            _buildDropdown(
              'Nivel de experiencia',
              _level,
              _levels,
              (v) => setState(() => _level = v!),
            ),
            const Gap(16),
            IronInput(
              label: 'Lesiones o restricciones',
              hint: 'Opcional...',
              maxLines: 3,
            ),
            const Gap(24),
          ],
        ),
      );

  // ── Step 3 ──────────────────────────────────────────────────────────────
  Widget _buildStep3() => SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _SedeCard(sede: _sede),
            const Gap(16),
            _ConsentBlock(
              terms: _terms,
              bio: _bioConsent,
              onTerms: (v) => setState(() => _terms = v),
              onBio: (v) => setState(() => _bioConsent = v),
            ),
            const Gap(8),
            _BiometricUsageNote(),
            const Gap(16),
            _FaceVerificationCard(
              capture: _faceCapture,
              processing: _processingCapture,
              consentGiven: _consentGiven,
              error: _faceError,
              onCapture: _captureFace,
            ),
            const Gap(20),
            Text(
              'La captura se almacena cifrada en el servidor y solo se usa para verificar tu identidad y controlar el acceso.',
              textAlign: TextAlign.center,
              style: GoogleFonts.inter(
                fontSize: 11,
                color: AppColors.textDisabled,
                fontWeight: FontWeight.w500,
              ),
            ),
            const Gap(24),
          ],
        ),
      );

  Widget _buildDropdown(
    String label,
    String value,
    List<String> items,
    ValueChanged<String?> onChanged,
  ) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: GoogleFonts.inter(
            fontSize: 13,
            fontWeight: FontWeight.w600,
            color: AppColors.textSecondary,
          ),
        ),
        const Gap(6),
        Container(
          decoration: BoxDecoration(
            color: AppColors.surface1,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: AppColors.border),
          ),
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: DropdownButton<String>(
            value: value,
            isExpanded: true,
            underline: const SizedBox(),
            icon: const Icon(
              Icons.keyboard_arrow_down_rounded,
              color: AppColors.textSecondary,
            ),
            style: GoogleFonts.inter(
              fontSize: 15,
              fontWeight: FontWeight.w500,
              color: AppColors.textPrimary,
            ),
            onChanged: onChanged,
            items: items
                .map((e) => DropdownMenuItem(value: e, child: Text(e)))
                .toList(),
          ),
        ),
      ],
    );
  }
}

// ── Sede card (fija) ────────────────────────────────────────────────────────
class _SedeCard extends StatelessWidget {
  final String sede;
  const _SedeCard({required this.sede});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
        boxShadow: [
          BoxShadow(
            color: AppColors.dark.withValues(alpha: 0.04),
            blurRadius: 10,
            offset: const Offset(0, 3),
          ),
        ],
      ),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: AppColors.primary.withValues(alpha: 0.12),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Padding(
              padding: const EdgeInsets.all(6),
              child: Lottie.asset(
                AppAssets.lottieGym,
                repeat: true,
                fit: BoxFit.contain,
              ),
            ),
          ),
          const Gap(12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Tu sede',
                  style: GoogleFonts.inter(
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textSecondary,
                  ),
                ),
                const Gap(2),
                Text(
                  sede,
                  style: GoogleFonts.lexend(
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
              ],
            ),
          ),
          Container(
            padding:
                const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
              color: AppColors.primary.withValues(alpha: 0.14),
              borderRadius: BorderRadius.circular(99),
            ),
            child: Text(
              'Asignada',
              style: GoogleFonts.inter(
                fontSize: 10,
                fontWeight: FontWeight.w700,
                color: AppColors.primary,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

// ── Consent block ───────────────────────────────────────────────────────────
class _ConsentBlock extends StatelessWidget {
  final bool terms;
  final bool bio;
  final ValueChanged<bool> onTerms;
  final ValueChanged<bool> onBio;

  const _ConsentBlock({
    required this.terms,
    required this.bio,
    required this.onTerms,
    required this.onBio,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 12),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        children: [
          _ConsentRow(
            value: terms,
            onChanged: onTerms,
            child: Text.rich(
              TextSpan(
                children: [
                  TextSpan(
                    text: 'Acepto los ',
                    style: GoogleFonts.inter(
                      fontSize: 13,
                      color: AppColors.textSecondary,
                    ),
                  ),
                  TextSpan(
                    text: 'Términos y condiciones',
                    style: GoogleFonts.inter(
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: AppColors.textPrimary,
                    ),
                  ),
                  TextSpan(
                    text: ' y el tratamiento de datos personales.',
                    style: GoogleFonts.inter(
                      fontSize: 13,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ],
              ),
            ),
          ),
          const Divider(height: 1, color: AppColors.border),
          _ConsentRow(
            value: bio,
            onChanged: onBio,
            child: Text.rich(
              TextSpan(
                children: [
                  TextSpan(
                    text: 'Autorizo el ',
                    style: GoogleFonts.inter(
                      fontSize: 13,
                      color: AppColors.textSecondary,
                    ),
                  ),
                  TextSpan(
                    text: 'tratamiento de mis datos biométricos',
                    style: GoogleFonts.inter(
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: AppColors.textPrimary,
                    ),
                  ),
                  TextSpan(
                    text:
                        ' para verificación de identidad y control de acceso al gimnasio.',
                    style: GoogleFonts.inter(
                      fontSize: 13,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _BiometricUsageNote extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: AppColors.primary.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.primary.withValues(alpha: 0.25)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(
            Icons.shield_outlined,
            size: 16,
            color: AppColors.primary,
          ),
          const Gap(8),
          Expanded(
            child: Text(
              'Tu captura facial será usada únicamente para verificación de identidad y control de acceso, y será procesada de forma segura.',
              style: GoogleFonts.inter(
                fontSize: 11,
                fontWeight: FontWeight.w500,
                color: AppColors.textSecondary,
                height: 1.4,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ConsentRow extends StatelessWidget {
  final bool value;
  final ValueChanged<bool> onChanged;
  final Widget child;

  const _ConsentRow({
    required this.value,
    required this.onChanged,
    required this.child,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: () => onChanged(!value),
      borderRadius: BorderRadius.circular(10),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Checkbox(
              value: value,
              onChanged: (v) => onChanged(v ?? false),
              activeColor: AppColors.primary,
              side: const BorderSide(color: AppColors.border),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(4),
              ),
              materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
              visualDensity: VisualDensity.compact,
            ),
            const Gap(6),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.only(top: 10),
                child: child,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ── Verification card with state machine ────────────────────────────────────
class _FaceVerificationCard extends StatelessWidget {
  final FaceCapture? capture;
  final bool processing;
  final bool consentGiven;
  final String? error;
  final VoidCallback onCapture;

  const _FaceVerificationCard({
    required this.capture,
    required this.processing,
    required this.consentGiven,
    required this.error,
    required this.onCapture,
  });

  bool get _verified => capture?.isVerified == true;
  bool get _hasError => error != null;

  @override
  Widget build(BuildContext context) {
    final accent = _hasError
        ? const Color(0xFFD32F2F)
        : _verified
            ? const Color(0xFF22C55E)
            : null;

    return Container(
      padding: const EdgeInsets.fromLTRB(18, 18, 18, 18),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(
          color: accent?.withValues(alpha: 0.45) ?? AppColors.border,
          width: accent != null ? 1.4 : 1,
        ),
        boxShadow: [
          BoxShadow(
            color: AppColors.dark.withValues(alpha: 0.05),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header
          Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: AppColors.primary.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Padding(
                  padding: const EdgeInsets.all(4),
                  child: Lottie.asset(
                    AppAssets.lottieEvaluacion,
                    repeat: true,
                    fit: BoxFit.contain,
                  ),
                ),
              ),
              const Gap(10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Verificación facial',
                      style: GoogleFonts.lexend(
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                    ),
                    const Gap(2),
                    Text(
                      _verified
                          ? 'Identidad confirmada · Encriptado en envío'
                          : _hasError
                              ? 'Algo salió mal con tu captura'
                              : 'Toma una foto en vivo para validar tu identidad',
                      style: GoogleFonts.inter(
                        fontSize: 11,
                        color: AppColors.textSecondary,
                      ),
                    ),
                  ],
                ),
              ),
              _StatusChip(
                state: _verified
                    ? _VerificationState.verified
                    : _hasError
                        ? _VerificationState.error
                        : processing
                            ? _VerificationState.capturing
                            : _VerificationState.pending,
              ),
            ],
          ),
          const Gap(16),

          // Content
          Center(
            child: _verified
                ? _CaptureThumbnail(file: File(capture!.localPath))
                : _hasError
                    ? const _ErrorIllustration()
                    : const _PendingIllustration(),
          ),
          if (_hasError) ...[
            const Gap(8),
            Center(
              child: Text(
                error!,
                textAlign: TextAlign.center,
                style: GoogleFonts.inter(
                  fontSize: 12,
                  color: const Color(0xFFD32F2F),
                ),
              ),
            ),
          ],
          if (_verified) ...[
            const Gap(8),
            const Center(child: _PrivacyTag()),
          ],
          const Gap(16),

          // Action button
          if (_verified)
            _GhostButton(
              icon: Icons.refresh_rounded,
              label: 'Repetir captura',
              onPressed: processing ? null : onCapture,
            )
          else
            _PrimaryDarkButton(
              icon: processing
                  ? null
                  : (_hasError
                      ? Icons.refresh_rounded
                      : Icons.camera_alt_rounded),
              label: processing
                  ? 'Abriendo cámara…'
                  : (_hasError
                      ? 'Reintentar captura'
                      : 'Iniciar verificación facial'),
              onPressed:
                  (!consentGiven || processing) ? null : onCapture,
              loading: processing,
            ),

          if (!_verified && !consentGiven) ...[
            const Gap(10),
            Row(
              children: [
                const Icon(
                  Icons.lock_outline_rounded,
                  size: 14,
                  color: AppColors.textDisabled,
                ),
                const Gap(6),
                Expanded(
                  child: Text(
                    'Acepta los consentimientos para habilitar la captura.',
                    style: GoogleFonts.inter(
                      fontSize: 11,
                      color: AppColors.textDisabled,
                    ),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

enum _VerificationState { pending, capturing, verified, error }

class _StatusChip extends StatelessWidget {
  final _VerificationState state;
  const _StatusChip({required this.state});

  @override
  Widget build(BuildContext context) {
    late Color bg;
    late Color fg;
    late String label;
    switch (state) {
      case _VerificationState.pending:
        bg = AppColors.surfaceContainerLow;
        fg = AppColors.textSecondary;
        label = 'Pendiente';
        break;
      case _VerificationState.capturing:
        bg = AppColors.primary.withValues(alpha: 0.18);
        fg = AppColors.primary;
        label = 'Capturando';
        break;
      case _VerificationState.verified:
        bg = const Color(0xFF22C55E).withValues(alpha: 0.14);
        fg = const Color(0xFF15803D);
        label = 'Verificado';
        break;
      case _VerificationState.error:
        bg = const Color(0xFFD32F2F).withValues(alpha: 0.14);
        fg = const Color(0xFFD32F2F);
        label = 'Error';
        break;
    }
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(99),
      ),
      child: Text(
        label,
        style: GoogleFonts.inter(
          fontSize: 10,
          fontWeight: FontWeight.w700,
          color: fg,
        ),
      ),
    );
  }
}

class _PendingIllustration extends StatelessWidget {
  const _PendingIllustration();

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        SizedBox(
          width: 72,
          height: 72,
          child: Lottie.asset(
            AppAssets.lottieUser,
            repeat: true,
            fit: BoxFit.contain,
          ),
        ),
        const Gap(8),
        Text(
          'Sin captura aún',
          style: GoogleFonts.lexend(
            fontSize: 13,
            fontWeight: FontWeight.w700,
            color: AppColors.textSecondary,
          ),
        ),
        const Gap(2),
        Text(
          'Centra tu rostro y mantén buena iluminación',
          textAlign: TextAlign.center,
          style: GoogleFonts.inter(
            fontSize: 11,
            color: AppColors.textDisabled,
          ),
        ),
      ],
    );
  }
}

class _ErrorIllustration extends StatelessWidget {
  const _ErrorIllustration();

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 64,
          height: 64,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: const Color(0xFFD32F2F).withValues(alpha: 0.12),
          ),
          child: const Icon(
            Icons.error_outline_rounded,
            color: Color(0xFFD32F2F),
            size: 32,
          ),
        ),
        const Gap(8),
        Text(
          'Captura fallida',
          style: GoogleFonts.lexend(
            fontSize: 13,
            fontWeight: FontWeight.w700,
            color: AppColors.textPrimary,
          ),
        ),
      ],
    );
  }
}

class _CaptureThumbnail extends StatelessWidget {
  final File file;
  const _CaptureThumbnail({required this.file});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: 112,
      height: 112,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          Container(
            width: 112,
            height: 112,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              border: Border.all(
                color: const Color(0xFF22C55E),
                width: 2.5,
              ),
              boxShadow: [
                BoxShadow(
                  color: const Color(0xFF22C55E).withValues(alpha: 0.18),
                  blurRadius: 14,
                  spreadRadius: 1,
                ),
              ],
            ),
            padding: const EdgeInsets.all(4),
            child: ClipOval(
              child: ColorFiltered(
                colorFilter: ColorFilter.mode(
                  AppColors.dark.withValues(alpha: 0.06),
                  BlendMode.darken,
                ),
                child: Image.file(
                  file,
                  fit: BoxFit.cover,
                  cacheWidth: 224,
                  gaplessPlayback: true,
                  errorBuilder: (_, _, _) => Container(
                    color: AppColors.surface1,
                    alignment: Alignment.center,
                    child: const Icon(
                      Icons.broken_image_outlined,
                      color: AppColors.textDisabled,
                    ),
                  ),
                ),
              ),
            ),
          ),
          Positioned(
            right: -2,
            bottom: -2,
            child: Container(
              width: 34,
              height: 34,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.surface0,
                boxShadow: [
                  BoxShadow(
                    color: AppColors.dark.withValues(alpha: 0.18),
                    blurRadius: 6,
                    offset: const Offset(0, 2),
                  ),
                ],
              ),
              padding: const EdgeInsets.all(3),
              child: Lottie.asset(
                AppAssets.lottieCheckGreen,
                repeat: false,
                fit: BoxFit.contain,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _PrivacyTag extends StatelessWidget {
  const _PrivacyTag();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLow,
        borderRadius: BorderRadius.circular(99),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(
            Icons.lock_rounded,
            size: 12,
            color: AppColors.textSecondary,
          ),
          const Gap(5),
          Text(
            'Almacenamiento privado · Sin galería pública',
            style: GoogleFonts.inter(
              fontSize: 10,
              fontWeight: FontWeight.w600,
              color: AppColors.textSecondary,
            ),
          ),
        ],
      ),
    );
  }
}

class _PrimaryDarkButton extends StatelessWidget {
  final String label;
  final IconData? icon;
  final VoidCallback? onPressed;
  final bool loading;

  const _PrimaryDarkButton({
    required this.label,
    required this.onPressed,
    this.icon,
    this.loading = false,
  });

  @override
  Widget build(BuildContext context) {
    final disabled = onPressed == null;
    return Opacity(
      opacity: disabled ? 0.55 : 1,
      child: SizedBox(
        height: 48,
        width: double.infinity,
        child: Material(
          color: AppColors.dark,
          borderRadius: BorderRadius.circular(14),
          child: InkWell(
            borderRadius: BorderRadius.circular(14),
            onTap: onPressed,
            child: Center(
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  if (loading)
                    const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        valueColor:
                            AlwaysStoppedAnimation<Color>(AppColors.primary),
                      ),
                    )
                  else if (icon != null)
                    Icon(icon, size: 18, color: AppColors.primary),
                  const Gap(8),
                  Flexible(
                    child: Text(
                      label,
                      overflow: TextOverflow.ellipsis,
                      style: GoogleFonts.lexend(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: AppColors.onDark,
                        letterSpacing: 0.4,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _GhostButton extends StatelessWidget {
  final String label;
  final IconData icon;
  final VoidCallback? onPressed;

  const _GhostButton({
    required this.label,
    required this.icon,
    required this.onPressed,
  });

  @override
  Widget build(BuildContext context) {
    final disabled = onPressed == null;
    return Opacity(
      opacity: disabled ? 0.55 : 1,
      child: SizedBox(
        height: 44,
        width: double.infinity,
        child: Material(
          color: AppColors.surface1,
          borderRadius: BorderRadius.circular(12),
          child: InkWell(
            borderRadius: BorderRadius.circular(12),
            onTap: onPressed,
            child: Center(
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(icon, size: 16, color: AppColors.textPrimary),
                  const Gap(6),
                  Text(
                    label,
                    style: GoogleFonts.lexend(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: AppColors.textPrimary,
                      letterSpacing: 0.4,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
