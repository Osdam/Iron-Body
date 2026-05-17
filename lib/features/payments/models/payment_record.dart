/// Registro de pago para historial/comprobante. Espejo de `toPublicArray()`
/// del backend — solo datos públicos (sin tarjeta/CVV/tokens/llaves).
class PaymentRecord {
  final String reference;
  final String status;
  final double amount;
  final String currency;
  final String? provider;
  final String? method;
  final String? providerRef;
  final String? description;
  final String? product;
  final String? userName;
  final String? document;
  final String? email;
  final String? phone;
  final String? reason;
  final String? paidAt;
  final String? createdAt;
  final String? updatedAt;

  /// Vencimiento de membresía (solo aplica a compras de membresía).
  final String? membershipExpiry;

  const PaymentRecord({
    required this.reference,
    required this.status,
    required this.amount,
    required this.currency,
    this.provider,
    this.method,
    this.providerRef,
    this.description,
    this.product,
    this.userName,
    this.document,
    this.email,
    this.phone,
    this.reason,
    this.paidAt,
    this.createdAt,
    this.updatedAt,
    this.membershipExpiry,
  });

  factory PaymentRecord.fromJson(Map<String, dynamic> j) => PaymentRecord(
        reference: (j['reference'] ?? '').toString(),
        status: (j['status'] ?? 'pending').toString(),
        amount: (j['amount'] is num) ? (j['amount'] as num).toDouble() : 0,
        currency: (j['currency'] ?? 'COP').toString(),
        provider: j['provider'] as String?,
        method: j['method'] as String?,
        providerRef: j['provider_ref'] as String?,
        description: j['description'] as String?,
        product: j['product'] as String?,
        userName: j['user_name'] as String?,
        document: j['document'] as String?,
        email: j['email'] as String?,
        phone: j['phone'] as String?,
        reason: j['reason'] as String?,
        paidAt: j['paid_at'] as String?,
        createdAt: j['created_at'] as String?,
        updatedAt: j['updated_at'] as String?,
        membershipExpiry: j['membership_expiry'] as String?,
      );

  bool get isApproved => status == 'approved';
  bool get isFailed =>
      status == 'failed' || status == 'cancelled' || status == 'expired';
  bool get isPending => status == 'pending' || status == 'processing';

  String get statusLabel => switch (status) {
        'approved' => 'Aprobado',
        'failed' => 'Rechazado',
        'cancelled' => 'Cancelado',
        'expired' => 'Expirado',
        'processing' => 'Procesando',
        _ => 'Pendiente',
      };

  String get methodLabel => switch (method) {
        'card' => 'Tarjeta',
        'pse' => 'PSE',
        'nequi' => 'Nequi',
        'daviplata' => 'Daviplata',
        _ => 'No disponible',
      };

  /// Fecha legible (paid_at si existe, si no created_at).
  DateTime? get dateTime {
    final raw = paidAt ?? createdAt ?? updatedAt;
    if (raw == null) return null;
    return DateTime.tryParse(raw)?.toLocal();
  }
}
