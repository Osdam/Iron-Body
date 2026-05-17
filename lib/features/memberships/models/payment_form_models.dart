import 'package:flutter/material.dart';

enum PaymentMethodType { credit, debit, pse, nequi, daviplata }

extension PaymentMethodTypeX on PaymentMethodType {
  String get label {
    switch (this) {
      case PaymentMethodType.credit:
        return 'Crédito';
      case PaymentMethodType.debit:
        return 'Débito';
      case PaymentMethodType.pse:
        return 'PSE';
      case PaymentMethodType.nequi:
        return 'Nequi';
      case PaymentMethodType.daviplata:
        return 'Daviplata';
    }
  }

  String get fullLabel {
    switch (this) {
      case PaymentMethodType.credit:
        return 'Tarjeta crédito';
      case PaymentMethodType.debit:
        return 'Tarjeta débito';
      case PaymentMethodType.pse:
        return 'PSE';
      case PaymentMethodType.nequi:
        return 'Nequi';
      case PaymentMethodType.daviplata:
        return 'Daviplata';
    }
  }

  IconData get icon {
    switch (this) {
      case PaymentMethodType.credit:
      case PaymentMethodType.debit:
        return Icons.credit_card_rounded;
      case PaymentMethodType.pse:
        return Icons.account_balance_rounded;
      case PaymentMethodType.nequi:
      case PaymentMethodType.daviplata:
        return Icons.smartphone_rounded;
    }
  }
}

enum CardBrand { unknown, visa, mastercard, amex, diners }

CardBrand detectCardBrand(String number) {
  final n = number.replaceAll(' ', '');
  if (n.isEmpty) return CardBrand.unknown;
  if (n.startsWith('4')) return CardBrand.visa;
  if (RegExp(r'^5[1-5]|^2[2-7]').hasMatch(n)) return CardBrand.mastercard;
  if (RegExp(r'^3[47]').hasMatch(n)) return CardBrand.amex;
  if (RegExp(r'^3[0689]').hasMatch(n)) return CardBrand.diners;
  return CardBrand.unknown;
}

enum PersonType { natural, juridica }

class CardFormData {
  String number = '';
  String holder = '';
  String expiry = '';
  String cvv = '';
  int dues = 1; // cuotas — default 1

  CardFormData copyWith({
    String? number,
    String? holder,
    String? expiry,
    String? cvv,
    int? dues,
  }) =>
      CardFormData()
        ..number = number ?? this.number
        ..holder = holder ?? this.holder
        ..expiry = expiry ?? this.expiry
        ..cvv = cvv ?? this.cvv
        ..dues = dues ?? this.dues;
}

class PseFormData {
  PersonType personType = PersonType.natural;
  String bankCode = '';
  String bankName = '';
  String docType = 'CC';
  String docNumber = '';
  String email = '';
  String phone = '';
}

class WalletFormData {
  String phone = '';
  String docType = 'CC';
  String docNumber = '';
}
