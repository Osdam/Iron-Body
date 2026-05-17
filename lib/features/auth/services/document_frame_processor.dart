import 'dart:io';
import 'dart:math' as math;

import 'package:camera/camera.dart';
import 'package:flutter/services.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';

import 'document_signals.dart';

/// Estado en vivo de la detección del documento sobre el stream de la cámara.
enum DocFrameStatus {
  noDocument, // no se ve un documento dentro del recuadro
  tooFar,     // el documento ocupa muy poco del recuadro
  tooClose,   // el documento se desborda del recuadro
  lowLight,   // demasiado oscuro
  glare,      // sobreexpuesto / reflejo fuerte
  blurry,     // imagen movida / fuera de foco
  ready,      // todo correcto → listo para auto-captura
}

extension DocFrameStatusX on DocFrameStatus {
  bool get isReady => this == DocFrameStatus.ready;

  // Mensajes simples, sin nada sobre "inclinar" ni "alinear" el celular.
  String get message => switch (this) {
        DocFrameStatus.noDocument => 'Ubica el documento dentro del recuadro',
        DocFrameStatus.tooFar => 'Acércalo un poco',
        DocFrameStatus.tooClose => 'Aléjalo un poco',
        DocFrameStatus.lowLight => 'Mejora la iluminación',
        DocFrameStatus.glare => 'Hay un reflejo · muévete un poco',
        DocFrameStatus.blurry => 'Mantén el pulso firme un momento',
        DocFrameStatus.ready => 'Documento detectado',
      };
}

class DocFrameResult {
  final DocFrameStatus status;
  final double brightness; // 0..255 aprox (muestreo del plano Y)
  final double sharpness;  // varianza de gradiente (mayor = más nítido)
  final double widthFill;  // 0..1 — ancho del texto del documento / ancho del frame
  final int textBlocks;

  const DocFrameResult({
    required this.status,
    required this.brightness,
    required this.sharpness,
    required this.widthFill,
    required this.textBlocks,
  });
}

/// Analiza cada frame de la cámara con ML Kit (reconocimiento de texto) más
/// estadísticas baratas de luminancia/nitidez para decidir, en vivo, si dentro
/// del recuadro hay un documento legible.
///
/// NO depende de la alineación precisa del celular ni del ángulo: la perspectiva
/// normal de una cámara de mano se acepta sin problema. Solo declara `ready`
/// cuando dentro del recuadro hay texto de un documento, con luz y nitidez
/// aceptables y ocupando una parte razonable del recuadro. La misma lógica
/// aplica al frente y al reverso. Nunca registra el texto OCR en consola.
class DocumentFrameProcessor {
  DocumentFrameProcessor()
      : _recognizer = TextRecognizer(script: TextRecognitionScript.latin);

  final TextRecognizer _recognizer;

  bool _busy = false;
  DateTime _lastRun = DateTime.fromMillisecondsSinceEpoch(0);
  static const Duration _minInterval = Duration(milliseconds: 250);

  // Umbrales muy tolerantes — basta con que la imagen sea "suficientemente
  // buena", no perfecta. Solo se rechaza lo claramente malo (no parece
  // documento / muy borroso / muy oscuro / claramente fuera del recuadro).
  static const double _darkBrightness = 42;   // solo lo claramente oscuro
  static const double _glareBrightness = 246; // solo sobreexposición fuerte
  static const double _blurVariance = 30;     // solo lo claramente borroso
  static const double _minWidthFill = 0.18;   // basta con que ocupe parte del recuadro
  static const double _maxWidthFill = 0.99;   // casi llenarlo no es problema

  // "Zona del recuadro" dentro del frame (fracción): el texto que cae fuera de
  // esta zona (en los bordes) se ignora para juzgar el documento. Generosa: no
  // exige que el documento esté centrado al milímetro.
  static const double _frameMarginX = 0.05;
  static const double _frameMarginY = 0.04;

