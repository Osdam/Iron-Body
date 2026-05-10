import '../../../data/models/payment_model.dart';

/// MOCK service — replace with real Wompi backend integration.
///
/// ─── REAL INTEGRATION STEPS ───────────────────────────────────────────────
/// 1. Generate a unique reference: 'IRON-{userId}-{timestamp}'
/// 2. POST {BACKEND_URL}/payments/create
///       body: { planId, amount, currency: 'COP', reference, userId }
///       → backend creates a Wompi transaction and returns { transactionId, integrityHash }
///
/// 3. For card payments: tokenize via Wompi.js / Wompi Flutter widget.
///       NEVER send raw PAN or CVV to your own backend.
///       Wompi returns a { token } that represents the card safely.
///
/// 4. POST {BACKEND_URL}/payments/confirm
///       body: { token (card only), transactionId, reference, paymentMethod }
///       → backend verifies integrity: SHA256(reference + amount + currency + PUBLIC_KEY)
///       → backend submits to Wompi API
///
/// 5. Listen for webhook: POST {BACKEND_URL}/payments/webhook (from Wompi servers)
///       → update membership status in database
///
/// 6. Poll status (optional): GET {BACKEND_URL}/payments/{reference}/status
///
/// ─── CONFIG ───────────────────────────────────────────────────────────────
/// Replace with real values via --dart-define or environment config.
/// const _wompiPublicKey = String.fromEnvironment('WOMPI_PUBLIC_KEY');
/// const _backendUrl     = String.fromEnvironment('BACKEND_URL');
/// ──────────────────────────────────────────────────────────────────────────
class MockPaymentService {
  MockPaymentService._();
  static final MockPaymentService instance = MockPaymentService._();

  /// Change to simulate different outcomes during testing.
  MockScenario scenario = MockScenario.approved;

  Future<PaymentServiceResult> processPayment({
    required String planId,
    required double amount,
    required String paymentMethod,
    String? cardToken, // Wompi card token — never raw card data
    String? bankCode,
    String? phoneNumber,
  }) async {
    // Simulate network latency
    await Future.delayed(const Duration(milliseconds: 2200));

    final ref = 'IRON-${DateTime.now().millisecondsSinceEpoch}';

    switch (scenario) {
      case MockScenario.approved:
        return PaymentServiceResult(
          status: PaymentStatus.approved,
          reference: ref,
          message: '¡Pago aprobado! Bienvenido a Iron Body.',
        );
      case MockScenario.rejected:
        return PaymentServiceResult(
          status: PaymentStatus.rejected,
          reference: ref,
          message: 'Pago rechazado. Verifica los datos o intenta con otro método.',
        );
      case MockScenario.pending:
        return PaymentServiceResult(
          status: PaymentStatus.pending,
          reference: ref,
          message: 'Tu pago está siendo procesado. Te notificaremos cuando se confirme.',
        );
    }
  }
}

enum MockScenario {
  /// Simulate approved payment — membership activates.
  approved,

  /// Simulate rejected payment — membership does NOT activate.
  rejected,

  /// Simulate pending payment — membership stays pending.
  pending,
}

class PaymentServiceResult {
  final PaymentStatus status;
  final String reference;
  final String message;

  const PaymentServiceResult({
    required this.status,
    required this.reference,
    required this.message,
  });

  bool get isApproved => status == PaymentStatus.approved;
  bool get isRejected => status == PaymentStatus.rejected;
  bool get isPending => status == PaymentStatus.pending;
}
