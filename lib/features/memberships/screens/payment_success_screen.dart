import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../core/utils/currency_formatter.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/membership_plan_model.dart';
import '../../payments/models/payment_record.dart';
import '../../payments/models/payment_transaction.dart';
import '../../payments/widgets/receipt_card.dart';
import '../../payments/widgets/success_view.dart';

/// Pago de membresía confirmado — comprobante premium tipo ticket.
class PaymentSuccessScreen extends StatelessWidget {
  final MembershipPlanModel plan;
  final PaymentTransaction? tx;
  final String? userName;
  final String? methodCode; // 'card'|'pse'|'nequi'|'daviplata'

  const PaymentSuccessScreen({
    super.key,
    required this.plan,
    this.tx,
    this.userName,
    this.methodCode,
  });

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    final expiry = now.add(Duration(days: 30 * plan.months));
    final f = DateFormat('dd/MM/yyyy');
    final amount = tx?.amount ?? plan.price;
    final reference =
        tx?.reference ?? 'IRON-${now.millisecondsSinceEpoch}';

    final u = AppSession.currentUser;
    final record = PaymentRecord(
      reference: reference,
      status: 'approved',
      amount: amount,
      currency: tx?.currency ?? 'COP',
      provider: 'epayco',
      method: methodCode,
      providerRef: tx?.providerRef,
      description: 'Membresía ${plan.name} · Iron Body',
      product: 'Membresía ${plan.name}',
      userName: userName ?? u?.fullName,
      document: u?.document,
      email: u?.email,
      phone: u?.phone,
      paidAt: tx?.paidAt ?? now.toIso8601String(),
      createdAt: now.toIso8601String(),
      membershipExpiry: f.format(expiry),
    );

    return PaymentSuccessView(
      title: 'Pago confirmado',
      subtitle: '¡Tu membresía ${plan.name} está activa!',
      barcodeValue: reference,
      record: record,
      rows: [
        ReceiptRow('Plan', plan.name),
        ReceiptRow('Referencia Iron Body', reference),
        ReceiptRow('Ref ePayco', tx?.providerRef ?? 'No disponible'),
        ReceiptRow('Monto', CurrencyFormatter.format(amount)),
        ReceiptRow('Método', record.methodLabel),
        ReceiptRow('Fecha', f.format(now)),
        ReceiptRow('Hora', DateFormat('HH:mm').format(now)),
        ReceiptRow('Usuario', userName ?? 'No disponible'),
        ReceiptRow('Vence el', f.format(expiry)),
      ],
    );
  }
}
