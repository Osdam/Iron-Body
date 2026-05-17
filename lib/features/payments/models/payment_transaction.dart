/// Estado de una transacción de pago, espejo del backend.
enum PaymentTxStatus { pending, processing, approved, failed, cancelled, expired }

PaymentTxStatus _statusFrom(String? raw) {
  switch (raw) {
    case 'approved':
      return PaymentTxStatus.approved;
    case 'failed':
      return PaymentTxStatus.failed;
    case 'cancelled':
      return PaymentTxStatus.cancelled;
    case 'expired':
      return PaymentTxStatus.expired;
    case 'processing':
      return PaymentTxStatus.processing;
    case 'pending':
    default:
      return PaymentTxStatus.pending;
  }
}

/// Transacción de pago tal como la expone el backend (`toPublicArray`).
/// No contiene llaves ni datos sensibles.
class PaymentTransaction {
  final String reference;
  final PaymentTxStatus status;
  final double amount;
  final String currency;
  final String? checkoutUrl;
  final String? description;
  final String? reason;
  final String? providerRef;
  final String? paidAt;

  /// URL del portal del banco para autorizar PSE dentro de la app (WebView).
  final String? authorizationUrl;

  const PaymentTransaction({
    required this.reference,
    required this.status,
    required this.amount,
    required this.currency,
    this.checkoutUrl,
    this.description,
    this.reason,
    this.providerRef,
    this.paidAt,
    this.authorizationUrl,
  });

  factory PaymentTransaction.fromJson(Map<String, dynamic> j) {
    return PaymentTransaction(
      reference: (j['reference'] ?? '').toString(),
      status: _statusFrom(j['status'] as String?),
      amount: (j['amount'] is num) ? (j['amount'] as num).toDouble() : 0,
      currency: (j['currency'] ?? 'COP').toString(),
      checkoutUrl: j['checkout_url'] as String?,
      description: j['description'] as String?,
      reason: j['reason'] as String?,
      providerRef: j['provider_ref'] as String?,
      paidAt: j['paid_at'] as String?,
      authorizationUrl: j['authorization_url'] as String?,
    );
  }

  bool get isApproved => status == PaymentTxStatus.approved;
  bool get isInFlight =>
      status == PaymentTxStatus.pending ||
      status == PaymentTxStatus.processing;
  bool get isFailed =>
      status == PaymentTxStatus.failed ||
      status == PaymentTxStatus.cancelled ||
      status == PaymentTxStatus.expired;
}
