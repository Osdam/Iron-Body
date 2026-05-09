import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/membership_plan_model.dart';
import '../../../data/models/payment_model.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../../shared/widgets/iron_card.dart';
import '../../../shared/widgets/section_header.dart';
import '../../../shared/widgets/status_badge.dart';
import 'payment_checkout_screen.dart';

class MembershipsScreen extends StatefulWidget {
  const MembershipsScreen({super.key});

  @override
  State<MembershipsScreen> createState() => _MembershipsScreenState();
}

class _MembershipsScreenState extends State<MembershipsScreen> {
  int _selectedPlan = 1;

  static const _planImages = [
    AppAssets.backgroundMembresia2,
    AppAssets.backgroundMembresia3,
    AppAssets.backgroundMembresia4,
    AppAssets.backgroundMembresia5,
  ];

  @override
  Widget build(BuildContext context) {
    final user = AppSession.currentUser!;
    final plans = mockPlans;

    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: const IronAppBar(title: 'Membresía'),
      body: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 100),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Estado actual
            _CurrentStatusCard(user: user).animate().fadeIn().slideY(begin: 0.2),
            const Gap(24),

            SectionHeader(title: 'Elige tu plan').animate().fadeIn(delay: 100.ms),
            const Gap(12),

            // Cards de planes
            ...plans.asMap().entries.map((entry) {
              final i = entry.key;
              final plan = entry.value;
              return Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: _PlanCard(
                  plan: plan,
                  isSelected: _selectedPlan == i,
                  onTap: () => setState(() => _selectedPlan = i),
                  backgroundImage: i < _planImages.length ? _planImages[i] : null,
                ).animate().fadeIn(delay: (150 + i * 80).ms).slideY(begin: 0.15),
              );
            }),

            const Gap(8),
            IronButton(
              label: 'COMPRAR PLAN',
              onPressed: () => Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (_) => PaymentCheckoutScreen(plan: plans[_selectedPlan]),
                ),
              ),
            ).animate().fadeIn(delay: 500.ms),
            const Gap(24),

            // Historial de pagos
            SectionHeader(title: 'Historial de pagos').animate().fadeIn(delay: 600.ms),
            const Gap(12),
            ...mockPayments.map((p) => Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: _PaymentTile(payment: p).animate().fadeIn(delay: 650.ms),
            )),
          ],
        ),
      ),
    );
  }
}

