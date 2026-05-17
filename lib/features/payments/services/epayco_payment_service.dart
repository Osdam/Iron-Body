import 'dart:math';

import '../../../core/network/api_client.dart';
import '../models/payment_transaction.dart';

/// Genera una idempotency_key única para CADA intento de pago iniciado por el
/// usuario. Un reintento (tras failed/cancelled/expired) produce una clave
/// nueva → transacción limpia, sin chocar con la anterior. La recuperación de
/// un pago en curso (pending/processing) NO usa esto: se consulta por
/// `reference` vía [EpaycoPaymentService.getStatus].
String newIdempotencyKey() =>
    'IRON-${DateTime.now().microsecondsSinceEpoch}-'
    '${Random().nextInt(0x7fffffff).toRadixString(16)}';

/// Método de pago. El cobro se procesa 100% por el backend (API ePayco): la
/// app NUNCA abre navegador ni WebView.
enum PaymentMethod { card, pse, nequi, daviplata }

extension PaymentMethodX on PaymentMethod {
  String get label => switch (this) {
        PaymentMethod.card => 'Tarjeta',
        PaymentMethod.pse => 'PSE',
        PaymentMethod.nequi => 'Nequi',
        PaymentMethod.daviplata => 'Daviplata',
      };

  String get endpoint => switch (this) {
        PaymentMethod.card => '/payments/epayco/pay-card',
        PaymentMethod.pse => '/payments/epayco/pay-pse',
        PaymentMethod.nequi => '/payments/epayco/pay-nequi',
        PaymentMethod.daviplata => '/payments/epayco/pay-daviplata',
      };
}

/// Datos de un cobro. Los datos de tarjeta solo viajan (sobre HTTPS) al backend
/// para que ÉL tokenice con ePayco; no se persisten ni se loguean, y se limpian
/// de la UI tras enviarse.
class PaymentRequest {
  final PaymentMethod method;
  final double amount;
  final String description;
  final String currency;
  final String idempotencyKey; // estable por intento → evita doble pago
  final int? orderId;
  final int? userId;
  final int? planId;
  final String? customerName;
  final String? customerEmail;
  final String? customerPhone;
  final String? customerDoc;
  final String? customerDocType;
  final String? customerCity;
  final String? customerAddress;
  final String? customerCountry;

  // Tarjeta (solo método card; transitorio).
  final String? cardNumber;
  final String? cardExpMonth;
  final String? cardExpYear;
  final String? cardCvc;
  final int dues; // cuotas (solo card), default 1

  // Nequi/Daviplata.
  final String? walletPhone;

  // PSE.
  final String? pseBank;
  final String? psePersonType; // 'natural' | 'juridica'

  const PaymentRequest({
    required this.method,
    required this.amount,
    required this.description,
    required this.idempotencyKey,
    this.currency = 'COP',
    this.orderId,
    this.userId,
    this.planId,
    this.customerName,
    this.customerEmail,
    this.customerPhone,
    this.customerDoc,
    this.customerDocType,
    this.customerCity,
    this.customerAddress,
    this.customerCountry,
    this.cardNumber,
    this.cardExpMonth,
    this.cardExpYear,
    this.cardCvc,
    this.dues = 1,
    this.walletPhone,
    this.pseBank,
    this.psePersonType,
  });

  Map<String, dynamic> toBody() {
    final body = <String, dynamic>{
      'amount': amount,
      'currency': currency,
      'description': description,
      'idempotency_key': idempotencyKey,
      if (orderId != null) 'order_id': orderId,
      if (userId != null) 'user_id': userId,
      if (planId != null) 'plan_id': planId,
      'customer': {
        if (customerName != null) 'name': customerName,
        if (customerEmail != null) 'email': customerEmail,
        if (customerPhone != null) 'phone': customerPhone,
        if (customerDoc != null) 'doc_number': customerDoc,
        if (customerDocType != null) 'doc_type': customerDocType,
        if (customerCity != null) 'city': customerCity,
        if (customerAddress != null) 'address': customerAddress,
        if (customerCountry != null) 'country': customerCountry,
      },
    };
    switch (method) {
      case PaymentMethod.card:
        body['card'] = {
          'number': (cardNumber ?? '').replaceAll(' ', ''),
          'exp_month': cardExpMonth ?? '',
          'exp_year': cardExpYear ?? '',
          'cvc': cardCvc ?? '',
        };
        body['dues'] = dues;
      case PaymentMethod.nequi:
        body['phone'] = walletPhone ?? customerPhone ?? '';
      case PaymentMethod.daviplata:
        if (walletPhone != null) body['phone'] = walletPhone;
      case PaymentMethod.pse:
        body['pse'] = {
          if (pseBank != null) 'bank': pseBank,
          'person_type': psePersonType ?? 'natural',
        };
    }
    return body;
  }
}

/// Habla con el backend Laravel (fuente de verdad). El backend procesa el pago
/// por API ePayco y devuelve el estado real; la app solo consulta por
/// `reference`. No hay navegador, WebView ni checkout web.
class EpaycoPaymentService {
  EpaycoPaymentService._();
  static final EpaycoPaymentService instance = EpaycoPaymentService._();

  /// Procesa el pago por el método elegido (cobro real por API ePayco).
  Future<PaymentTransaction> pay(PaymentRequest req) async {
    final json =
        await ApiClient.instance.postJson(req.method.endpoint, req.toBody());
    return PaymentTransaction.fromJson(json);
  }

  /// Estado real por referencia (recupera el pago aunque falle la red durante
  /// el cobro: nunca se crea un pago duplicado).
  Future<PaymentTransaction> getStatus(String reference) async {
    final json =
        await ApiClient.instance.getJson('/payments/$reference/status');
    return PaymentTransaction.fromJson(json);
  }
}