  Future<DocFrameResult?> process(
    CameraImage image,
    CameraDescription camera,
    DeviceOrientation deviceOrientation,
  ) async {
    if (_busy) return null;
    final now = DateTime.now();
    if (now.difference(_lastRun) < _minInterval) return null;
    _busy = true;
    _lastRun = now;
    try {
      final input = _toInputImage(image, camera, deviceOrientation);
      if (input == null) return null;

      final recognized = await _recognizer.processImage(input);
      final brightness = _averageLuminance(image);
      final sharpness = _gradientVariance(image);

      final rotation = input.metadata?.rotation;
      final rotated = rotation == InputImageRotation.rotation90deg ||
          rotation == InputImageRotation.rotation270deg;
      final frameW = (rotated ? image.height : image.width).toDouble();
      final frameH = (rotated ? image.width : image.height).toDouble();

      // Zona del recuadro dentro del frame.
      final zx0 = frameW * _frameMarginX, zx1 = frameW * (1 - _frameMarginX);
      final zy0 = frameH * _frameMarginY, zy1 = frameH * (1 - _frameMarginY);

      // Cajas de texto DENTRO de la zona del recuadro (se ignora el borde).
      double minX = double.infinity, maxX = -1;
      var inZone = 0;
      var total = 0;
      final inZoneText = StringBuffer();
      final allText = StringBuffer();
      for (final block in recognized.blocks) {
        final r = block.boundingBox;
        if (r.width < frameW * 0.04) continue; // ruido
        total++;
        allText
          ..write(block.text)
          ..write(' ');
        final cx = r.center.dx, cy = r.center.dy;
        if (cx >= zx0 && cx <= zx1 && cy >= zy0 && cy <= zy1) {
          inZone++;
          minX = math.min(minX, r.left);
          maxX = math.max(maxX, r.right);
          inZoneText
            ..write(block.text)
            ..write(' ');
        }
      }
      // Si nada cayó en la zona pero sí hay texto (el recuadro puede quedar un
      // poco desfasado), no penalizar: usar todo el texto del frame.
      final useZone = inZone > 0;
      final blocksConsidered = useZone ? inZone : total;
      final docText = (useZone ? inZoneText : allText).toString();
      final widthFill = (useZone && maxX > minX)
          ? ((maxX - minX) / frameW).clamp(0.0, 1.0)
          : (total > 0 ? 0.5 : 0.0); // sin caja en zona: no juzgamos el ancho

      // Gate compartido frente/reverso: ¿el texto del recuadro parece de un
      // documento de identidad? (evita teclados / objetos cualquiera).
      final looksLikeDoc = DocumentSignals.isLikelyDocument(docText);

      final status = _decide(
        looksLikeDoc: looksLikeDoc,
        hasTextBlock: blocksConsidered >= 1,
        brightness: brightness,
        sharpness: sharpness,
        widthFill: widthFill,
      );
      return DocFrameResult(
        status: status,
        brightness: brightness,
        sharpness: sharpness,
        widthFill: widthFill,
        textBlocks: blocksConsidered,
      );
    } catch (_) {
      return null;
    } finally {
      _busy = false;
    }
  }

  /// Decisión única, idéntica para frente y reverso. Sin nada de ángulo/posición
  /// del celular: solo "parece documento + luz + nitidez + cabe en el recuadro".
  DocFrameStatus _decide({
    required bool looksLikeDoc,
    required bool hasTextBlock,
    required double brightness,
    required double sharpness,
    required double widthFill,
  }) {
    if (!looksLikeDoc || !hasTextBlock) return DocFrameStatus.noDocument;
    if (brightness < _darkBrightness) return DocFrameStatus.lowLight;
    if (brightness > _glareBrightness) return DocFrameStatus.glare;
    if (widthFill < _minWidthFill) return DocFrameStatus.tooFar;
    if (widthFill > _maxWidthFill) return DocFrameStatus.tooClose;
    if (sharpness < _blurVariance) return DocFrameStatus.blurry;
    return DocFrameStatus.ready;
  }

  Future<void> close() => _recognizer.close();

