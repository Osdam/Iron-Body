import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../../app_shell.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../shared/widgets/iron_button.dart';
import '../widgets/receipt_card.dart';

/// Pago no realizado / rechazado — diseño premium. Nunca muestra SQL, rutas,
/// excepciones, tokens ni llaves: solo un motivo amable.
class PaymentFailedScreen extends StatelessWidget {
  final String? reason;
  final String? reference;
  final String? methodLabel;
  final double? amount;

  const PaymentFailedScreen({
    super.key,
    this.reason,
    this.reference,
    this.methodLabel,
    this.amount,
  });

  @override
  Widget build(BuildContext context) {
    final motivo = (reason != null && reason!.trim().isNotEmpty)
        ? reason!.trim()
        : 'El pago no pudo completarse';

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
                  color: AppColors.error.withValues(alpha: 0.10),
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.highlight_off_rounded,
                    size: 50, color: AppColors.error),
              ).animate().scale(
                  begin: const Offset(0.6, 0.6),
                  curve: Curves.easeOutBack,
                  duration: 600.ms),
              const Gap(22),
              Text(
                'No pudimos completar el pago',
                textAlign: TextAlign.center,
                style: GoogleFonts.lexend(
                    fontSize: 22,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary),
              ).animate().fadeIn(delay: 150.ms),
              const Gap(8),
              Text(
                'No se realizó ningún cobro.',
                textAlign: TextAlign.center,
                style: GoogleFonts.inter(
                    fontSize: 14, color: AppColors.textSecondary),
              ).animate().fadeIn(delay: 250.ms),
              const Gap(24),
              PaymentReceiptCard(
                icon: Icons.highlight_off_rounded,
                iconColor: Colors.white,
                iconBg: AppColors.error,
                title: 'Pago no realizado',
                subtitle: motivo,
                statusLabel: 'Rechazado',
                statusColor: AppColors.error,
                barcodeValue: reference ?? 'IRON-SIN-REF',
                rows: [
                  ReceiptRow('Motivo', motivo),
                  ReceiptRow('Referencia', reference ?? 'No disponible'),
                  ReceiptRow('Método', methodLabel ?? 'No disponible'),
                  ReceiptRow(
                      'Monto',
                      amount != null
                          ? CurrencyFormatter.format(amount!)
                          : 'No disponible'),
                ],
              ).animate().fadeIn(delay: 350.ms).slideY(begin: 0.08),
              const Gap(24),
              IronButton(
                label: 'INTENTAR NUEVAMENTE',
                onPressed: () => Navigator.of(context).pop(),
              ),
              const Gap(12),
              IronButton(
                label: 'CAMBIAR MÉTODO DE PAGO',
                isPrimary: false,
                onPressed: () => Navigator.of(context).pop(),
              ),
              const Gap(8),
              TextButton(
                onPressed: () => Navigator.pushAndRemoveUntil(
                  context,
                  MaterialPageRoute(builder: (_) => const AppShell()),
                  (_) => false,
                ),
                child: Text('Volver al inicio',
                    style: GoogleFonts.lexend(
                        color: AppColors.textSecondary,
                        fontWeight: FontWeight.w600)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