class _CurrentStatusCard extends StatelessWidget {
  final dynamic user;
  const _CurrentStatusCard({required this.user});

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(20),
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.dark,
          borderRadius: BorderRadius.circular(20),
          boxShadow: [BoxShadow(color: AppColors.dark.withValues(alpha: 0.25), blurRadius: 20, offset: const Offset(0, 8))],
        ),
        child: Stack(
          children: [
            Positioned.fill(
              child: Image.asset(AppAssets.backgroundMembresia1, fit: BoxFit.cover),
            ),
            Positioned.fill(
              child: Container(color: AppColors.dark.withValues(alpha: 0.80)),
            ),
            Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text('Mi membresía actual', style: GoogleFonts.inter(fontSize: 13, color: AppColors.onDark.withValues(alpha: 0.6))),
                      const StatusBadge(label: 'Activa', variant: BadgeVariant.success),
                    ],
                  ),
                  const Gap(8),
                  Text(user.planName, style: GoogleFonts.lexend(fontSize: 22, fontWeight: FontWeight.w700, color: AppColors.onDark)),
                  const Gap(4),
                  Text('Vence en ${user.daysRemaining} días', style: GoogleFonts.inter(fontSize: 13, color: AppColors.primary)),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PlanCard extends StatelessWidget {
  final MembershipPlanModel plan;
  final bool isSelected;
  final VoidCallback onTap;
  final String? backgroundImage;

  const _PlanCard({
    required this.plan,
    required this.isSelected,
    required this.onTap,
    this.backgroundImage,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(18),
        child: AnimatedContainer(
          duration: 200.ms,
          decoration: BoxDecoration(
            color: isSelected ? AppColors.dark : AppColors.surface0,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(
              color: isSelected ? AppColors.primary : AppColors.border,
              width: isSelected ? 2 : 1,
            ),
            boxShadow: isSelected
                ? [BoxShadow(color: AppColors.dark.withValues(alpha: 0.2), blurRadius: 16, offset: const Offset(0, 6))]
                : [BoxShadow(color: AppColors.dark.withValues(alpha: 0.05), blurRadius: 8, offset: const Offset(0, 2))],
          ),
          child: Stack(
            children: [
              if (backgroundImage != null) ...[
                Positioned.fill(
                  child: Image.asset(backgroundImage!, fit: BoxFit.cover),
                ),
                Positioned.fill(
                  child: AnimatedContainer(
                    duration: 200.ms,
                    color: isSelected
                        ? AppColors.dark.withValues(alpha: 0.82)
                        : Colors.white.withValues(alpha: 0.90),
                  ),
                ),
              ],
              Padding(
                padding: const EdgeInsets.all(18),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            Text(
                              plan.name,
                              style: GoogleFonts.lexend(
                                fontSize: 16,
                                fontWeight: FontWeight.w700,
                                color: isSelected ? AppColors.onDark : AppColors.textPrimary,
                              ),
                            ),
                            if (plan.badge.isNotEmpty) ...[
                              const Gap(8),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                decoration: BoxDecoration(color: AppColors.primary, borderRadius: BorderRadius.circular(99)),
                                child: Text(plan.badge, style: GoogleFonts.lexend(fontSize: 10, fontWeight: FontWeight.w700, color: AppColors.dark)),
                              ),
                            ],
                          ],
                        ),
                        GestureDetector(
                          onTap: onTap,
                          child: AnimatedContainer(
                            duration: const Duration(milliseconds: 200),
                            width: 22,
                            height: 22,
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              color: isSelected ? AppColors.primary : Colors.transparent,
                              border: Border.all(color: isSelected ? AppColors.primary : AppColors.border, width: 2),
                            ),
                            child: isSelected ? const Icon(Icons.check_rounded, size: 14, color: AppColors.dark) : null,
                          ),
                        ),
                      ],
                    ),
                    const Gap(10),
                    Row(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      children: [
                        Text(
                          CurrencyFormatter.format(plan.price),
                          style: GoogleFonts.lexend(
                            fontSize: 26,
                            fontWeight: FontWeight.w700,
                            color: isSelected ? AppColors.primary : AppColors.textPrimary,
                          ),
                        ),
                        const Gap(4),
                        Padding(
                          padding: const EdgeInsets.only(bottom: 4),
                          child: Text(
                            '/ ${plan.period}',
                            style: GoogleFonts.inter(fontSize: 13, color: isSelected ? AppColors.onDark.withValues(alpha: 0.5) : AppColors.textSecondary),
                          ),
                        ),
                        if (plan.originalPrice != null) ...[
                          const Gap(8),
                          Padding(
                            padding: const EdgeInsets.only(bottom: 4),
                            child: Text(
                              CurrencyFormatter.format(plan.originalPrice!),
                              style: GoogleFonts.inter(
                                fontSize: 13,
                                color: isSelected ? AppColors.onDark.withValues(alpha: 0.4) : AppColors.textDisabled,
                                decoration: TextDecoration.lineThrough,
                              ),
                            ),
                          ),
                        ],
                      ],
                    ),
                    const Gap(12),
                    ...plan.benefits.take(3).map((b) => Padding(
                      padding: const EdgeInsets.only(bottom: 4),
                      child: Row(
                        children: [
                          Icon(Icons.check_circle_rounded, size: 14, color: isSelected ? AppColors.primary : AppColors.textSecondary),
                          const Gap(6),
                          Text(b, style: GoogleFonts.inter(fontSize: 12, color: isSelected ? AppColors.onDark.withValues(alpha: 0.75) : AppColors.textSecondary)),
                        ],
                      ),
                    )),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _PaymentTile extends StatelessWidget {
  final PaymentModel payment;
  const _PaymentTile({required this.payment});

  @override
  Widget build(BuildContext context) {
    final (label, variant) = switch (payment.status) {
      PaymentStatus.approved => ('Aprobado', BadgeVariant.success),
      PaymentStatus.pending  => ('Pendiente', BadgeVariant.warning),
      PaymentStatus.rejected => ('Rechazado', BadgeVariant.error),
    };

    return IronCard(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(color: AppColors.surfaceContainerLow, borderRadius: BorderRadius.circular(12)),
            child: const Icon(Icons.receipt_long_outlined, color: AppColors.textSecondary, size: 20),
          ),
          const Gap(12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(payment.planName, style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                Text(payment.reference, style: GoogleFonts.inter(fontSize: 11, color: AppColors.textDisabled)),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(CurrencyFormatter.format(payment.amount), style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
              const Gap(4),
              StatusBadge(label: label, variant: variant),
            ],
          ),
        ],
      ),
    );
  }
}
