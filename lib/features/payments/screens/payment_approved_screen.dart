import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../core/utils/currency_formatter.dart';
import '../../../data/mock/mock_data.dart';
import '../models/payment_record.dart';
import '../models/payment_transaction.dart';
import '../widgets/receipt_card.dart';
import '../widgets/success_view.dart';

/// Pago aprobado (tienda) — comprobante premium tipo ticket.
class PaymentApprovedScreen extends StatelessWidget {
  final PaymentTransaction tx;
  final String title;
  final String subtitle;
  final String? methodCode; // 'card'|'pse'|'nequi'|'daviplata'
  final String? userName;
  final String? productName;

  const PaymentApprovedScreen({
    super.key,
    required this.tx,
    this.title = '¡Compra exitosa!',
    this.subtitle = 'Tu transacción fue aprobada correctamente.',
    this.methodCode,
    this.userName,
    this.productName,
  });

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    final f = DateFormat('dd/MM/yyyy');
    final u = AppSession.currentUser;
    final record = PaymentRecord(
      reference: tx.reference,
      status: 'approved',
      amount: tx.amount,
      currency: tx.currency,
      provider: 'epayco',
      method: methodCode,
      providerRef: tx.providerRef,
      description: tx.description,
      product: productName ?? tx.description,
      userName: userName ?? u?.fullName,
      document: u?.document,
      email: u?.email,
      phone: u?.phone,
      paidAt: tx.paidAt ?? now.toIso8601String(),
      createdAt: now.toIso8601String(),
    );

    return PaymentSuccessView(
      title: title,
      subtitle: subtitle,
      barcodeValue: tx.reference,
      record: record,
      rows: [
        ReceiptRow('Referencia Iron Body', tx.reference),
        ReceiptRow('Ref ePayco', tx.providerRef ?? 'No disponible'),
        ReceiptRow('Monto', CurrencyFormatter.format(tx.amount)),
        ReceiptRow('Método', record.methodLabel),
        ReceiptRow('Fecha', f.format(now)),
        ReceiptRow('Hora', DateFormat('HH:mm').format(now)),
        ReceiptRow('Usuario', userName ?? 'No disponible'),
        ReceiptRow(
            'Producto', productName ?? tx.description ?? 'Pedido Iron Body'),
      ],
    );
  }
}
