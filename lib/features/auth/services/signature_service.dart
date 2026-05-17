import 'dart:io';
import 'dart:typed_data';

import 'package:path_provider/path_provider.dart';

import '../models/legal_consent.dart';

class SignatureException implements Exception {
  final String message;
  const SignatureException(this.message);
  @override
  String toString() => 'SignatureException: $message';
}

/// Persiste la firma del usuario (dibujada en pantalla o documento subido) en
/// almacenamiento privado de la app y prepara el envío al backend.
///
/// Reglas:
///  - la firma NUNCA se guarda como texto plano ni se imprime en consola;
///  - el archivo queda en el directorio privado de la app, no en la galería;
///  - el cifrado/retención definitivo corresponde al backend.
class SignatureService {
  SignatureService._();
  static final SignatureService instance = SignatureService._();

  static const _privateDirName = 'signatures';

  Future<Directory> _privateDir() async {
    final docs = await getApplicationDocumentsDirectory();
    final dir = Directory('${docs.path}/$_privateDirName');
    if (!await dir.exists()) await dir.create(recursive: true);
    return dir;
  }

  /// Guarda los bytes PNG de una firma dibujada en pantalla.
  Future<SignatureSupport> saveDrawnSignature(Uint8List pngBytes) async {
    if (pngBytes.isEmpty) {
      throw const SignatureException('La firma está vacía.');
    }
    final dir = await _privateDir();
    final path =
        '${dir.path}/sign_${DateTime.now().millisecondsSinceEpoch}.png';
    await File(path).writeAsBytes(pngBytes, flush: true);
    return SignatureSupport(kind: SignatureKind.drawn, filePath: path);
  }

  /// Copia un documento firmado seleccionado por el usuario (imagen o PDF).
  Future<SignatureSupport> saveUploadedFile(String sourcePath) async {
    final src = File(sourcePath);
    if (!await src.exists()) {
      throw const SignatureException('El archivo seleccionado no existe.');
    }
    final ext = sourcePath.split('.').last.toLowerCase();
    final isPdf = ext == 'pdf';
    final isImage = ext == 'png' || ext == 'jpg' || ext == 'jpeg';
    if (!isPdf && !isImage) {
      throw const SignatureException(
          'Formato no soportado. Usa PDF, PNG o JPG.');
    }
    final dir = await _privateDir();
    final dest =
        '${dir.path}/signed_${DateTime.now().millisecondsSinceEpoch}.$ext';
    final saved = await src.copy(dest);
    return SignatureSupport(
      kind: isPdf ? SignatureKind.uploadedPdf : SignatureKind.uploadedImage,
      filePath: saved.path,
    );
  }

  /// Borra el archivo de firma local (al reemplazarla o abandonar el flujo).
  Future<void> disposeSignature(SignatureSupport? support) async {
    final path = support?.filePath;
    if (path == null) return;
    final f = File(path);
    if (await f.exists()) {
      try {
        await f.delete();
      } catch (_) {/* ignore */}
    }
  }

  /// Envío al backend. Mock por ahora. Reemplazar por multipart autenticado.
  Future<void> submitToBackend(
    SignatureSupport support, {
    required String userId,
  }) async {
    // TODO(backend): POST multipart/form-data
    //   uri: ${baseUrl}/legal/signature
    //   fields: { userId, kind: support.kind.name }
    //   files:  { 'signature': <support.filePath> }
    //   headers: { 'Authorization': 'Bearer <session-token>' }
    await Future<void>.delayed(const Duration(milliseconds: 200));
  }
}
