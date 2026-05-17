import 'dart:io';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:image/image.dart' as img;

import '../models/document_quality.dart';

/// Analiza la foto de un documento y decide si tiene calidad suficiente para
/// continuar (nitidez, iluminación, encuadre, bordes, etc.).
///
/// Es una validación HEURÍSTICA, no un escáner de documentos profesional: busca
/// un equilibrio entre rechazar fotos claramente malas y no molestar al usuario
/// con falsos negativos. Nunca imprime la imagen ni su contenido.
class DocumentQualityService {
  DocumentQualityService._();
  static final DocumentQualityService instance = DocumentQualityService._();

  /// Analiza la imagen en [imagePath]. Corre el procesamiento de píxeles en un
  /// isolate (`compute`) para no bloquear la UI.
  Future<DocumentQualityResult> analyze(String imagePath) async {
    try {
      final bytes = await File(imagePath).readAsBytes();
      return await compute(_analyzeBytes, bytes);
    } catch (_) {
      return DocumentQualityResult.notDecodable();
    }
  }
}

// ── Implementación (top-level para poder usarse con compute) ─────────────────

// Umbrales (muy tolerantes: solo rechazan lo claramente malo).
const double _kMaxDim = 720; // se redimensiona el lado mayor a esto
const double _kDarkBrightness = 38;       // bloqueante: solo imagen claramente oscura
const double _kBrightBrightness = 236;    // bloqueante: solo sobreexposición fuerte
const double _kLowContrastStd = 13;        // solo advertencia
const double _kBlurLaplacianVar = 22;     // bloqueante: solo lo claramente borroso
const double _kMinFillRatio = 0.14;       // bloqueante: solo si está muy lejos
const double _kGlareFraction = 0.07;      // solo advertencia

