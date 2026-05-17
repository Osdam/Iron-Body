import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../../app_shell.dart';
import '../../../core/theme/app_colors.dart';
import '../../../shared/widgets/iron_button.dart';
import '../models/payment_record.dart';
import '../screens/receipt_screen.dart';
import 'receipt_card.dart';

/// Vista de pago aprobado premium (comprobante tipo ticket + confeti sutil),
/// reutilizada por tienda y membresía. Solo datos no sensibles.
class PaymentSuccessView extends StatelessWidget {
  final String title;
  final String subtitle;
  final List<ReceiptRow> rows;
  final String barcodeValue;

  /// Registro para abrir el comprobante profesional (PDF/compartir/descargar).
  final PaymentRecord record;

  const PaymentSuccessView({
    super.key,
    required this.title,
    required this.subtitle,
    required this.rows,
    required this.barcodeValue,
    required this.record,
  });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface0,
      body: Stack(
        children: [
          SafeArea(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(24, 26, 24, 24),
              child: Column(
                children: [
                  Container(
                    width: 96,
                    height: 96,
                    decoration: const BoxDecoration(
                        color: AppColors.primary, shape: BoxShape.circle),
                    child: const Icon(Icons.check_rounded,
                        size: 52, color: AppColors.dark),
                  ).animate().scale(
                      begin: const Offset(0, 0),
                      curve: Curves.elasticOut,
                      duration: 800.ms),
                  const Gap(20),
                  Text(title,
                          textAlign: TextAlign.center,
                          style: GoogleFonts.lexend(
                              fontSize: 24,
                              fontWeight: FontWeight.w700,
                              color: AppColors.textPrimary))
                      .animate()
                      .fadeIn(delay: 250.ms)
                      .slideY(begin: 0.15),
                  const Gap(8),
                  Text(subtitle,
                          textAlign: TextAlign.center,
                          style: GoogleFonts.inter(
                              fontSize: 14,
                              color: AppColors.textSecondary))
                      .animate()
                      .fadeIn(delay: 350.ms),
                  const Gap(24),
                  PaymentReceiptCard(
                    icon: Icons.verified_rounded,
                    title: 'Pago confirmado',
                    subtitle: 'Tu transacción fue aprobada correctamente',
                    statusLabel: 'Aprobado',
                    statusColor: const Color(0xFF1FA463),
                    barcodeValue: barcodeValue,
                    rows: rows,
                  ).animate().fadeIn(delay: 450.ms).slideY(begin: 0.08),
                  const Gap(24),
                  IronButton(
                    label: 'VER COMPROBANTE',
                    onPressed: () => Navigator.push(
                      context,
                      MaterialPageRoute(
                          builder: (_) => ReceiptScreen(record: record)),
                    ),
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
          const Positioned.fill(child: SubtleConfetti()),
        ],
      ),
    );
  }
}
