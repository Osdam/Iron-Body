import 'dart:io';

import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:path_provider/path_provider.dart';

import '../models/identity_document.dart';
import 'date_extractor.dart';
import 'document_signals.dart';

class IdentityVerificationException implements Exception {
  final String message;
  const IdentityVerificationException(this.message);
  @override
  String toString() => 'IdentityVerificationException: $message';
}

/// Capa responsable de:
///  - mover las fotos del documento a almacenamiento privado de la app;
///  - ejecutar OCR sobre la imagen frontal para intentar extraer número de
///    documento, nombre, fecha de nacimiento y tipo de documento;
///  - preparar el envío al backend.
///
/// El backend NO está implementado todavía. [submitToBackend] simula un envío
/// exitoso. Cuando exista el endpoint, reemplazar el cuerpo por un POST
/// multipart/form-data autenticado; el servidor debe cifrar y custodiar las
/// imágenes y nunca exponerlas en respuestas públicas.
///
/// Seguridad: este servicio NUNCA debe imprimir el número de documento, el
/// texto OCR completo ni la imagen en consola.
class IdentityVerificationService {
  IdentityVerificationService._();
  static final IdentityVerificationService instance =
      IdentityVerificationService._();

  static const _privateDirName = 'identity';

  Future<Directory> _privateDir() async {
    final docs = await getApplicationDocumentsDirectory();
    final dir = Directory('${docs.path}/$_privateDirName');
    if (!await dir.exists()) await dir.create(recursive: true);
    return dir;
  }

  /// Copia la imagen capturada/seleccionada a almacenamiento privado y devuelve
  /// la ruta resultante. [side] solo se usa para el nombre del archivo.
  Future<String> persistImage(String sourcePath, {required String side}) async {
    final src = File(sourcePath);
    if (!await src.exists()) {
      throw const IdentityVerificationException(
          'La imagen seleccionada no está disponible.');
    }
    final dir = await _privateDir();
    final ext = sourcePath.split('.').last.toLowerCase();
    final safeExt =
        (ext == 'png' || ext == 'jpg' || ext == 'jpeg') ? ext : 'jpg';
    final dest =
        '${dir.path}/doc_${side}_${DateTime.now().millisecondsSinceEpoch}.$safeExt';
    final saved = await src.copy(dest);
    return saved.path;
  }

  /// OCR sobre la imagen frontal. Alias de [analyzeImage] por compatibilidad.
  Future<OcrResult> analyzeFront(String frontImagePath) =>
      analyzeImage(frontImagePath);

  /// Ejecuta OCR sobre una imagen del documento y devuelve los datos extraídos.
  /// Tolerante a fallos: si ML Kit no está disponible o no se logra leer la
  /// fecha de nacimiento, devuelve un [OcrResult] parcial con baja confianza
  /// para que la UI ofrezca ingreso manual / revisión.
  Future<OcrResult> analyzeImage(String imagePath) async {
    final text = await _recognizeText(imagePath);
    if (text == null) return const OcrResult(confidence: 0);
    return _parse(text);
  }

  /// ¿La imagen parece realmente un documento de identidad? Usa el mismo
  /// criterio compartido que la detección en vivo ([DocumentSignals]) para
  /// evitar aceptar objetos cualquiera (p. ej. un teclado) — útil sobre todo
  /// cuando la imagen viene de la galería y no pasó por la guía de la cámara.
  /// Si el OCR no está disponible, no bloquea (devuelve true).
  Future<bool> looksLikeDocument(String imagePath) async {
    final text = await _recognizeText(imagePath);
    if (text == null) return true; // sin OCR no podemos juzgar → no bloquear
    return DocumentSignals.isLikelyDocument(text);
  }

  Future<String?> _recognizeText(String imagePath) async {
    try {
      final recognizer = TextRecognizer(script: TextRecognitionScript.latin);
      final recognized =
          await recognizer.processImage(InputImage.fromFilePath(imagePath));
      await recognizer.close();
      return recognized.text;
    } catch (_) {
      return null; // OCR no disponible (sin Google Play Services / web, etc.)
    }
  }

  /// Combina el OCR del frente y del reverso priorizando los campos detectados.
  /// La fecha de nacimiento que ya esté presente se conserva; si aparece en
  /// ambos, se prefiere la del lado con mayor confianza.
  OcrResult mergeOcr(OcrResult front, OcrResult back) {
    final preferA = front.confidence >= back.confidence;
    final hi = preferA ? front : back;
    final lo = preferA ? back : front;
    return _recompute(OcrResult(
      documentNumber: hi.documentNumber ?? lo.documentNumber,
      fullName: hi.fullName ?? lo.fullName,
      birthDate: hi.birthDate ?? lo.birthDate,
      documentType: hi.documentType ?? lo.documentType,
    ));
  }