DocumentQualityResult _analyzeBytes(Uint8List bytes) {
  final decoded = img.decodeImage(bytes);
  if (decoded == null) return DocumentQualityResult.notDecodable();

  // Redimensionar para acelerar el análisis manteniendo proporción.
  final img.Image im;
  if (decoded.width >= decoded.height) {
    im = decoded.width > _kMaxDim
        ? img.copyResize(decoded, width: _kMaxDim.round())
        : decoded;
  } else {
    im = decoded.height > _kMaxDim
        ? img.copyResize(decoded, height: _kMaxDim.round())
        : decoded;
  }

  final w = im.width;
  final h = im.height;
  if (w < 40 || h < 40) {
    return const DocumentQualityResult(
      ok: false,
      issues: [DocumentQualityIssue.documentTooFar],
    );
  }

  // Luminancia (0..255) por píxel.
  final lum = Float32List(w * h);
  var sum = 0.0;
  for (var y = 0; y < h; y++) {
    for (var x = 0; x < w; x++) {
      final p = im.getPixel(x, y);
      // Rec. 601 luma.
      final l = 0.299 * p.r + 0.587 * p.g + 0.114 * p.b;
      lum[y * w + x] = l;
      sum += l;
    }
  }
  final mean = sum / (w * h);

  // Desviación estándar (contraste global).
  var varSum = 0.0;
  for (var i = 0; i < lum.length; i++) {
    final d = lum[i] - mean;
    varSum += d * d;
  }
  final std = math.sqrt(varSum / lum.length);

  // Varianza del laplaciano (nitidez): muestreamos píxeles interiores.
  var lapSum = 0.0;
  var lapSqSum = 0.0;
  var lapN = 0;
  for (var y = 1; y < h - 1; y++) {
    for (var x = 1; x < w - 1; x++) {
      final c = lum[y * w + x];
      final lap = 4 * c -
          lum[(y - 1) * w + x] -
          lum[(y + 1) * w + x] -
          lum[y * w + x - 1] -
          lum[y * w + x + 1];
      lapSum += lap;
      lapSqSum += lap * lap;
      lapN++;
    }
  }
  final lapMean = lapN == 0 ? 0.0 : lapSum / lapN;
  final lapVar = lapN == 0 ? 0.0 : (lapSqSum / lapN) - (lapMean * lapMean);

  // ── Detección aproximada del documento ────────────────────────────────────
  // Suposición: el borde de la imagen es "fondo". Marcamos como documento los
  // píxeles cuya luminancia se aparta del fondo.
  final borderMean = _borderMean(lum, w, h);
  const delta = 22.0;
  // Conteo de píxeles "documento" por fila y por columna.
  final rowCount = Int32List(h);
  final colCount = Int32List(w);
  var fgTotal = 0;
  var glareInside = 0;
  for (var y = 0; y < h; y++) {
    for (var x = 0; x < w; x++) {
      final l = lum[y * w + x];
      if ((l - borderMean).abs() > delta) {
        rowCount[y]++;
        colCount[x]++;
        fgTotal++;
        if (l > 250) glareInside++;
      }
    }
  }
  final fgFraction = fgTotal / (w * h);

  // Bounding box: una fila/columna "cuenta" si >15% de sus píxeles son documento.
  final rowThresh = (w * 0.15).round();
  final colThresh = (h * 0.15).round();
  var top = -1, bottom = -1, left = -1, right = -1;
  for (var y = 0; y < h; y++) {
    if (rowCount[y] > rowThresh) {
      top = top == -1 ? y : top;
      bottom = y;
    }
  }
  for (var x = 0; x < w; x++) {
    if (colCount[x] > colThresh) {
      left = left == -1 ? x : left;
      right = x;
    }
  }

  final detectionReliable = fgFraction > 0.05 && top != -1 && left != -1;
  double fillRatio = 0;
  var touchesAllEdges = false;
  var touchedEdges = 0;
  if (detectionReliable) {
    final bw = (right - left + 1).toDouble();
    final bh = (bottom - top + 1).toDouble();
    fillRatio = (bw * bh) / (w * h);
    final eps = 0.02;
    final tTop = top <= h * eps;
    final tBot = bottom >= h * (1 - eps);
    final tLeft = left <= w * eps;
    final tRight = right >= w * (1 - eps);
    touchedEdges = [tTop, tBot, tLeft, tRight].where((b) => b).length;
    touchesAllEdges = touchedEdges == 4;
  }

  // ── Reglas ────────────────────────────────────────────────────────────────
  // (No se evalúa inclinación/alineación: la perspectiva normal de cámara de
  // mano es aceptable; nunca se bloquea ni se advierte por ángulo.)
  final issues = <DocumentQualityIssue>[];

  if (mean < _kDarkBrightness) issues.add(DocumentQualityIssue.tooDark);
  if (mean > _kBrightBrightness) issues.add(DocumentQualityIssue.tooBright);
  if (lapVar < _kBlurLaplacianVar) issues.add(DocumentQualityIssue.blurry);
  if (std < _kLowContrastStd && !issues.contains(DocumentQualityIssue.tooDark)) {
    issues.add(DocumentQualityIssue.lowContrast);
  }

  if (detectionReliable) {
    if (fillRatio < _kMinFillRatio) {
      issues.add(DocumentQualityIssue.documentTooFar);
    } else if (touchesAllEdges && fillRatio > 0.985) {
      issues.add(DocumentQualityIssue.cropped);
    } else if (touchedEdges == 3) {
      issues.add(DocumentQualityIssue.edgesNotVisible);
    }
    if (fgTotal > 0 && glareInside / fgTotal > _kGlareFraction) {
      issues.add(DocumentQualityIssue.glare);
    }
  }

  final blocking = issues.any((i) => i.isBlocking);
  return DocumentQualityResult(
    ok: !blocking,
    issues: issues,
    brightness: mean,
    sharpness: lapVar,
    contrast: std,
    fillRatio: fillRatio,
  );
}

double _borderMean(Float32List lum, int w, int h) {
  final bx = math.max(1, (w * 0.05).round());
  final by = math.max(1, (h * 0.05).round());
  var s = 0.0;
  var n = 0;
  for (var y = 0; y < h; y++) {
    for (var x = 0; x < w; x++) {
      if (x < bx || x >= w - bx || y < by || y >= h - by) {
        s += lum[y * w + x];
        n++;
      }
    }
  }
  return n == 0 ? 0 : s / n;
}

