import '../../../core/network/api_client.dart';
import '../models/payment_record.dart';

/// Consulta el historial de pagos y el detalle de una transacción.
/// Reutiliza endpoints del backend (datos públicos, sin secretos).
class PaymentHistoryService {
  PaymentHistoryService._();
  static final PaymentHistoryService instance = PaymentHistoryService._();

  /// GET /api/payments/epayco/history
  Future<List<PaymentRecord>> history({int limit = 50}) async {
    final json =
        await ApiClient.instance.getJson('/payments/epayco/history?limit=$limit');
    final list = (json['data'] as List?) ?? const [];
    return list
        .whereType<Map<String, dynamic>>()
        .map(PaymentRecord.fromJson)
        .toList();
  }

  /// Detalle/estado actual de una transacción (reutiliza el endpoint de
  /// estado, que además refresca pendientes desde ePayco).
  Future<PaymentRecord> detail(String reference) async {
    final json =
        await ApiClient.instance.getJson('/payments/$reference/status');
    return PaymentRecord.fromJson(json);
  }
}