  // ── Parsing heurístico (no jurídico, mejorable) ─────────────────────────────

  OcrResult _parse(String text) {
    if (text.trim().isEmpty) return const OcrResult(confidence: 0);
    final upper = text.toUpperCase();

    // Tipo de documento (Colombia y genéricos).
    String? docType;
    if (RegExp(r'TARJETA\s+DE\s+IDENTIDAD').hasMatch(upper) ||
        RegExp(r'\bT\.?\s?I\.?\b').hasMatch(upper)) {
      docType = 'TI';
    } else if (RegExp(r'C[ÉE]DULA\s+DE\s+CIUDADAN[ÍI]A').hasMatch(upper) ||
        RegExp(r'\bC\.?\s?C\.?\b').hasMatch(upper)) {
      docType = 'CC';
    } else if (RegExp(r'C[ÉE]DULA\s+DE\s+EXTRANJER[ÍI]A').hasMatch(upper) ||
        RegExp(r'\bC\.?\s?E\.?\b').hasMatch(upper)) {
      docType = 'CE';
    } else if (upper.contains('PASAPORTE') || upper.contains('PASSPORT')) {
      docType = 'PASAPORTE';
    }

    // Fecha de nacimiento (parser robusto, con anclaje a etiquetas y limpieza OCR).
    final birthDate = DateExtractor.extractBirthDate(text);

    // Número de documento: secuencia larga de dígitos (puntos/espacios opcionales).
    String? docNumber;
    for (final m in RegExp(r'(?<!\d)[\d][\d.\s]{5,16}[\d](?!\d)').allMatches(text)) {
      final digits = m.group(0)!.replaceAll(RegExp(r'[.\s]'), '');
      if (digits.length >= 6 && digits.length <= 12) {
        docNumber = digits;
        break;
      }
    }

    // Nombre: línea más "alfabética" y plausible (heurística simple).
    String? fullName;
    for (final line in text.split('\n')) {
      final l = line.trim();
      if (l.length < 6 || l.length > 42) continue;
      final letters = RegExp(r'[A-Za-zÁÉÍÓÚÑáéíóúñ ]').allMatches(l).length;
      if (letters / l.length > 0.85 && l.split(RegExp(r'\s+')).length >= 2) {
        fullName ??= l;
      }
    }

    return _recompute(OcrResult(
      documentNumber: docNumber,
      fullName: fullName,
      birthDate: birthDate,
      documentType: docType,
    ));
  }

  /// Recalcula la confianza heurística en función de qué campos se detectaron.
  /// La fecha de nacimiento pesa lo suficiente para superar el umbral de
  /// [OcrResult.isHighConfidence] (0.6) por sí sola.
  OcrResult _recompute(OcrResult r) {
    var c = 0.15; // hubo texto / algún campo
    if (r.documentType != null) c += 0.10;
    if (r.documentNumber != null) c += 0.10;
    if (r.fullName != null) c += 0.05;
    if (r.birthDate != null) c += 0.50;
    return r.copyWith(confidence: c.clamp(0, 1));
  }

  /// Decide el estado del documento a partir del OCR. No bloquea al usuario:
  /// si la confianza es baja, devuelve [IdentityStatus.needsManualReview].
  IdentityStatus statusFromOcr(OcrResult ocr) {
    if (ocr.isHighConfidence) return IdentityStatus.verified;
    return IdentityStatus.needsManualReview;
  }

  /// Envía el documento + datos al backend. Reemplazar cuando exista el
  /// endpoint. El backend debe: exigir sesión autenticada, cifrar en reposo,
  /// nunca devolver las imágenes en endpoints públicos, y validar manualmente
  /// los documentos marcados como [IdentityStatus.needsManualReview].
  Future<void> submitToBackend(
    IdentityDocument doc, {
    required String userId,
  }) async {
    // TODO(backend): POST multipart/form-data
    //   uri: ${baseUrl}/identity/verify
    //   fields: { userId, documentType, birthDate, documentNumber }
    //   files:  { 'front': <frontImagePath>, 'back': <backImagePath> }
    //   headers: { 'Authorization': 'Bearer <session-token>' }
    await Future<void>.delayed(const Duration(milliseconds: 250));
  }

  /// Borra las imágenes locales. Llamar al abandonar el registro sin completarlo
  /// o tras confirmar el envío al backend.
  Future<void> disposeImages(IdentityDocument? doc) async {
    if (doc == null) return;
    for (final path in [doc.frontImagePath, doc.backImagePath]) {
      if (path == null) continue;
      final f = File(path);
      if (await f.exists()) {
        try {
          await f.delete();
        } catch (_) {/* ignore */}
      }
    }
  }
}
