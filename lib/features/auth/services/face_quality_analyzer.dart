import 'dart:ui';

import 'package:google_mlkit_face_detection/google_mlkit_face_detection.dart';

/// Outcome codes for the live verification loop. Each one maps 1:1 to a smart
/// hint shown beneath the camera preview.
enum FaceQualityCode {
  noFace,
  multipleFaces,
  outsideOval,
  tooFar,
  tooClose,
  notLookingFront,
  headTilted,
  eyesNotVisible,
  faceOccluded,
  lowLight,
  blurry,
  ready,
}

class FaceQualityResult {
  final FaceQualityCode code;
  final String message;

  const FaceQualityResult(this.code, this.message);

  bool get isReady => code == FaceQualityCode.ready;
}

/// Tunable thresholds. Kept lenient so the experience feels responsive on
/// average phones — strict enough to actually filter bad captures.
class FaceQualityConfig {
  static const double minFaceWidthRatio = 0.22;
  static const double maxFaceWidthRatio = 0.62;
  static const double centerToleranceX = 0.18;
  static const double centerToleranceY = 0.22;

  static const double maxYawDeg = 18;
  static const double maxRollDeg = 16;

  static const double minBrightness = 65; // 0–255 luminance
  static const double minSharpness = 28; // gradient variance proxy
  static const double minEyeOpenProb = 0.35;
}

class FaceQualityAnalyzer {
  const FaceQualityAnalyzer();

  FaceQualityResult analyze({
    required List<Face> faces,
    required Size frameSize,
    required double brightness,
    required double sharpness,
  }) {
    if (faces.isEmpty) {
      return const FaceQualityResult(
        FaceQualityCode.noFace,
        'Coloca tu rostro dentro del óvalo',
      );
    }
    if (faces.length > 1) {
      return const FaceQualityResult(
        FaceQualityCode.multipleFaces,
        'Solo debe aparecer una persona en cámara',
      );
    }

    final face = faces.first;
    final bbox = face.boundingBox;
    final w = frameSize.width;
    final h = frameSize.height;
    if (w <= 0 || h <= 0) {
      return const FaceQualityResult(
        FaceQualityCode.noFace,
        'Coloca tu rostro dentro del óvalo',
      );
    }

    final cx = (bbox.left + bbox.width / 2) / w;
    final cy = (bbox.top + bbox.height / 2) / h;
    final faceW = bbox.width / w;

    if (faceW < FaceQualityConfig.minFaceWidthRatio) {
      return const FaceQualityResult(
        FaceQualityCode.tooFar,
        'Acércate un poco más',
      );
    }
    if (faceW > FaceQualityConfig.maxFaceWidthRatio) {
      return const FaceQualityResult(
        FaceQualityCode.tooClose,
        'Aléjate un poco',
      );
    }

    final cxOff = (cx - 0.5).abs();
    final cyOff = (cy - 0.5).abs();
    if (cxOff > FaceQualityConfig.centerToleranceX ||
        cyOff > FaceQualityConfig.centerToleranceY) {
      return const FaceQualityResult(
        FaceQualityCode.outsideOval,
        'Centra tu rostro dentro del óvalo',
      );
    }

    // Bounding box must remain inside the visible frame so we don't capture a
    // partially-cropped face.
    if (bbox.left < 0 ||
        bbox.top < 0 ||
        bbox.right > w ||
        bbox.bottom > h) {
      return const FaceQualityResult(
        FaceQualityCode.outsideOval,
        'Tu rostro está cortado, ajústalo dentro del óvalo',
      );
    }

    if (brightness < FaceQualityConfig.minBrightness) {
      return const FaceQualityResult(
        FaceQualityCode.lowLight,
        'Mejora la iluminación',
      );
    }

    final yaw = face.headEulerAngleY?.abs() ?? 0;
    final roll = face.headEulerAngleZ?.abs() ?? 0;
    if (yaw > FaceQualityConfig.maxYawDeg) {
      return const FaceQualityResult(
        FaceQualityCode.notLookingFront,
        'Mira al frente',
      );
    }
    if (roll > FaceQualityConfig.maxRollDeg) {
      return const FaceQualityResult(
        FaceQualityCode.headTilted,
        'Mantén la cabeza recta',
      );
    }

    // Eye visibility — flagged either by very low open probability or absent
    // landmarks (heavy reflection / dark glasses).
    final leftProb = face.leftEyeOpenProbability;
    final rightProb = face.rightEyeOpenProbability;
    final hasEyeProbs = leftProb != null && rightProb != null;
    if (hasEyeProbs &&
        (leftProb < FaceQualityConfig.minEyeOpenProb ||
            rightProb < FaceQualityConfig.minEyeOpenProb)) {
      return const FaceQualityResult(
        FaceQualityCode.eyesNotVisible,
        'Asegúrate de que tus ojos estén visibles',
      );
    }
    final leftEye = face.landmarks[FaceLandmarkType.leftEye];
    final rightEye = face.landmarks[FaceLandmarkType.rightEye];
    if (leftEye == null || rightEye == null) {
      return const FaceQualityResult(
        FaceQualityCode.eyesNotVisible,
        'Evita gafas oscuras o reflejos en los ojos',
      );
    }

    // Occlusion proxy: if nose / mouth landmarks are missing the face is
    // likely partially covered by a hand, mask, or low cap.
    final nose = face.landmarks[FaceLandmarkType.noseBase];
    final mouthLeft = face.landmarks[FaceLandmarkType.leftMouth];
    final mouthRight = face.landmarks[FaceLandmarkType.rightMouth];
    if (nose == null || mouthLeft == null || mouthRight == null) {
      return const FaceQualityResult(
        FaceQualityCode.faceOccluded,
        'Retira elementos que cubran tu rostro',
      );
    }

    if (sharpness < FaceQualityConfig.minSharpness) {
      return const FaceQualityResult(
        FaceQualityCode.blurry,
        'No te muevas, estamos enfocando',
      );
    }

    return const FaceQualityResult(
      FaceQualityCode.ready,
      'Rostro listo para capturar',
    );
  }
}
