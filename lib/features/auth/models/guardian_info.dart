import 'package:flutter/foundation.dart';

/// Datos del responsable legal / acudiente. Solo aplica cuando el usuario que
/// se registra es menor de edad ([legalAdultAge]).
@immutable
class GuardianInfo {
  final String fullName;
  final String documentNumber;
  final String phone;
  final String email;
  final String relationship; // parentesco
  final bool acceptsResponsibility;

  const GuardianInfo({
    this.fullName = '',
    this.documentNumber = '',
    this.phone = '',
    this.email = '',
    this.relationship = '',
    this.acceptsResponsibility = false,
  });

  bool get isComplete =>
      fullName.trim().length >= 3 &&
      documentNumber.trim().length >= 4 &&
      phone.trim().length >= 7 &&
      _looksLikeEmail(email) &&
      relationship.trim().isNotEmpty &&
      acceptsResponsibility;

  static bool _looksLikeEmail(String value) {
    final v = value.trim();
    return v.contains('@') && v.contains('.') && v.length >= 6;
  }

  GuardianInfo copyWith({
    String? fullName,
    String? documentNumber,
    String? phone,
    String? email,
    String? relationship,
    bool? acceptsResponsibility,
  }) {
    return GuardianInfo(
      fullName: fullName ?? this.fullName,
      documentNumber: documentNumber ?? this.documentNumber,
      phone: phone ?? this.phone,
      email: email ?? this.email,
      relationship: relationship ?? this.relationship,
      acceptsResponsibility:
          acceptsResponsibility ?? this.acceptsResponsibility,
    );
  }
}
