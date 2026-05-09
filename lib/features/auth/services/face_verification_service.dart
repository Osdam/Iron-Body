import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';

enum BiometricStatus { none, capturing, pendingUpload, registered, failed }

@immutable
class FaceCapture {
  final String localPath;
  final int bytesLength;
  final DateTime capturedAt;
  final BiometricStatus status;

  const FaceCapture({
    required this.localPath,
    required this.bytesLength,
    required this.capturedAt,
    required this.status,
  });

  FaceCapture copyWith({BiometricStatus? status}) => FaceCapture(
        localPath: localPath,
        bytesLength: bytesLength,
        capturedAt: capturedAt,
        status: status ?? this.status,
      );

  bool get isVerified => status == BiometricStatus.registered ||
      status == BiometricStatus.pendingUpload;
}

class FaceVerificationException implements Exception {
  final String message;
  const FaceVerificationException(this.message);
  @override
  String toString() => 'FaceVerificationException: $message';
}

/// Layer responsible for moving a temporary camera capture into app-private
/// storage and preparing the secure payload to send to the backend.
///
/// Today the backend endpoint is not implemented yet; [submitToBackend] is a
/// stub that simulates a successful registration. When the endpoint is
/// available, replace its body with an authenticated multipart POST and let the
/// server cipher and persist the biometric record. The server is the source of
/// truth — it must never expose the raw image in public responses.
class FaceVerificationService {
  FaceVerificationService._();
  static final FaceVerificationService instance = FaceVerificationService._();

  static const _privateDirName = 'biometric';

  Future<FaceCapture> persistCapture(String tempPath) async {
    final tempFile = File(tempPath);
    if (!await tempFile.exists()) {
      throw const FaceVerificationException('La captura no está disponible.');
    }

    final docs = await getApplicationDocumentsDirectory();
    final privateDir = Directory('${docs.path}/$_privateDirName');
    if (!await privateDir.exists()) {
      await privateDir.create(recursive: true);
    }

    final destPath =
        '${privateDir.path}/face_${DateTime.now().millisecondsSinceEpoch}.jpg';
    final saved = await tempFile.copy(destPath);

    // Best-effort cleanup of the OS-level temp file so the raw frame doesn't
    // linger in cache. Failure is non-fatal — the file is already app-private.
    try {
      await tempFile.delete();
    } catch (_) {/* ignore */}

    final length = await saved.length();
    return FaceCapture(
      localPath: saved.path,
      bytesLength: length,
      capturedAt: DateTime.now(),
      status: BiometricStatus.pendingUpload,
    );
  }

  /// Sends the capture to the backend. Replace the body with the real call when
  /// available. The backend must:
  ///   - require an authenticated session for [userId];
  ///   - encrypt the bytes at rest;
  ///   - never return the raw image in any public endpoint.
  Future<FaceCapture> submitToBackend(
    FaceCapture capture, {
    required String userId,
  }) async {
    final file = File(capture.localPath);
    if (!await file.exists()) {
      throw const FaceVerificationException(
          'La captura ya no está disponible para enviar.');
    }
    // TODO(backend): POST multipart/form-data
    //   uri: ${baseUrl}/biometric/register
    //   fields: { userId }
    //   files:  { 'face': MultipartFile.fromBytes(await file.readAsBytes()) }
    //   headers: { 'Authorization': 'Bearer <session-token>' }
    return capture.copyWith(status: BiometricStatus.registered);
  }

  /// Removes the local copy. Call when leaving the registration flow without
  /// completing it, or after a confirmed backend upload.
  Future<void> dispose(FaceCapture? capture) async {
    if (capture == null) return;
    final file = File(capture.localPath);
    if (await file.exists()) {
      try {
        await file.delete();
      } catch (_) {/* ignore */}
    }
  }
}
