import 'package:flutter/foundation.dart';

/// Tipo de soporte de firma anexado por el usuario.
enum SignatureKind { none, drawn, uploadedImage, uploadedPdf }

/// Conjunto de aceptaciones legales que el usuario (o su acudiente) marca en el
/// paso "Contrato y autorización".
@immutable
class ContractAcceptance {
  final bool termsAndConditions;
  final bool dataProcessing;        // tratamiento de datos personales
  final bool truthfulness;          // declara que la info es verídica
  final bool serviceContract;       // contrato de prestación de servicios
  final bool physicalRiskWaiver;    // exoneración/aceptación de riesgos
  final bool guardianAuthorization; // solo aplica si es menor

  const ContractAcceptance({
    this.termsAndConditions = false,
    this.dataProcessing = false,
    this.truthfulness = false,
    this.serviceContract = false,
    this.physicalRiskWaiver = false,
    this.guardianAuthorization = false,
  });

  /// ¿Están aceptadas todas las casillas obligatorias para este usuario?
  /// [isMinor] decide si la autorización del acudiente es obligatoria.
  bool isComplete({required bool isMinor}) {
    final base = termsAndConditions &&
        dataProcessing &&
        truthfulness &&
        serviceContract &&
        physicalRiskWaiver;
    if (isMinor) return base && guardianAuthorization;
    return base;
  }

  ContractAcceptance copyWith({
    bool? termsAndConditions,
    bool? dataProcessing,
    bool? truthfulness,
    bool? serviceContract,
    bool? physicalRiskWaiver,
    bool? guardianAuthorization,
  }) {
    return ContractAcceptance(
      termsAndConditions: termsAndConditions ?? this.termsAndConditions,
      dataProcessing: dataProcessing ?? this.dataProcessing,
      truthfulness: truthfulness ?? this.truthfulness,
      serviceContract: serviceContract ?? this.serviceContract,
      physicalRiskWaiver: physicalRiskWaiver ?? this.physicalRiskWaiver,
      guardianAuthorization:
          guardianAuthorization ?? this.guardianAuthorization,
    );
  }
}

/// Firma o documento firmado anexado por el usuario.
@immutable
class SignatureSupport {
  final SignatureKind kind;
  final String? filePath; // ruta privada al PNG dibujado o al archivo subido

  const SignatureSupport({this.kind = SignatureKind.none, this.filePath});

  bool get isAttached => kind != SignatureKind.none && filePath != null;

  String get label => switch (kind) {
        SignatureKind.none => 'Sin firma',
        SignatureKind.drawn => 'Firma digital adjuntada',
        SignatureKind.uploadedImage => 'Documento firmado (imagen) adjuntado',
        SignatureKind.uploadedPdf => 'Documento firmado (PDF) adjuntado',
      };
}

/// Snapshot completo del consentimiento legal, listo para enviar al backend.
@immutable
class LegalConsent {
  final ContractAcceptance acceptance;
  final SignatureSupport signature;
  final DateTime acceptedAt;

  const LegalConsent({
    required this.acceptance,
    required this.signature,
    required this.acceptedAt,
  });
}
