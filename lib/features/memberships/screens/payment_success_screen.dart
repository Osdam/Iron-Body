import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../data/models/membership_plan_model.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../../app_shell.dart';

class PaymentSuccessScreen extends StatelessWidget {
  final MembershipPlanModel plan;
  const PaymentSuccessScreen({super.key, required this.plan});

  @override
  Widget build(BuildContext context) {
    final expiry = DateTime.now().add(Duration(days: 30 * plan.months));

    return Scaffold(
      backgroundColor: AppColors.surface0,
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            children: [
              const Spacer(),

              // Checkmark
              Container(
                width: 100,
                height: 100,
                decoration: const BoxDecoration(color: AppColors.primary, shape: BoxShape.circle),
                child: const Icon(Icons.check_rounded, size: 56, color: AppColors.dark),
              ).animate().scale(begin: const Offset(0, 0), curve: Curves.elasticOut, duration: 800.ms),

              const Gap(24),
              Text(
                'Pago confirmado',
                style: GoogleFonts.lexend(fontSize: 28, fontWeight: FontWeight.w700, color: AppColors.textPrimary),
              ).animate().fadeIn(delay: 300.ms).slideY(begin: 0.2),
              const Gap(8),
              Text(
                '¡Tu membresía está activa!',
                style: GoogleFonts.inter(fontSize: 15, color: AppColors.textSecondary),
              ).animate().fadeIn(delay: 400.ms),

              const Gap(32),

              // Detalles
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(
                  color: AppColors.surfaceContainerLow,
                  borderRadius: BorderRadius.circular(18),
                  border: Border.all(color: AppColors.border),
                ),
                child: Column(
                  children: [
                    _detail('Plan', plan.name),
                    const Gap(12),
                    _detail('Valor pagado', CurrencyFormatter.format(plan.price)),
                    const Gap(12),
                    _detail('Fecha de pago', '${DateTime.now().day}/${DateTime.now().month}/${DateTime.now().year}'),
                    const Gap(12),
                    _detail('Vence el', '${expiry.day}/${expiry.month}/${expiry.year}'),
                    const Gap(12),
                    _detail('Referencia', 'WP-${DateTime.now().millisecondsSinceEpoch}'),
                  ],
                ),
              ).animate().fadeIn(delay: 500.ms),

              const Spacer(),

              IronButton(
                label: 'IR AL INICIO',
                onPressed: () => Navigator.pushAndRemoveUntil(
                  context,
                  MaterialPageRoute(builder: (_) => const AppShell()),
                  (_) => false,
                ),
              ).animate().fadeIn(delay: 600.ms),
              const Gap(12),
              IronButton(
                label: 'EMPEZAR ENTRENAMIENTO',
                isPrimary: false,
                onPressed: () => Navigator.pushAndRemoveUntil(
                  context,
                  MaterialPageRoute(builder: (_) => const AppShell()),
                  (_) => false,
                ),
              ).animate().fadeIn(delay: 700.ms),
              const Gap(16),
            ],
          ),
        ),
      ),
    );
  }

  Widget _detail(String label, String value) => Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: GoogleFonts.inter(fontSize: 13, color: AppColors.textSecondary)),
          Text(value, style: GoogleFonts.lexend(fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
        ],
      );
}