  // ── Conversión CameraImage → InputImage (igual patrón que el módulo facial) ─
  InputImage? _toInputImage(
    CameraImage image,
    CameraDescription camera,
    DeviceOrientation deviceOrientation,
  ) {
    final sensor = camera.sensorOrientation;
    InputImageRotation? rotation;
    if (Platform.isAndroid) {
      var comp = _orientationDegrees(deviceOrientation);
      comp = (sensor - comp + 360) % 360; // cámara trasera
      rotation = InputImageRotationValue.fromRawValue(comp);
    } else if (Platform.isIOS) {
      rotation = InputImageRotationValue.fromRawValue(sensor);
    }
    if (rotation == null) return null;

    final format = InputImageFormatValue.fromRawValue(image.format.raw);
    if (format == null) return null;
    if (Platform.isAndroid && format != InputImageFormat.nv21) return null;
    if (Platform.isIOS && format != InputImageFormat.bgra8888) return null;
    if (image.planes.length != 1) return null;
    final plane = image.planes.first;

    return InputImage.fromBytes(
      bytes: plane.bytes,
      metadata: InputImageMetadata(
        size: Size(image.width.toDouble(), image.height.toDouble()),
        rotation: rotation,
        format: format,
        bytesPerRow: plane.bytesPerRow,
      ),
    );
  }

  int _orientationDegrees(DeviceOrientation o) => switch (o) {
        DeviceOrientation.portraitUp => 0,
        DeviceOrientation.landscapeLeft => 90,
        DeviceOrientation.portraitDown => 180,
        DeviceOrientation.landscapeRight => 270,
      };

  // ── Estadísticas baratas sobre el plano de luminancia ─────────────────────
  double _averageLuminance(CameraImage image) {
    final bytes = image.planes.first.bytes;
    if (Platform.isAndroid) {
      final ySize = image.width * image.height;
      final limit = ySize.clamp(0, bytes.length);
      var total = 0;
      var count = 0;
      for (var i = 0; i < limit; i += 1024) {
        total += bytes[i];
        count++;
      }
      return count == 0 ? 0 : total / count;
    }
    // BGRA en iOS — canal verde como proxy de luminancia.
    var total = 0;
    var count = 0;
    for (var i = 1; i < bytes.length; i += 4096) {
      total += bytes[i];
      count++;
    }
    return count == 0 ? 0 : total / count;
  }

  /// Varianza de |dx|+|dy| en una rejilla gruesa. Mayor = más nítido.
  double _gradientVariance(CameraImage image) {
    final bytes = image.planes.first.bytes;
    final w = image.width;
    final h = image.height;
    const step = 16;
    var sum = 0.0, sumSq = 0.0;
    var count = 0;
    if (Platform.isAndroid) {
      final yLimit = w * h;
      if (yLimit > bytes.length) return 0;
      for (var row = step; row < h - step; row += step) {
        final base = row * w;
        for (var col = step; col < w - step; col += step) {
          final idx = base + col;
          final c = bytes[idx];
          final dx = (bytes[idx + step] - c).abs();
          final dy = (bytes[idx + step * w] - c).abs();
          final m = (dx + dy).toDouble();
          sum += m;
          sumSq += m * m;
          count++;
        }
      }
    } else {
      final bpr = image.planes.first.bytesPerRow;
      for (var row = step; row < h - step; row += step) {
        for (var col = step; col < w - step; col += step) {
          final idx = row * bpr + col * 4 + 1;
          final idxX = row * bpr + (col + step) * 4 + 1;
          final idxY = (row + step) * bpr + col * 4 + 1;
          if (idxY >= bytes.length) continue;
          final c = bytes[idx];
          final dx = (bytes[idxX] - c).abs();
          final dy = (bytes[idxY] - c).abs();
          final m = (dx + dy).toDouble();
          sum += m;
          sumSq += m * m;
          count++;
        }
      }
    }
    if (count == 0) return 0;
    final mean = sum / count;
    return (sumSq / count) - (mean * mean);
  }
}
