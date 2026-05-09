import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';
import 'package:local_auth_android/local_auth_android.dart';
import 'package:local_auth_darwin/local_auth_darwin.dart';

/// Outcome of a biometric authentication request.
enum BiometricOutcome {
  success,
  userCancelled,
  notAvailable,
  notEnrolled,
  lockedOut,
  failed,
}

@immutable
class BiometricAuthResult {
  final BiometricOutcome outcome;
  final String? message;

  const BiometricAuthResult(this.outcome, [this.message]);

  bool get isSuccess => outcome == BiometricOutcome.success;
}

/// Wraps `local_auth` for biometric prompts and `flutter_secure_storage` for
/// the persisted session reference.
///
/// Storage keys:
///   * `auth.session_doc` — document number bound to this device's session.
///   * `auth.session_token` — opaque session token issued by the backend.
///
/// The biometric SDK never returns the actual fingerprint / face data — only a
/// success / failure signal. We never persist biometric data.
class BiometricSessionService {
  BiometricSessionService._();
  static final BiometricSessionService instance = BiometricSessionService._();

  final LocalAuthentication _auth = LocalAuthentication();

  static const _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
    iOptions: IOSOptions(
      accessibility: KeychainAccessibility.first_unlock_this_device,
    ),
  );

  static const _kDoc = 'auth.session_doc';
  static const _kToken = 'auth.session_token';

  bool _busy = false;

  // ── Capabilities ──────────────────────────────────────────────────────────

  Future<bool> get hasDeviceSupport async {
    try {
      final supported = await _auth.isDeviceSupported();
      final canCheck = await _auth.canCheckBiometrics;
      return supported && canCheck;
    } on PlatformException {
      return false;
    }
  }

  Future<List<BiometricType>> availableBiometrics() async {
    try {
      return await _auth.getAvailableBiometrics();
    } on PlatformException {
      return const [];
    }
  }

  /// Platform-aware label for the manual button.
  Future<String> resolveBiometricLabel() async {
    final list = await availableBiometrics();
    if (Platform.isIOS) {
      if (list.contains(BiometricType.face)) return 'Ingresar con Face ID';
      if (list.contains(BiometricType.fingerprint)) {
        return 'Ingresar con Touch ID';
      }
      return 'Ingresar con biometría';
    }
    if (Platform.isAndroid) {
      if (list.contains(BiometricType.fingerprint) ||
          list.contains(BiometricType.strong) ||
          list.contains(BiometricType.weak)) {
        return 'Ingresar con huella';
      }
      if (list.contains(BiometricType.face)) {
        return 'Ingresar con biometría facial';
      }
    }
    return 'Ingresar con biometría';
  }

  // ── Session storage ───────────────────────────────────────────────────────

  Future<String?> readStoredDocument() => _storage.read(key: _kDoc);
  Future<String?> readStoredToken() => _storage.read(key: _kToken);

  Future<bool> hasStoredSession() async {
    final doc = await readStoredDocument();
    final token = await readStoredToken();
    return doc != null && doc.isNotEmpty && token != null && token.isNotEmpty;
  }

  Future<void> persistSession({
    required String document,
    required String token,
  }) async {
    await _storage.write(key: _kDoc, value: document);
    await _storage.write(key: _kToken, value: token);
  }

  /// Wipes the secure session. Call from the logout flow.
  Future<void> clearSession() async {
    await _storage.delete(key: _kDoc);
    await _storage.delete(key: _kToken);
  }

  // ── Auth ──────────────────────────────────────────────────────────────────

  Future<BiometricAuthResult> authenticate({
    required String reason,
  }) async {
    if (_busy) {
      return const BiometricAuthResult(
        BiometricOutcome.failed,
        'Ya hay una verificación en curso.',
      );
    }
    _busy = true;
    try {
      if (!await hasDeviceSupport) {
        return const BiometricAuthResult(
          BiometricOutcome.notAvailable,
          'Este dispositivo no admite autenticación biométrica.',
        );
      }
      final available = await availableBiometrics();
      if (available.isEmpty) {
        return const BiometricAuthResult(
          BiometricOutcome.notEnrolled,
          'Este dispositivo no tiene biometría configurada.',
        );
      }

      final didAuth = await _auth.authenticate(
        localizedReason: reason,
        authMessages: const [
          AndroidAuthMessages(
            signInTitle: 'Iron Body',
            cancelButton: 'Cancelar',
          ),
          IOSAuthMessages(cancelButton: 'Cancelar'),
        ],
        options: const AuthenticationOptions(
          biometricOnly: true,
          stickyAuth: true,
          useErrorDialogs: true,
        ),
      );
      return didAuth
          ? const BiometricAuthResult(BiometricOutcome.success)
          : const BiometricAuthResult(
              BiometricOutcome.userCancelled,
              'Verificación cancelada.',
            );
    } on PlatformException catch (e) {
      return _mapPlatformException(e);
    } finally {
      _busy = false;
    }
  }

  BiometricAuthResult _mapPlatformException(PlatformException e) {
    switch (e.code) {
      case 'NotAvailable':
      case 'PasscodeNotSet':
        return const BiometricAuthResult(
          BiometricOutcome.notAvailable,
          'Biometría no disponible en este dispositivo.',
        );
      case 'NotEnrolled':
        return const BiometricAuthResult(
          BiometricOutcome.notEnrolled,
          'Este dispositivo no tiene biometría configurada.',
        );
      case 'LockedOut':
      case 'PermanentlyLockedOut':
        return const BiometricAuthResult(
          BiometricOutcome.lockedOut,
          'Demasiados intentos. Intenta más tarde o desbloquea el dispositivo.',
        );
      default:
        return BiometricAuthResult(
          BiometricOutcome.failed,
          e.message ?? 'No se pudo verificar tu identidad.',
        );
    }
  }
}
