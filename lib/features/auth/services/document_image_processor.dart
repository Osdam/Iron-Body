import 'dart:io';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:image/image.dart' as img;
import 'package:path_provider/path_provider.dart';

/// Postproceso de la foto capturada (o subida) del documento:
///  1. corrige la orientación (EXIF),
///  2. intenta **recortar el documento** y dejar fuera mesa / teclado / sombras
///     del entorno (detección por contraste contra el borde de la imagen); si no
///     hay un recorte confiable, hace un recorte central suave que elimina los
///     márgenes exteriores,
///  3. mejora la imagen para OCR (normaliza brillo/contraste → reduce el efecto
///     de sombras e iluminación despareja), y
///  4. la reescala si es enorme y la re-codifica como JPEG.
///
/// Es tolerante: ante cualquier fallo devuelve la imagen original sin tocar, así
/// que nunca rompe el flujo. Corre el procesamiento de píxeles en un isolate.
/// Nunca registra contenido de la imagen en consola.
class DocumentImageProcessor {
  DocumentImageProcessor._();
  static final DocumentImageProcessor instance = DocumentImageProcessor._();

  static const _dirName = 'identity_tmp';

  /// Procesa la imagen en [sourcePath] y devuelve la ruta del resultado (un
  /// archivo temporal nuevo). Si algo falla, devuelve [sourcePath] sin cambios.
  Future<String> processCapture(String sourcePath) async {
    try {
      final bytes = await File(sourcePath).readAsBytes();
      final out = await compute(_process, bytes);
      if (out == null || out.isEmpty) return sourcePath;
      final docs = await getApplicationDocumentsDirectory();
      final dir = Directory('${docs.path}/$_dirName');
      if (!await dir.exists()) await dir.create(recursive: true);
      final dest =
          '${dir.path}/doc_proc_${DateTime.now().millisecondsSinceEpoch}.jpg';
      await File(dest).writeAsBytes(out, flush: true);
      return dest;
    } catch (_) {
      return sourcePath;
    }
  }
}

// ── Implementación (top-level para usarse con compute) ──────────────────────

Uint8List? _process(Uint8List input) {
  var im = img.decodeImage(input);
  if (im == null) return null;
  im = img.bakeOrientation(im); // aplica la orientación EXIF a los píxeles

  // 1) Recortar al documento. Si la detección no es confiable, recorte central
  //    suave (quita los márgenes exteriores, donde están mesa/teclado/sombras;
  //    el documento siempre se encuadró dentro del marco guía, bien adentro).
  final crop = _documentRect(im) ?? _centerInset(im, 0.06);
  im = img.copyCrop(im,
      x: crop.left, y: crop.top, width: crop.width, height: crop.height);

  // 2) Reescalar si es enorme (conserva detalle suficiente para OCR).
  const maxSide = 1700;
  if (im.width > maxSide || im.height > maxSide) {
    im = im.width >= im.height
        ? img.copyResize(im, width: maxSide)
        : img.copyResize(im, height: maxSide);
  }

  // 3) Mejora para OCR: estira el rango tonal (sube brillo si está oscuro,
  //    sube contraste, reduce el efecto de sombras / luz despareja).
  im = img.normalize(im, min: 8, max: 248);
  im = img.adjustColor(im, contrast: 1.06);

  return Uint8List.fromList(img.encodeJpg(im, quality: 92));
}

class _Rect {
  final int left, top, width, height;
  const _Rect(this.left, this.top, this.width, this.height);
}

/// Recorte centrado que quita una fracción [inset] de cada lado.
_Rect _centerInset(img.Image im, double inset) {
  final dx = (im.width * inset).round();
  final dy = (im.height * inset).round();
  final w = math.max(1, im.width - 2 * dx);
  final h = math.max(1, im.height - 2 * dy);
  return _Rect(dx, dy, w, h);
}

/// Estima el rectángulo del documento por contraste contra el "fondo" (el borde
/// de la imagen). Devuelve null si el recorte no es confiable; en ese caso se
/// usa un recorte central suave que quita los márgenes exteriores.
_Rect? _documentRect(img.Image src) {
  // Trabaja sobre una versión pequeña por velocidad; escala el resultado.
  final scale = src.width >= src.height
      ? (480.0 / src.width).clamp(0.05, 1.0)
      : (480.0 / src.height).clamp(0.05, 1.0);
  final sw = math.max(8, (src.width * scale).round());
  final sh = math.max(8, (src.height * scale).round());
  final small = img.copyResize(src, width: sw, height: sh);

  final lum = List<double>.filled(sw * sh, 0);
  for (var y = 0; y < sh; y++) {
    for (var x = 0; x < sw; x++) {
      final p = small.getPixel(x, y);
      lum[y * sw + x] = 0.299 * p.r + 0.587 * p.g + 0.114 * p.b;
    }
  }

  // Media de luminancia del borde (≈ fondo).
  final bx = math.max(1, (sw * 0.05).round());
  final by = math.max(1, (sh * 0.05).round());
  var bs = 0.0;
  var bn = 0;
  for (var y = 0; y < sh; y++) {
    for (var x = 0; x < sw; x++) {
      if (x < bx || x >= sw - bx || y < by || y >= sh - by) {
        bs += lum[y * sw + x];
        bn++;
      }
    }
  }
  if (bn == 0) return null;
  final borderMean = bs / bn;
  const delta = 22.0;

  final rowCount = List<int>.filled(sh, 0);
  final colCount = List<int>.filled(sw, 0);
  var fg = 0;
  for (var y = 0; y < sh; y++) {
    for (var x = 0; x < sw; x++) {
      if ((lum[y * sw + x] - borderMean).abs() > delta) {
        rowCount[y]++;
        colCount[x]++;
        fg++;
      }
    }
  }
  if (fg / (sw * sh) < 0.06) return null; // casi nada destaca del fondo

  final rowThr = (sw * 0.12).round();
  final colThr = (sh * 0.12).round();
  var top = -1, bottom = -1, left = -1, right = -1;
  for (var y = 0; y < sh; y++) {
    if (rowCount[y] > rowThr) {
      top = top == -1 ? y : top;
      bottom = y;
    }
  }
  for (var x = 0; x < sw; x++) {
    if (colCount[x] > colThr) {
      left = left == -1 ? x : left;
      right = x;
    }
  }
  if (top == -1 || left == -1 || bottom <= top || right <= left) return null;

  final bw = (right - left + 1).toDouble();
  final bh = (bottom - top + 1).toDouble();
  final areaFrac = (bw * bh) / (sw * sh);
  if (areaFrac < 0.22 || areaFrac > 0.995) return null; // muy chico / casi todo

  // Padding alrededor del documento (no recortar al ras del texto).
  final padX = (bw * 0.04).round();
  final padY = (bh * 0.04).round();
  var l = ((left - padX) / scale).round();
  var t = ((top - padY) / scale).round();
  var r = ((right + padX) / scale).round();
  var b = ((bottom + padY) / scale).round();
  l = l.clamp(0, src.width - 1);
  t = t.clamp(0, src.height - 1);
  r = r.clamp(l + 1, src.width);
  b = b.clamp(t + 1, src.height);
  return _Rect(l, t, r - l, b - t);
}
