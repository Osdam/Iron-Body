import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../../app_shell.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../shared/widgets/iron_button.dart';
import '../models/payment_transaction.dart';
import '../services/epayco_payment_service.dart';
import '../widgets/receipt_card.dart';
import 'payment_failed_screen.dart';

/// Pago en verificación — diseño premium. Reconsulta el estado real al backend
/// ("Actualizar estado") y enruta a éxito/fallo cuando se resuelve.
class PaymentPendingScreen extends StatefulWidget {
  final String reference;
  final Widget Function(PaymentTransaction tx) onApproved;
  final VoidCallback? onApprovedSideEffect;
  final String? methodLabel;
  final double? amount;
  final String? providerRef;
  final bool isPse;

  const PaymentPendingScreen({
    super.key,
    required this.reference,
    required this.onApproved,
    this.onApprovedSideEffect,
    this.methodLabel,
    this.amount,
    this.providerRef,
    this.isPse = false,
  });

  @override
  State<PaymentPendingScreen> createState() => _PaymentPendingScreenState();
}

class _PaymentPendingScreenState extends State<PaymentPendingScreen> {
  bool _checking = false;
  String? _reason;
  String? _providerRef;

  @override
  void initState() {
    super.initState();
    _providerRef = widget.providerRef;
  }

  Future<void> _refresh() async {
    if (_checking) return;
    setState(() => _checking = true);
    try {
      final tx =
          await EpaycoPaymentService.instance.getStatus(widget.reference);
      if (!mounted) return;
      if (tx.isApproved) {
        widget.onApprovedSideEffect?.call();
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(builder: (_) => widget.onApproved(tx)),
        );
        return;
      }
      if (tx.isFailed) {
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(
            builder: (_) => PaymentFailedScreen(
              reason: tx.reason,
              reference: tx.reference,
              methodLabel: widget.methodLabel,
              amount: widget.amount,
            ),
          ),
        );
        return;
      }
      setState(() {
        _reason = tx.reason;
        _providerRef = tx.providerRef ?? _providerRef;
        _checking = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _checking = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Aún no podemos confirmar. Intenta de nuevo.',
              style: GoogleFonts.inter()),
          behavior: SnackBarBehavior.floating,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final title = widget.isPse
        ? 'Pago PSE pendiente de autorización'
        : 'Pago en verificación';
    final msg = widget.isPse
        ? 'Tu solicitud fue registrada. Autoriza el pago en el portal de tu '
            'banco. Cuando ePayco confirme la transacción, actualizaremos el '
            'estado.'
        : 'Tu solicitud fue registrada. Estamos esperando la confirmación '
            'del proveedor.';

    return Scaffold(
      backgroundColor: AppColors.surface0,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 28, 24, 24),
          child: Column(
            children: [
              Container(
                width: 96,
                height: 96,
                decoration: BoxDecoration(
                  color: AppColors.primary.withValues(alpha: 0.16),
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.schedule_rounded,
                    size: 48, color: AppColors.dark),
              )
                  .animate(onPlay: (c) => c.repeat(reverse: true))
                  .scaleXY(end: 1.06, duration: 1200.ms),
              const Gap(22),
              Text(
                title,
                textAlign: TextAlign.center,
                style: GoogleFonts.lexend(
                    fontSize: 22,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary),
              ).animate().fadeIn(),
              const Gap(10),
              Text(
                msg,
                textAlign: TextAlign.center,
                style: GoogleFonts.inter(
                    fontSize: 13.5,
                    color: AppColors.textSecondary,
                    height: 1.5),
              ).animate().fadeIn(delay: 120.ms),
              const Gap(24),
              PaymentReceiptCard(
                icon: Icons.schedule_rounded,
                iconColor: AppColors.dark,
                iconBg: AppColors.primary.withValues(alpha: 0.18),
                title: 'Solicitud registrada',
                subtitle: _reason ?? 'Esperando confirmación del proveedor',
                statusLabel: 'Pendiente',
                statusColor: const Color(0xFFB07A00),
                barcodeValue: widget.reference,
                rows: [
                  ReceiptRow('Referencia Iron Body', widget.reference),
                  ReceiptRow('Ref ePayco', _providerRef ?? 'No disponible'),
                  ReceiptRow(
                      'Monto',
                      widget.amount != null
                          ? CurrencyFormatter.format(widget.amount!)
                          : 'No disponible'),
                  ReceiptRow('Método', widget.methodLabel ?? 'No disponible'),
                ],
              ).animate().fadeIn(delay: 200.ms).slideY(begin: 0.08),
              const Gap(24),
              IronButton(
                label: _checking ? 'CONSULTANDO…' : 'ACTUALIZAR ESTADO',
                onPressed: _refresh,
              ),
              const Gap(12),
              IronButton(
                label: 'VOLVER AL INICIO',
                isPrimary: false,
                onPressed: () => Navigator.pushAndRemoveUntil(
                  context,
                  MaterialPageRoute(builder: (_) => const AppShell()),
                  (_) => false,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
