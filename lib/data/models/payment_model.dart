enum PaymentStatus { approved, pending, rejected }

class PaymentModel {
  final String id;
  final String planName;
  final double amount;
  final DateTime date;
  final PaymentStatus status;
  final String reference;

  const PaymentModel({
    required this.id,
    required this.planName,
    required this.amount,
    required this.date,
    required this.status,
    required this.reference,
  });
}
