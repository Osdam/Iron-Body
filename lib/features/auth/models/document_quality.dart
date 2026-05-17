import 'package:flutter/foundation.dart';

/// Problemas que la validación de calidad puede detectar en la foto del
/// documento. No todos bloquean: algunos son solo advertencias.
enum DocumentQualityIssue {
  blurry,            // imagen borrosa / movida
  tooDark,           // poca iluminación
  tooBright,         // sobreexpuesta
  lowContrast,       // texto poco legible
  documentTooFar,    // el documento ocupa poca parte de la imagen
  edgesNotVisible,   // no se distinguen bien los bordes del documento
  tilted,            // documento muy inclinado
  cropped,           // el documento parece cortado
  glare,             // reflejo fuerte
  notDecodable,      // no se pudo leer la imagen
}

extension DocumentQualityIssueX on DocumentQualityIssue {
  /// ¿Este problema impide continuar? Los demás son advertencias suaves.
  bool get isBlocking => switch (this) {
        DocumentQualityIssue.blurry => true,
        DocumentQualityIssue.tooDark => true,
        DocumentQualityIssue.tooBright => true,
        DocumentQualityIssue.documentTooFar => true,
        DocumentQualityIssue.notDecodable => true,
        DocumentQualityIssue.lowContrast => false,
        DocumentQualityIssue.edgesNotVisible => false,
        DocumentQualityIssue.tilted => false,
        DocumentQualityIssue.cropped => false,
        DocumentQualityIssue.glare => false,
      };

  String get message => switch (this) {
        DocumentQualityIssue.blurry =>
          'La imagen está borrosa. Mantén el pulso firme e inténtalo de nuevo.',
        DocumentQualityIssue.tooDark =>
          'Falta iluminación. Busca un lugar más iluminado.',
        DocumentQualityIssue.tooBright =>
          'La imagen está sobreexpuesta. Evita la luz directa.',
        DocumentQualityIssue.lowContrast =>
          'El texto se ve poco legible. Mejora la iluminación y el enfoque.',
        DocumentQualityIssue.documentTooFar =>
          'El documento está muy lejos. Acércalo hasta llenar el recuadro.',
        DocumentQualityIssue.edgesNotVisible =>
          'Asegúrate de que se vean los cuatro bordes del documento.',
        DocumentQualityIssue.tilted =>
          'El documento quedó muy torcido. Apóyalo más plano e inténtalo de nuevo.',
        DocumentQualityIssue.cropped =>
          'El documento parece cortado. Ubícalo completo dentro del recuadro.',
        DocumentQualityIssue.glare =>
          'Hay un reflejo fuerte sobre el documento. Cambia el ángulo.',
        DocumentQualityIssue.notDecodable =>
          'No pudimos procesar la imagen. Toma la foto de nuevo.',
      };
}

@immutable
class DocumentQualityResult {
  /// true si la imagen es aceptable para continuar (sin problemas bloqueantes).
  final bool ok;
  final List<DocumentQualityIssue> issues;

  // Métricas crudas (útiles para depuración / ajustes; no se imprimen).
  final double brightness; // 0..255 (luminancia media)
  final double sharpness;  // varianza laplaciana normalizada
  final double contrast;   // desviación estándar de luminancia
  final double fillRatio;  // 0..1 — proporción de la imagen ocupada por el documento

  const DocumentQualityResult({
    required this.ok,
    required this.issues,
    this.brightness = 0,
    this.sharpness = 0,
    this.contrast = 0,
    this.fillRatio = 0,
  });

  factory DocumentQualityResult.notDecodable() => const DocumentQualityResult(
        ok: false,
        issues: [DocumentQualityIssue.notDecodable],
      );

  List<DocumentQualityIssue> get blockingIssues =>
      issues.where((i) => i.isBlocking).toList();

  List<DocumentQualityIssue> get warnings =>
      issues.where((i) => !i.isBlocking).toList();

  /// Mensaje principal a mostrar al usuario (el primer problema bloqueante, o
  /// la primera advertencia, o un mensaje de éxito).
  String get primaryMessage {
    if (blockingIssues.isNotEmpty) return blockingIssues.first.message;
    if (warnings.isNotEmpty) return warnings.first.message;
    return 'La imagen se ve bien.';
  }
}
