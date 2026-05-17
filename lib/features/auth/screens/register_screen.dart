import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:intl/intl.dart';
import 'package:lottie/lottie.dart';

import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/user_model.dart';
import '../../../shared/widgets/auth_background.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../../shared/widgets/iron_input.dart';
import '../../memberships/screens/memberships_screen.dart';
import '../models/guardian_info.dart';
import '../models/identity_document.dart';
import '../models/legal_consent.dart';
import '../services/face_verification_service.dart';
import '../services/identity_verification_service.dart';
import '../services/legal_contract_service.dart';
import '../services/signature_service.dart';
import '../widgets/consent_checkbox_group.dart';
import '../widgets/contract_section_card.dart';
import '../widgets/document_capture_card.dart';
import '../widgets/guardian_form.dart';
import '../widgets/signature_pad_section.dart';
import 'document_capture_screen.dart';
import 'face_capture_screen.dart';

/// Flujo de "Crear cuenta" — 6 pasos:
///  1. Datos personales
///  2. Datos físicos / preferencias (sin peso ni estatura)
///  3. Validación de identidad (documento frente/reverso + OCR + edad)
///  4. Contrato y autorización (+ responsable legal si es menor)
///  5. Firma / soporte legal
///  6. Verificación facial → continúa al flujo de membresía existente
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  static const int _stepCount = 6;
  final _pageController = PageController();
  int _step = 0;
  bool _completed = false;

  // ── Paso 1: datos personales ────────────────────────────────────────────
  final _docCtrl = TextEditingController();
  final _nameCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  String _gender = 'Masculino';

  // ── Paso 2: preferencias ────────────────────────────────────────────────
  String _goal = 'Hipertrofia muscular';
  String _level = 'Principiante';
  final _injuriesCtrl = TextEditingController();

  // ── Paso 3: identidad ───────────────────────────────────────────────────
  IdentityDocument _identity = const IdentityDocument();
  bool _docProcessingConsent = false;
  bool _busyFront = false;
  bool _busyBack = false;
  bool _analyzing = false;
  String? _identityError;
  OcrResult? _ocrFront;
  OcrResult? _ocrBack;

  // ── Paso 4: contrato y autorización ─────────────────────────────────────
  ContractAcceptance _acceptance = const ContractAcceptance();
  GuardianInfo _guardian = const GuardianInfo();
  final _gNameCtrl = TextEditingController();
  final _gDocCtrl = TextEditingController();
  final _gPhoneCtrl = TextEditingController();
  final _gEmailCtrl = TextEditingController();
  String _gRelationship = '';

  // ── Paso 5: firma ───────────────────────────────────────────────────────
  SignatureSupport _signature = const SignatureSupport();

  // ── Paso 6: verificación facial ─────────────────────────────────────────
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
    'Preferencias',
    'Validación de identidad',
    'Contrato y autorización',
    'Firma / soporte legal',
    'Verificación facial',
  ];

  bool get _isMinor => _identity.isMinor ?? false;
  bool get _bioConsentGiven => _bioConsent;
  bool get _faceReady => _bioConsentGiven && _faceCapture?.isVerified == true;

  // ── Navegación entre pasos ──────────────────────────────────────────────
  bool _canAdvanceFrom(int step) {
    switch (step) {
      case 0:
        return _nameCtrl.text.trim().isNotEmpty &&
            _docCtrl.text.trim().isNotEmpty;
      case 1:
        return true;
      case 2:
        return _docProcessingConsent &&
            _identity.hasBothImages &&
            _identity.effectiveBirthDate != null;
      case 3:
        final base = _acceptance.isComplete(isMinor: _isMinor);
        return _isMinor ? base && _guardianComplete : base;
      case 4:
        return _signature.isAttached;
      case 5:
        return _faceReady && !_submitting;
      default:
        return false;
    }
  }

  bool get _guardianComplete => GuardianInfo(
        fullName: _gNameCtrl.text,
        documentNumber: _gDocCtrl.text,
        phone: _gPhoneCtrl.text,
        email: _gEmailCtrl.text,
        relationship: _gRelationship,
        acceptsResponsibility: _guardian.acceptsResponsibility,
      ).isComplete;

  void _next() {
    if (!_canAdvanceFrom(_step)) {
      _showSnack(_blockMessageFor(_step));
      return;
    }
    if (_step < _stepCount - 1) {
      setState(() => _step++);
      _pageController.animateToPage(
        _step,
        duration: 380.ms,
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
        duration: 380.ms,
        curve: Curves.easeOutCubic,
      );
    } else {
      Navigator.pop(context);
    }
  }

  String _blockMessageFor(int step) {
    switch (step) {
      case 0:
        return 'Ingresa al menos tu nombre y documento.';
      case 2:
        if (!_docProcessingConsent) {
          return 'Autoriza el procesamiento de las imágenes de tu documento para continuar.';
        }
        return 'Captura el documento por ambos lados y confirma tu fecha de nacimiento.';
      case 3:
        return _isMinor
            ? 'Acepta el contrato y completa los datos del responsable legal.'
            : 'Debes aceptar el contrato, los términos y el tratamiento de datos.';
      case 4:
        return 'Adjunta tu firma o un documento firmado para continuar.';
      case 5:
        return 'Completa la verificación facial y el consentimiento biométrico.';
      default:
        return 'Completa la información requerida para continuar.';
    }
  }

  // ── Paso 3: captura y OCR (frente + reverso, combinados) ────────────────
  Future<void> _onSidePicked(String sourcePath, {required bool isFront}) async {
    setState(() {
      if (isFront) {
        _busyFront = true;
      } else {
        _busyBack = true;
      }
      _identityError = null;
    });
    try {
      final saved = await IdentityVerificationService.instance
          .persistImage(sourcePath, side: isFront ? 'front' : 'back');
      if (!mounted) return;
      setState(() {
        _identity = isFront
            ? _identity.copyWith(
                frontImagePath: saved, status: IdentityStatus.analyzing)
            : _identity.copyWith(
                backImagePath: saved, status: IdentityStatus.analyzing);
        _analyzing = true;
      });
      // OCR de esta cara (tolerante a fallos).
      OcrResult ocr;
      try {
        ocr = await IdentityVerificationService.instance.analyzeImage(saved);
      } catch (_) {
        ocr = const OcrResult(confidence: 0);
      }
      if (!mounted) return;
      if (isFront) {
        _ocrFront = ocr;
      } else {
        _ocrBack = ocr;
      }
      final merged = _mergedOcr();
      setState(() {
        _identity = _identity.copyWith(ocr: merged, status: _statusFor(merged));
        if (isFront) {
          _busyFront = false;
        } else {
          _busyBack = false;
        }
        _analyzing = false;
      });
    } on IdentityVerificationException catch (e) {
      if (!mounted) return;
      setState(() {
        _identityError = e.message;
        _busyFront = false;
        _busyBack = false;
        _analyzing = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _identityError = 'No se pudo procesar la imagen. Inténtalo de nuevo.';
        _busyFront = false;
        _busyBack = false;
        _analyzing = false;
      });
    }
  }

  OcrResult _mergedOcr() => IdentityVerificationService.instance.mergeOcr(
        _ocrFront ?? const OcrResult(confidence: 0),
        _ocrBack ?? const OcrResult(confidence: 0),
      );

  /// Estado del documento. Si la fecha fue ingresada/confirmada manualmente, NO
  /// se marca como validado automáticamente: queda en "revisión manual" salvo
  /// que el OCR ya hubiera leído la fecha con alta confianza.
  IdentityStatus _statusFor(OcrResult merged) {
    if (_identity.confirmedBirthDate != null && !merged.isHighConfidence) {
      return IdentityStatus.needsManualReview;
    }
    return IdentityVerificationService.instance.statusFromOcr(merged);
  }

  Future<void> _retryOcr() async {
    if (_analyzing) return;
    setState(() {
      _analyzing = true;
      _identity = _identity.copyWith(status: IdentityStatus.analyzing);
    });
    OcrResult f = const OcrResult(confidence: 0);
    OcrResult b = const OcrResult(confidence: 0);
    try {
      final fp = _identity.frontImagePath;
      if (fp != null) f = await IdentityVerificationService.instance.analyzeImage(fp);
    } catch (_) {/* ignore */}
    try {
      final bp = _identity.backImagePath;
      if (bp != null) b = await IdentityVerificationService.instance.analyzeImage(bp);
    } catch (_) {/* ignore */}
    if (!mounted) return;
    _ocrFront = f;
    _ocrBack = b;
    final merged = _mergedOcr();
    setState(() {
      _identity = _identity.copyWith(ocr: merged, status: _statusFor(merged));
      _analyzing = false;
    });
  }

  Future<void> _pickBirthDateManually() async {
    final now = DateTime.now();
    final initial = _identity.effectiveBirthDate ?? DateTime(now.year - 20);
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(1900),
      lastDate: now,
      helpText: 'Fecha de nacimiento',
    );
    if (picked == null) return;
    setState(() {
      _identity = _identity.copyWith(
        confirmedBirthDate: picked,
        status: _identity.ocr.isHighConfidence
            ? IdentityStatus.verified
            : IdentityStatus.needsManualReview,
      );
    });
  }

  // ── Paso 6: verificación facial (flujo existente) ───────────────────────
  Future<void> _captureFace() async {
    if (!_bioConsentGiven || _processingCapture) return;
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
      final previous = _faceCapture;
      final capture =
          await FaceVerificationService.instance.persistCapture(tempPath);
      if (previous != null) {
        await FaceVerificationService.instance.dispose(previous);
      }
      if (!mounted) return;
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
    if (!_faceReady) {
      _showSnack(_blockMessageFor(5));
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
      weight: 0,
      height: 0,
      planName: 'Sin plan activo',
      membershipExpiry: DateTime.now(),
      avatarUrl: _faceCapture!.localPath,
    );

    try {
      // 1) Identidad → backend (mock).
      await IdentityVerificationService.instance
          .submitToBackend(_identity, userId: pendingId);

      // 2) Consentimiento legal + firma → backend (mock).
      final consent = LegalConsent(
        acceptance: _acceptance,
        signature: _signature,
        acceptedAt: DateTime.now(),
      );
      await LegalContractService.instance.submitConsent(
        consent,
        userId: pendingId,
        guardian: _isMinor ? _guardianInfoForSubmit() : null,
      );
      await SignatureService.instance
          .submitToBackend(_signature, userId: pendingId);

      // 3) Captura facial → backend (mock).
      final registered = await FaceVerificationService.instance
          .submitToBackend(_faceCapture!, userId: pendingId);
      if (!mounted) return;
      setState(() {
        _faceCapture = registered;
        _completed = true;
      });

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
        _faceError = 'No se pudo enviar el registro. Inténtalo de nuevo.';
      });
      _showSnack(_faceError!);
    }
  }

  GuardianInfo _guardianInfoForSubmit() => GuardianInfo(
        fullName: _gNameCtrl.text.trim(),
        documentNumber: _gDocCtrl.text.trim(),
        phone: _gPhoneCtrl.text.trim(),
        email: _gEmailCtrl.text.trim(),
        relationship: _gRelationship,
        acceptsResponsibility: _guardian.acceptsResponsibility,
      );

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
    _injuriesCtrl.dispose();
    _gNameCtrl.dispose();
    _gDocCtrl.dispose();
    _gPhoneCtrl.dispose();
    _gEmailCtrl.dispose();
    // Limpieza de archivos sensibles si el registro no se completó.
    if (!_completed) {
      IdentityVerificationService.instance.disposeImages(_identity);
      SignatureService.instance.disposeSignature(_signature);
    }
    super.dispose();
  }

  // ── Build ───────────────────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    final isFinal = _step == _stepCount - 1;
    final canContinue = _canAdvanceFrom(_step);

    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: AppBar(
        backgroundColor: AppColors.surface0,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_ios_new_rounded,
              size: 20, color: AppColors.textPrimary),
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
                  const EdgeInsets.symmetric(vertical: 18, horizontal: 24),
              child: Column(
                children: [
                  Row(
                    children: List.generate(
                      _stepCount,
                      (i) => Expanded(
                        child: Container(
                          height: 4,
                          margin:
                              EdgeInsets.only(right: i < _stepCount - 1 ? 5 : 0),
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
                        'Paso ${_step + 1} de $_stepCount',
                        style: GoogleFonts.lexend(
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textSecondary,
                        ),
                      ),
                      Flexible(
                        child: Text(
                          _stepTitles[_step],
                          textAlign: TextAlign.right,
                          overflow: TextOverflow.ellipsis,
                          style: GoogleFonts.inter(
                            fontSize: 12,
                            color: AppColors.textSecondary,
                          ),
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
                children: [
                  _buildStepPersonal(),
                  _buildStepPreferences(),
                  _buildStepIdentity(),
                  _buildStepContract(),
                  _buildStepSignature(),
                  _buildStepFace(),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 8, 24, 28),
              child: Opacity(
                opacity: canContinue && !_submitting ? 1 : 0.55,
                child: IronButton(
                  label: _submitting
                      ? 'PROCESANDO...'
                      : isFinal
                          ? 'FINALIZAR Y CONTINUAR A MEMBRESÍA'
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

  // ── Paso 1 ──────────────────────────────────────────────────────────────
  Widget _buildStepPersonal() => SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 24),
        child: Column(
          children: [
            IronInput(
              label: 'Documento',
              hint: 'Número de documento',
              controller: _docCtrl,
              prefixLottie: AppAssets.lottieDocumento,
              keyboardType: TextInputType.number,
              onChanged: (_) => setState(() {}),
            ),
            const Gap(16),
            IronInput(
              label: 'Nombre completo',
              hint: 'Ej: Juan Pérez García',
              controller: _nameCtrl,
              prefixLottie: AppAssets.lottieUser,
              onChanged: (_) => setState(() {}),
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

  // ── Paso 2 ──────────────────────────────────────────────────────────────
  Widget _buildStepPreferences() => SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _InfoNote(
              icon: Icons.tips_and_updates_outlined,
              text:
                  'Estos datos nos ayudan a personalizar tu experiencia. No '
                  'necesitas saberlos con exactitud; puedes ajustarlos luego.',
            ),
            const Gap(16),
            _buildDropdown(
                'Objetivo físico', _goal, _goals, (v) => setState(() => _goal = v!)),
            const Gap(16),
            _buildDropdown('Nivel de experiencia', _level, _levels,
                (v) => setState(() => _level = v!)),
            const Gap(16),
            IronInput(
              label: 'Lesiones o restricciones',
              hint: 'Opcional. Ej: molestia en rodilla derecha…',
              controller: _injuriesCtrl,
              maxLines: 3,
            ),
            const Gap(24),
          ],
        ),
      );

  // ── Paso 3 ──────────────────────────────────────────────────────────────
  Widget _buildStepIdentity() => SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _InfoNote(
              icon: Icons.privacy_tip_outlined,
              text:
                  'Necesitamos validar tu identidad y tu edad. Las imágenes se '
                  'usan solo para esto y se envían de forma segura al servidor.',
            ),
            const Gap(16),
            _DocConsentRow(
              value: _docProcessingConsent,
              onChanged: (v) => setState(() => _docProcessingConsent = v),
            ),
            const Gap(14),
            DocumentSideCapture(
              side: DocumentSide.front,
              imagePath: _identity.frontImagePath,
              busy: _busyFront,
              enabled: _docProcessingConsent,
              onPicked: (p) => _onSidePicked(p, isFront: true),
            ),
            const Gap(14),
            DocumentSideCapture(
              side: DocumentSide.back,
              imagePath: _identity.backImagePath,
              busy: _busyBack,
              enabled: _docProcessingConsent,
              onPicked: (p) => _onSidePicked(p, isFront: false),
            ),
            if (_identityError != null) ...[
              const Gap(10),
              Text(_identityError!,
                  style: GoogleFonts.inter(
                      fontSize: 12, color: AppColors.error)),
            ],
            const Gap(16),
            _IdentityResultCard(
              identity: _identity,
              analyzing: _analyzing,
              onEditBirthDate: _pickBirthDateManually,
              onRetryOcr:
                  (_identity.frontImagePath != null || _identity.backImagePath != null) &&
                          !_analyzing
                      ? _retryOcr
                      : null,
            ),
            const Gap(20),
            Text(
              'Las imágenes de tu documento se almacenan cifradas en el servidor '
              'y solo se usan para verificar tu identidad. Los documentos con '
              'lectura incompleta pueden requerir revisión manual del gimnasio.',
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

  // ── Paso 4 ──────────────────────────────────────────────────────────────
  Widget _buildStepContract() {
    final minor = _isMinor;
    return SingleChildScrollView(
      padding: const EdgeInsets.symmetric(horizontal: 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _InfoNote(
            icon: Icons.gavel_rounded,
            text: minor
                ? 'Como eres menor de edad, tu registro debe ser autorizado por '
                    'tu responsable legal. Lee el contrato y los términos antes '
                    'de aceptar.'
                : 'Lee el contrato, los términos y el tratamiento de datos. Marca '
                    'cada casilla para confirmar tu aceptación.',
          ),
          const Gap(16),
          ContractSectionCard(
            title: LegalTexts.contractTitle,
            body: LegalTexts.contractBody,
            icon: Icons.assignment_outlined,
            initiallyExpanded: true,
          ),
          const Gap(10),
          ContractSectionCard(
            title: LegalTexts.termsTitle,
            body: LegalTexts.termsBody,
            icon: Icons.rule_rounded,
          ),
          const Gap(10),
          ContractSectionCard(
            title: LegalTexts.dataTitle,
            body: LegalTexts.dataBody,
            icon: Icons.shield_outlined,
          ),
          const Gap(10),
          ContractSectionCard(
            title: LegalTexts.riskTitle,
            body: LegalTexts.riskBody,
            icon: Icons.fitness_center_rounded,
          ),
          if (minor) ...[
            const Gap(10),
            ContractSectionCard(
              title: LegalTexts.guardianTitle,
              body: LegalTexts.guardianBody,
              icon: Icons.family_restroom_rounded,
            ),
          ],
          const Gap(16),
          ConsentCheckboxGroup(
            items: [
              ConsentItem(
                key: 'terms',
                value: _acceptance.termsAndConditions,
                onChanged: (v) => setState(() => _acceptance =
                    _acceptance.copyWith(termsAndConditions: v)),
                label: const Text('Acepto los términos y condiciones.'),
              ),
              ConsentItem(
                key: 'data',
                value: _acceptance.dataProcessing,
                onChanged: (v) => setState(() =>
                    _acceptance = _acceptance.copyWith(dataProcessing: v)),
                label: const Text(
                    'Acepto el tratamiento de mis datos personales.'),
              ),
              ConsentItem(
                key: 'truth',
                value: _acceptance.truthfulness,
                onChanged: (v) => setState(() =>
                    _acceptance = _acceptance.copyWith(truthfulness: v)),
                label: const Text(
                    'Declaro que la información suministrada es verídica.'),
              ),
              ConsentItem(
                key: 'contract',
                value: _acceptance.serviceContract,
                onChanged: (v) => setState(() =>
                    _acceptance = _acceptance.copyWith(serviceContract: v)),
                label: const Text(
                    'Acepto el contrato de prestación de servicios del gimnasio.'),
              ),
              ConsentItem(
                key: 'risk',
                value: _acceptance.physicalRiskWaiver,
                onChanged: (v) => setState(() => _acceptance =
                    _acceptance.copyWith(physicalRiskWaiver: v)),
                label: const Text(
                    'Reconozco y acepto los riesgos de la actividad física.'),
              ),
              if (minor)
                ConsentItem(
                  key: 'guardian',
                  value: _acceptance.guardianAuthorization,
                  onChanged: (v) => setState(() => _acceptance =
                      _acceptance.copyWith(guardianAuthorization: v)),
                  label: const Text(
                      'El responsable legal acepta y autoriza el registro del menor.'),
                ),
            ],
          ),
          if (minor) ...[
            const Gap(16),
            GuardianForm(
              nameCtrl: _gNameCtrl,
              documentCtrl: _gDocCtrl,
              phoneCtrl: _gPhoneCtrl,
              emailCtrl: _gEmailCtrl,
              relationship: _gRelationship,
              onRelationshipChanged: (v) => setState(() => _gRelationship = v),
              acceptsResponsibility: _guardian.acceptsResponsibility,
              onAcceptsChanged: (v) => setState(
                  () => _guardian = _guardian.copyWith(acceptsResponsibility: v)),
              onAnyChanged: () => setState(() {}),
            ),
          ],
          const Gap(14),
          Text(
            'Textos de carácter informativo y editables. La versión definitiva del '
            'contrato y las autorizaciones debe ser revisada por el área legal '
            'del gimnasio.',
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
  }

  // ── Paso 5 ──────────────────────────────────────────────────────────────
  Widget _buildStepSignature() => SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _InfoNote(
              icon: Icons.draw_outlined,
              text:
                  'Anexa tu firma. Puedes firmar directamente en pantalla o subir '
                  'una foto/PDF del contrato firmado.',
            ),
            const Gap(16),
            SignaturePadSection(
              current: _signature,
              onAttached: (s) => setState(() => _signature = s),
              onCleared: () => setState(() => _signature = const SignatureSupport()),
            ),
            const Gap(16),
            Text(
              'Tu firma se guarda de forma privada en el dispositivo y se envía '
              'cifrada al servidor; no se almacena como texto ni se publica.',
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

  // ── Paso 6 ──────────────────────────────────────────────────────────────
  Widget _buildStepFace() => SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const _SedeCard(sede: 'Sede Sur'),
            const Gap(16),
            _BioConsentRow(
              value: _bioConsent,
              onChanged: (v) => setState(() => _bioConsent = v),
            ),
            const Gap(8),
            const _BiometricUsageNote(),
            const Gap(16),
            _FaceVerificationCard(
              capture: _faceCapture,
              processing: _processingCapture,
              consentGiven: _bioConsentGiven,
              error: _faceError,
              onCapture: _captureFace,
            ),
            const Gap(20),
            Text(
              'La captura se almacena cifrada en el servidor y solo se usa para '
              'verificar tu identidad y controlar el acceso al gimnasio.',
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

  // ── Helpers UI ──────────────────────────────────────────────────────────
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
            icon: const Icon(Icons.keyboard_arrow_down_rounded,
                color: AppColors.textSecondary),
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

// ════════════════════════════════════════════════════════════════════════════
// Widgets de apoyo
// ════════════════════════════════════════════════════════════════════════════

/// Casilla de consentimiento para procesar las imágenes del documento. Debe
/// marcarse antes de habilitar la captura (paso de validación de identidad).
class _DocConsentRow extends StatelessWidget {
  final bool value;
  final ValueChanged<bool> onChanged;
  const _DocConsentRow({required this.value, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: value
              ? AppColors.primary.withValues(alpha: 0.4)
              : AppColors.border,
        ),
      ),
      child: InkWell(
        onTap: () => onChanged(!value),
        borderRadius: BorderRadius.circular(10),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Checkbox(
                value: value,
                onChanged: (v) => onChanged(v ?? false),
                activeColor: AppColors.primary,
                checkColor: AppColors.onPrimary,
                side: const BorderSide(color: AppColors.border),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(4)),
                materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                visualDensity: VisualDensity.compact,
              ),
              const Gap(8),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.only(top: 9),
                  child: Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: 'Autorizo el ',
                          style: GoogleFonts.inter(
                              fontSize: 13, color: AppColors.textSecondary),
                        ),
                        TextSpan(
                          text: 'procesamiento de las imágenes de mi documento',
                          style: GoogleFonts.inter(
                              fontSize: 13,
                              fontWeight: FontWeight.w700,
                              color: AppColors.textPrimary),
                        ),
                        TextSpan(
                          text:
                              ' para verificar mi identidad y mi edad. Se envían cifradas y no se publican.',
                          style: GoogleFonts.inter(
                              fontSize: 13, color: AppColors.textSecondary),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _InfoNote extends StatelessWidget {
  final IconData icon;
  final String text;
  const _InfoNote({required this.icon, required this.text});

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
          Icon(icon, size: 16, color: AppColors.textPrimary),
          const Gap(8),
          Expanded(
            child: Text(
              text,
              style: GoogleFonts.inter(
                fontSize: 11.5,
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

/// Tarjeta de resultado de la validación de identidad (OCR + edad).
class _IdentityResultCard extends StatelessWidget {
  final IdentityDocument identity;
  final bool analyzing;
  final VoidCallback onEditBirthDate;
  final VoidCallback? onRetryOcr;

  const _IdentityResultCard({
    required this.identity,
    required this.analyzing,
    required this.onEditBirthDate,
    required this.onRetryOcr,
  });

  @override
  Widget build(BuildContext context) {
    final hasImages = identity.hasBothImages;
    final dob = identity.effectiveBirthDate;
    final age = identity.age;
    final minor = identity.isMinor;

    final (chipLabel, chipColor) = _statusChip();

    return Container(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  color: AppColors.primary.withValues(alpha: 0.14),
                  borderRadius: BorderRadius.circular(9),
                ),
                child: const Icon(Icons.verified_user_outlined,
                    size: 18, color: AppColors.textPrimary),
              ),
              const Gap(10),
              Expanded(
                child: Text(
                  'Resultado de la validación',
                  style: GoogleFonts.lexend(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
              ),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
                decoration: BoxDecoration(
                  color: chipColor.withValues(alpha: 0.16),
                  borderRadius: BorderRadius.circular(99),
                ),
                child: Text(
                  chipLabel,
                  style: GoogleFonts.inter(
                    fontSize: 10,
                    fontWeight: FontWeight.w700,
                    color: chipColor == AppColors.primary
                        ? AppColors.textPrimary
                        : chipColor,
                  ),
                ),
              ),
            ],
          ),
          const Gap(12),
          if (!hasImages)
            Text(
              'Carga el documento por ambos lados para iniciar la validación.',
              style: GoogleFonts.inter(
                  fontSize: 12.5, color: AppColors.textSecondary),
            )
          else if (analyzing)
            Row(
              children: const [
                SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(
                      strokeWidth: 2,
                      valueColor:
                          AlwaysStoppedAnimation<Color>(AppColors.primary)),
                ),
                Gap(10),
                Text('Analizando documento…'),
              ],
            )
          else ...[
            if (identity.ocr.documentType != null)
              _row('Tipo de documento', identity.ocr.documentType!),
            if (identity.ocr.documentNumber != null)
              _row('Número detectado',
                  _maskNumber(identity.ocr.documentNumber!)),
            _row(
              'Fecha de nacimiento',
              dob != null
                  ? DateFormat('dd/MM/yyyy').format(dob)
                  : 'No detectada',
            ),
            if (age != null) _row('Edad', '$age años'),
            const Gap(10),
            if (minor == true)
              _badge('Menor de edad detectado · requiere responsable legal',
                  AppColors.dark, AppColors.onDark)
            else if (minor == false)
              _badge('Mayor de edad detectado', AppColors.primary,
                  AppColors.textPrimary)
            else
              _badge('Edad por confirmar — ingresa tu fecha de nacimiento',
                  AppColors.surfaceContainer, AppColors.textSecondary),
            const Gap(12),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: onEditBirthDate,
                    icon: const Icon(Icons.event_rounded, size: 16),
                    label: Text(dob == null
                        ? 'Ingresar fecha de nacimiento'
                        : 'Corregir fecha'),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: AppColors.textPrimary,
                      side: const BorderSide(color: AppColors.border),
                      padding: const EdgeInsets.symmetric(vertical: 11),
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12)),
                    ),
                  ),
                ),
                if (onRetryOcr != null) ...[
                  const Gap(8),
                  IconButton(
                    onPressed: onRetryOcr,
                    tooltip: 'Reintentar lectura',
                    icon: const Icon(Icons.refresh_rounded),
                    color: AppColors.textSecondary,
                  ),
                ],
              ],
            ),
            if (identity.status == IdentityStatus.needsManualReview) ...[
              const Gap(8),
              Text(
                'No pudimos leer todos los datos automáticamente. Confirma tu '
                'fecha de nacimiento; el gimnasio revisará el documento.',
                style: GoogleFonts.inter(
                  fontSize: 11.5,
                  color: AppColors.textSecondary,
                  height: 1.35,
                ),
              ),
            ],
          ],
        ],
      ),
    );
  }

  (String, Color) _statusChip() {
    switch (identity.status) {
      case IdentityStatus.pending:
      case IdentityStatus.uploadingFront:
      case IdentityStatus.uploadingBack:
        return ('Pendiente', AppColors.textSecondary);
      case IdentityStatus.analyzing:
        return ('Analizando', AppColors.primary);
      case IdentityStatus.verified:
        return ('Validado', AppColors.primary);
      case IdentityStatus.needsManualReview:
        return ('Revisión manual', AppColors.textSecondary);
      case IdentityStatus.failed:
        return ('Error', AppColors.error);
    }
  }

  Widget _row(String k, String v) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 130,
              child: Text(k,
                  style: GoogleFonts.inter(
                      fontSize: 12, color: AppColors.textSecondary)),
            ),
            Expanded(
              child: Text(v,
                  style: GoogleFonts.inter(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w600,
                      color: AppColors.textPrimary)),
            ),
          ],
        ),
      );

  Widget _badge(String label, Color bg, Color fg) => Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(vertical: 9, horizontal: 12),
        decoration: BoxDecoration(
          color: bg == AppColors.primary
              ? AppColors.primary.withValues(alpha: 0.16)
              : bg,
          borderRadius: BorderRadius.circular(10),
        ),
        child: Text(
          label,
          textAlign: TextAlign.center,
          style: GoogleFonts.inter(
            fontSize: 12,
            fontWeight: FontWeight.w700,
            color: fg,
          ),
        ),
      );

  // Enmascara el número de documento: solo se muestran los últimos 3 dígitos.
  static String _maskNumber(String n) {
    if (n.length <= 3) return '•••';
    return '${'•' * (n.length - 3)}${n.substring(n.length - 3)}';
  }
}

class _BioConsentRow extends StatelessWidget {
  final bool value;
  final ValueChanged<bool> onChanged;
  const _BioConsentRow({required this.value, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
      ),
      child: InkWell(
        onTap: () => onChanged(!value),
        borderRadius: BorderRadius.circular(10),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Checkbox(
                value: value,
                onChanged: (v) => onChanged(v ?? false),
                activeColor: AppColors.primary,
                checkColor: AppColors.onPrimary,
                side: const BorderSide(color: AppColors.border),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(4)),
                materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                visualDensity: VisualDensity.compact,
              ),
              const Gap(8),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.only(top: 9),
                  child: Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: 'Autorizo el ',
                          style: GoogleFonts.inter(
                              fontSize: 13, color: AppColors.textSecondary),
                        ),
                        TextSpan(
                          text: 'tratamiento de mis datos biométricos',
                          style: GoogleFonts.inter(
                              fontSize: 13,
                              fontWeight: FontWeight.w700,
                              color: AppColors.textPrimary),
                        ),
                        TextSpan(
                          text:
                              ' para verificación de identidad y control de acceso al gimnasio.',
                          style: GoogleFonts.inter(
                              fontSize: 13, color: AppColors.textSecondary),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
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
              child: Lottie.asset(AppAssets.lottieGym,
                  repeat: true, fit: BoxFit.contain),
            ),
          ),
          const Gap(12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Tu sede',
                    style: GoogleFonts.inter(
                        fontSize: 11,
                        fontWeight: FontWeight.w600,
                        color: AppColors.textSecondary)),
                const Gap(2),
                Text(sede,
                    style: GoogleFonts.lexend(
                        fontSize: 16,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary)),
              ],
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
              color: AppColors.primary.withValues(alpha: 0.14),
              borderRadius: BorderRadius.circular(99),
            ),
            child: Text('Asignada',
                style: GoogleFonts.inter(
                    fontSize: 10,
                    fontWeight: FontWeight.w700,
                    color: AppColors.primary)),
          ),
        ],
      ),
    );
  }
}

