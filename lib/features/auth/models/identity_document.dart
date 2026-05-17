import 'package:flutter/foundation.dart';

/// Edad mínima para ser considerado mayor de edad en el flujo legal.
/// Centralizada aquí para que ajustarla sea trivial (no quemada en la UI).
const int legalAdultAge = 18;

/// Estado del proceso de validación de identidad por documento.
enum IdentityStatus {
  pending,        // sin imágenes aún
  uploadingFront, // cargando frente
  uploadingBack,  // cargando reverso
  analyzing,      // ejecutando OCR
  verified,       // OCR ok, fecha de nacimiento detectada
  needsManualReview, // OCR incompleto o baja confianza → usuario ingresa datos
  failed,         // error duro
}

/// Resultado parcial de la lectura OCR del documento. Ningún campo es
/// garantizado: el OCR puede fallar o entregar datos parciales.
@immutable
class OcrResult {
  final String? documentNumber;
  final String? fullName;
  final DateTime? birthDate;
  final String? documentType; // CC, TI, CE, PASAPORTE… si se logra inferir
  final double confidence;    // 0..1, heurística simple

  const OcrResult({
    this.documentNumber,
    this.fullName,
    this.birthDate,
    this.documentType,
    this.confidence = 0,
  });

  bool get hasBirthDate => birthDate != null;
  bool get isHighConfidence => confidence >= 0.6 && hasBirthDate;

  OcrResult copyWith({
    String? documentNumber,
    String? fullName,
    DateTime? birthDate,
    String? documentType,
    double? confidence,
  }) {
    return OcrResult(
      documentNumber: documentNumber ?? this.documentNumber,
      fullName: fullName ?? this.fullName,
      birthDate: birthDate ?? this.birthDate,
      documentType: documentType ?? this.documentType,
      confidence: confidence ?? this.confidence,
    );
  }
}

/// Agregado del documento de identidad cargado por el usuario.
/// Las imágenes se guardan en almacenamiento privado de la app; el envío al
/// backend debe hacerse por multipart y el cifrado/retención corresponde al
/// servidor (ver [IdentityVerificationService]).
@immutable
class IdentityDocument {
  final String? frontImagePath;
  final String? backImagePath;
  final OcrResult ocr;
  final IdentityStatus status;
  /// Fecha de nacimiento confirmada (OCR o ingreso manual).
  final DateTime? confirmedBirthDate;

  const IdentityDocument({
    this.frontImagePath,
    this.backImagePath,
    this.ocr = const OcrResult(),
    this.status = IdentityStatus.pending,
    this.confirmedBirthDate,
  });

  bool get hasBothImages =>
      frontImagePath != null && backImagePath != null;

  DateTime? get effectiveBirthDate => confirmedBirthDate ?? ocr.birthDate;

  int? get age {
    final dob = effectiveBirthDate;
    if (dob == null) return null;
    final now = DateTime.now();
    var years = now.year - dob.year;
    if (now.month < dob.month ||
        (now.month == dob.month && now.day < dob.day)) {
      years--;
    }
    return years < 0 ? null : years;
  }

  bool? get isMinor {
    final a = age;
    if (a == null) return null;
    return a < legalAdultAge;
  }

  /// Listo para avanzar del paso de identidad: ambas imágenes + fecha conocida.
  bool get isResolved => hasBothImages && effectiveBirthDate != null;

  IdentityDocument copyWith({
    String? frontImagePath,
    String? backImagePath,
    OcrResult? ocr,
    IdentityStatus? status,
    DateTime? confirmedBirthDate,
    bool clearConfirmedBirthDate = false,
  }) {
    return IdentityDocument(
      frontImagePath: frontImagePath ?? this.frontImagePath,
      backImagePath: backImagePath ?? this.backImagePath,
      ocr: ocr ?? this.ocr,
      status: status ?? this.status,
      confirmedBirthDate: clearConfirmedBirthDate
          ? null
          : (confirmedBirthDate ?? this.confirmedBirthDate),
    );
  }
}
