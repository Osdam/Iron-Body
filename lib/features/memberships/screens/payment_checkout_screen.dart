import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../data/models/membership_plan_model.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../../shared/widgets/iron_card.dart';
import '../../../shared/widgets/iron_input.dart';
import 'payment_success_screen.dart';

class PaymentCheckoutScreen extends StatefulWidget {
  final MembershipPlanModel plan;
  const PaymentCheckoutScreen({super.key, required this.plan});

  @override
  State<PaymentCheckoutScreen> createState() => _PaymentCheckoutScreenState();
}

class _PaymentCheckoutScreenState extends State<PaymentCheckoutScreen> {
  final _couponCtrl = TextEditingController();
  bool _processing = false;

  Future<void> _pay() async {
    setState(() => _processing = true);
    await Future.delayed(const Duration(milliseconds: 1500));
    if (!mounted) return;
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(builder: (_) => PaymentSuccessScreen(plan: widget.plan)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final plan = widget.plan;
    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: const IronAppBar(title: 'Resumen de pago'),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Resumen del plan
            IronCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text('Plan seleccionado', style: GoogleFonts.inter(fontSize: 12, color: AppColors.textSecondary)),
                      if (plan.badge.isNotEmpty)
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(color: AppColors.primary, borderRadius: BorderRadius.circular(99)),
                          child: Text(plan.badge, style: GoogleFonts.lexend(fontSize: 10, fontWeight: FontWeight.w700, color: AppColors.dark)),
                        ),
                    ],
                  ),
                  const Gap(8),
                  Text(plan.name, style: GoogleFonts.lexend(fontSize: 22, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                  Text(plan.period, style: GoogleFonts.inter(fontSize: 13, color: AppColors.textSecondary)),
                  const Gap(16),
                  const Divider(color: AppColors.border),
                  const Gap(12),
                  ...plan.benefits.map((b) => Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: Row(children: [
                      const Icon(Icons.check_circle_rounded, size: 16, color: AppColors.primary),
                      const Gap(8),
                      Text(b, style: GoogleFonts.inter(fontSize: 13, color: AppColors.textPrimary)),
                    ]),
                  )),
                ],
              ),
            ).animate().fadeIn().slideY(begin: 0.2),
            const Gap(20),

            // Cupón
            IronInput(
              label: 'Código de descuento',
              hint: 'Ej: IRON15',
              controller: _couponCtrl,
              prefixIcon: Icons.discount_outlined,
              suffix: TextButton(
                onPressed: () {},
                child: Text('Aplicar', style: GoogleFonts.lexend(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
              ),
            ).animate().fadeIn(delay: 150.ms),
            const Gap(20),

            // Total
            IronCard(
              child: Column(
                children: [
                  _row('Subtotal', CurrencyFormatter.format(plan.price)),
                  const Gap(8),
                  _row('Descuento', '\$0'),
                  const Gap(8),
                  const Divider(color: AppColors.border),
                  const Gap(8),
                  _row('Total', CurrencyFormatter.format(plan.price), isBold: true),
                ],
              ),
            ).animate().fadeIn(delay: 200.ms),
            const Gap(24),

            // Método de pago
            IronCard(
              child: Row(
                children: [
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      color: AppColors.surfaceContainerLow,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: const Icon(Icons.credit_card_rounded, color: AppColors.textSecondary),
                  ),
                  const Gap(12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Wompi', style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                        Text('Tarjeta · PSE · Nequi · Daviplata', style: GoogleFonts.inter(fontSize: 12, color: AppColors.textSecondary)),
                      ],
                    ),
                  ),
                  const Icon(Icons.keyboard_arrow_right_rounded, color: AppColors.textSecondary),
                ],
              ),
            ).animate().fadeIn(delay: 300.ms),
            const Gap(32),

            if (_processing)
              const Center(child: CircularProgressIndicator(color: AppColors.primary))
            else
              IronButton(
                label: 'PAGAR CON WOMPI',
                onPressed: _pay,
              ).animate().fadeIn(delay: 400.ms),

            const Gap(12),
            Center(
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.lock_rounded, size: 13, color: AppColors.textDisabled),
                  const Gap(5),
                  Text(
                    'Pago seguro procesado por Wompi',
                    style: GoogleFonts.inter(fontSize: 12, color: AppColors.textDisabled),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _row(String label, String value, {bool isBold = false}) => Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: GoogleFonts.inter(fontSize: 14, color: isBold ? AppColors.textPrimary : AppColors.textSecondary, fontWeight: isBold ? FontWeight.w700 : FontWeight.w400)),
          Text(value, style: GoogleFonts.lexend(fontSize: isBold ? 18 : 14, fontWeight: isBold ? FontWeight.w700 : FontWeight.w500, color: isBold ? AppColors.primary : AppColors.textPrimary)),
        ],
      );
}
