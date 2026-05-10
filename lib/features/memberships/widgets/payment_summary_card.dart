import 'package:flutter/material.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../data/models/membership_plan_model.dart';

class PaymentSummaryCard extends StatelessWidget {
  final MembershipPlanModel plan;
  final String? userName;
  final String reference;
  final String selectedMethod;
  final double discount;

  const PaymentSummaryCard({
    super.key,
    required this.plan,
    this.userName,
    required this.reference,
    required this.selectedMethod,
    this.discount = 0,
  });

  @override
  Widget build(BuildContext context) {
    final total = plan.price - discount;

    return Container(
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFF121212), Color(0xFF1E1E2A)],
        ),
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.22),
            blurRadius: 22,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 18, 18, 16),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'RESUMEN DE PAGO',
                        style: GoogleFonts.lexend(
                          fontSize: 9,
                          color: Colors.white38,
                          letterSpacing: 2,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const Gap(6),
                      Text(
                        plan.name,
                        style: GoogleFonts.lexend(
                          fontSize: 22,
                          fontWeight: FontWeight.w700,
                          color: Colors.white,
                        ),
                      ),
                      Text(
                        plan.period,
                        style: GoogleFonts.inter(
                            fontSize: 13, color: Colors.white54),
                      ),
                    ],
                  ),
                ),
                if (plan.badge.isNotEmpty)
                  Container(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: AppColors.primary,
                      borderRadius: BorderRadius.circular(99),
                    ),
                    child: Text(
                      plan.badge,
                      style: GoogleFonts.lexend(
                        fontSize: 10,
                        fontWeight: FontWeight.w700,
                        color: AppColors.dark,
                      ),
                    ),
                  ),
              ],
            ),
          ),
          Container(height: 1, color: Colors.white.withValues(alpha: 0.08)),
          // Detail rows
          Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              children: [
                if (userName != null) ...[
                  _row('Usuario', userName!),
                  const Gap(10),
                ],
                _row('Referencia', reference),
                const Gap(10),
                _row('Método', selectedMethod),
                const Gap(10),
                _row('Subtotal', CurrencyFormatter.format(plan.price)),
                if (discount > 0) ...[
                  const Gap(10),
                  _row(
                    'Descuento',
                    '− ${CurrencyFormatter.format(discount)}',
                    valueColor: AppColors.primary,
                  ),
                ],
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  child: Container(
                      height: 1,
                      color: Colors.white.withValues(alpha: 0.08)),
                ),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      'Total a pagar',
                      style: GoogleFonts.lexend(
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: Colors.white,
                      ),
                    ),
                    Text(
                      CurrencyFormatter.format(total),
                      style: GoogleFonts.lexend(
                        fontSize: 22,
                        fontWeight: FontWeight.w700,
                        color: AppColors.primary,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _row(String label, String value,
      {Color valueColor = Colors.white70}) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          label,
          style: GoogleFonts.inter(fontSize: 13, color: Colors.white38),
        ),
        Flexible(
          child: Text(
            value,
            style: GoogleFonts.lexend(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: valueColor,
            ),
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ],
    );
  }
}