class _BiometricUsageNote extends StatelessWidget {
  const _BiometricUsageNote();

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
          const Icon(Icons.shield_outlined, size: 16, color: AppColors.primary),
          const Gap(8),
          Expanded(
            child: Text(
              'Tu captura facial será usada únicamente para verificación de '
              'identidad y control de acceso, y será procesada de forma segura.',
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
        ? AppColors.error
        : _verified
            ? AppColors.primary
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
                  child: Lottie.asset(AppAssets.lottieEvaluacion,
                      repeat: true, fit: BoxFit.contain),
                ),
              ),
              const Gap(10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Verificación facial',
                        style: GoogleFonts.lexend(
                            fontSize: 14,
                            fontWeight: FontWeight.w700,
                            color: AppColors.textPrimary)),
                    const Gap(2),
                    Text(
                      _verified
                          ? 'Identidad confirmada · Encriptado en envío'
                          : _hasError
                              ? 'Algo salió mal con tu captura'
                              : 'Toma una foto en vivo para validar tu identidad',
                      style: GoogleFonts.inter(
                          fontSize: 11, color: AppColors.textSecondary),
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
              child: Text(error!,
                  textAlign: TextAlign.center,
                  style: GoogleFonts.inter(
                      fontSize: 12, color: AppColors.error)),
            ),
          ],
          if (_verified) ...[
            const Gap(8),
            const Center(child: _PrivacyTag()),
          ],
          const Gap(16),
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
              onPressed: (!consentGiven || processing) ? null : onCapture,
              loading: processing,
            ),
          if (!_verified && !consentGiven) ...[
            const Gap(10),
            Row(
              children: [
                const Icon(Icons.lock_outline_rounded,
                    size: 14, color: AppColors.textDisabled),
                const Gap(6),
                Expanded(
                  child: Text(
                    'Acepta el consentimiento biométrico para habilitar la captura.',
                    style: GoogleFonts.inter(
                        fontSize: 11, color: AppColors.textDisabled),
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
        fg = AppColors.textPrimary;
        label = 'Capturando';
        break;
      case _VerificationState.verified:
        bg = AppColors.primary.withValues(alpha: 0.18);
        fg = AppColors.textPrimary;
        label = 'Verificado';
        break;
      case _VerificationState.error:
        bg = AppColors.error.withValues(alpha: 0.14);
        fg = AppColors.error;
        label = 'Error';
        break;
    }
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration:
          BoxDecoration(color: bg, borderRadius: BorderRadius.circular(99)),
      child: Text(label,
          style: GoogleFonts.inter(
              fontSize: 10, fontWeight: FontWeight.w700, color: fg)),
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
          child: Lottie.asset(AppAssets.lottieUser,
              repeat: true, fit: BoxFit.contain),
        ),
        const Gap(8),
        Text('Sin captura aún',
            style: GoogleFonts.lexend(
                fontSize: 13,
                fontWeight: FontWeight.w700,
                color: AppColors.textSecondary)),
        const Gap(2),
        Text('Centra tu rostro y mantén buena iluminación',
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(
                fontSize: 11, color: AppColors.textDisabled)),
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
            color: AppColors.error.withValues(alpha: 0.12),
          ),
          child: const Icon(Icons.error_outline_rounded,
              color: AppColors.error, size: 32),
        ),
        const Gap(8),
        Text('Captura fallida',
            style: GoogleFonts.lexend(
                fontSize: 13,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary)),
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
              border: Border.all(color: AppColors.primary, width: 2.5),
              boxShadow: [
                BoxShadow(
                  color: AppColors.primary.withValues(alpha: 0.18),
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
                    child: const Icon(Icons.broken_image_outlined,
                        color: AppColors.textDisabled),
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
              child: Lottie.asset(AppAssets.lottieCheckGreen,
                  repeat: false, fit: BoxFit.contain),
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
          const Icon(Icons.lock_rounded, size: 12, color: AppColors.textSecondary),
          const Gap(5),
          Text('Almacenamiento privado · Sin galería pública',
              style: GoogleFonts.inter(
                  fontSize: 10,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textSecondary)),
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
                              AlwaysStoppedAnimation<Color>(AppColors.primary)),
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
                  Text(label,
                      style: GoogleFonts.lexend(
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                          letterSpacing: 0.4)),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
