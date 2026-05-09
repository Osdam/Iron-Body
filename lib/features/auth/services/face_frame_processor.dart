import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/services.dart';
import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';

import 'face_quality_analyzer.dart';

/// Wraps ML Kit face detection and lightweight luminance / sharpness sampling
/// over the live camera stream. Throttles to one analysis at a time and a
/// minimum interval to keep the UI smooth.
class FaceFrameProcessor {
  FaceFrameProcessor()
      : _detector = FaceDetector(
          options: FaceDetectorOptions(
            enableLandmarks: true,
            enableClassification: true,
            enableContours: false,
            enableTracking: false,
            performanceMode: FaceDetectorMode.fast,
            minFaceSize: 0.15,
          ),
        );

  final FaceDetector _detector;
  final FaceQualityAnalyzer _analyzer = const FaceQualityAnalyzer();

  bool _busy = false;
  DateTime _lastRun = DateTime.fromMillisecondsSinceEpoch(0);
  static const Duration _minInterval = Duration(milliseconds: 220);

  Future<FaceQualityResult?> process(
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
      final inputImage = _toInputImage(image, camera, deviceOrientation);
      if (inputImage == null) return null;

      final faces = await _detector.processImage(inputImage);
      final brightness = _averageLuminance(image);
      final sharpness = _gradientVariance(image);

      // After ML Kit applies the rotation we passed, the bounding boxes are
      // expressed in the rotated (portrait) frame. Match those dimensions for
      // the centering / size heuristics.
      final rotation = inputImage.metadata?.rotation;
      final isRotated = rotation == InputImageRotation.rotation90deg ||
          rotation == InputImageRotation.rotation270deg;
      final frameSize = Size(
        isRotated ? image.height.toDouble() : image.width.toDouble(),
        isRotated ? image.width.toDouble() : image.height.toDouble(),
      );

      return _analyzer.analyze(
        faces: faces,
        frameSize: frameSize,
        brightness: brightness,
        sharpness: sharpness,
      );
    } catch (_) {
      return null;
    } finally {
      _busy = false;
    }
  }

  Future<void> close() => _detector.close();

  // ── Conversion ────────────────────────────────────────────────────────────

  InputImage? _toInputImage(
    CameraImage image,
    CameraDescription camera,
    DeviceOrientation deviceOrientation,
  ) {
    final sensor = camera.sensorOrientation;
    InputImageRotation? rotation;

    if (Platform.isAndroid) {
      var compensation = _orientationDegrees(deviceOrientation);
      if (camera.lensDirection == CameraLensDirection.front) {
        compensation = (sensor + compensation) % 360;
      } else {
        compensation = (sensor - compensation + 360) % 360;
      }
      rotation = InputImageRotationValue.fromRawValue(compensation);
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

  int _orientationDegrees(DeviceOrientation o) {
    switch (o) {
      case DeviceOrientation.portraitUp:
        return 0;
      case DeviceOrientation.landscapeLeft:
        return 90;
      case DeviceOrientation.portraitDown:
        return 180;
      case DeviceOrientation.landscapeRight:
        return 270;
    }
  }

  // ── Frame statistics (cheap, sampled) ─────────────────────────────────────

  double _averageLuminance(CameraImage image) {
    final bytes = image.planes.first.bytes;
    if (Platform.isAndroid) {
      // NV21: Y plane occupies first width*height bytes.
      final ySize = image.width * image.height;
      final limit = ySize.clamp(0, bytes.length);
      return _sampledMean(bytes, 0, limit, 1, 1024);
    }
    // BGRA on iOS — sample the green channel as a proxy for luminance.
    return _sampledMean(bytes, 1, bytes.length, 4, 1024);
  }

  double _sampledMean(
    Uint8List bytes,
    int offset,
    int limit,
    int strideBytes,
    int sampleEvery,
  ) {
    int total = 0;
    int count = 0;
    final step = strideBytes * sampleEvery;
    for (int i = offset; i < limit; i += step) {
      total += bytes[i];
      count++;
    }
    return count == 0 ? 0 : total / count;
  }

  /// Variance of |dx|+|dy| sampled on a coarse grid. Higher = sharper.
  double _gradientVariance(CameraImage image) {
    final bytes = image.planes.first.bytes;
    final w = image.width;
    final h = image.height;
    if (Platform.isAndroid) {
      const step = 16;
      double sum = 0;
      double sumSq = 0;
      int count = 0;
      final yLimit = w * h;
      if (yLimit > bytes.length) return 0;
      for (int row = step; row < h - step; row += step) {
        final base = row * w;
        for (int col = step; col < w - step; col += step) {
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
      if (count == 0) return 0;
      final mean = sum / count;
      return (sumSq / count) - (mean * mean);
    }
    // BGRA — green channel as luminance proxy; use bytesPerRow to be safe.
    const step = 16;
    final bpr = image.planes.first.bytesPerRow;
    double sum = 0;
    double sumSq = 0;
    int count = 0;
    for (int row = step; row < h - step; row += step) {
      for (int col = step; col < w - step; col += step) {
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
    if (count == 0) return 0;
    final mean = sum / count;
    return (sumSq / count) - (mean * mean);
  }
}
