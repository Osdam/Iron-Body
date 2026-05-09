import 'package:flutter/material.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:percent_indicator/circular_percent_indicator.dart';
import 'package:percent_indicator/linear_percent_indicator.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/daily_nutrition_log.dart';
import '../../../data/models/nutrition_goals.dart';

class NutritionSummaryCard extends StatelessWidget {
  final DailyNutritionLog log;
  final NutritionGoals goals;

  const NutritionSummaryCard({
    super.key,
    required this.log,
    required this.goals,
  });

  @override
  Widget build(BuildContext context) {
    final consumed = log.totalCalories;
    final remaining = (goals.calories - consumed).clamp(0.0, goals.calories);
    final calPct = goals.calories > 0
        ? (consumed / goals.calories).clamp(0.0, 1.0)
        : 0.0;

    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
        boxShadow: [
          BoxShadow(
            color: AppColors.dark.withValues(alpha: 0.06),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Stack(
        children: [
          Positioned.fill(
            child: Opacity(
              opacity: 0.42,
              child: Image.asset(AppAssets.nutricionCard, fit: BoxFit.cover),
            ),
          ),
          Positioned.fill(
            child: Container(
              color: AppColors.surface0.withValues(alpha: 0.42),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    CircularPercentIndicator(
                      radius: 58.0,
                      lineWidth: 9.0,
                      percent: calPct,
                      backgroundColor: AppColors.surfaceContainerLow,
                      progressColor: AppColors.primary,
                      circularStrokeCap: CircularStrokeCap.round,
                      center: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Text(
                            consumed.round().toString(),
                            style: GoogleFonts.lexend(
                              fontSize: 20,
                              fontWeight: FontWeight.w800,
                              color: AppColors.textPrimary,
                            ),
                          ),
                          Text(
                            'kcal',
                            style: GoogleFonts.inter(
                              fontSize: 11,
                              color: AppColors.textSecondary,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const Gap(20),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          _CalorieRow(
                            label: 'Meta',
                            value: '${goals.calories.round()} kcal',
                            color: AppColors.textSecondary,
                          ),
                          const Gap(6),
                          _CalorieRow(
                            label: 'Consumidas',
                            value: '${consumed.round()} kcal',
                            color: AppColors.primary,
                            bold: true,
                          ),
                          const Gap(6),
                          _CalorieRow(
                            label: 'Restantes',
                            value: '${remaining.round()} kcal',
                            color: remaining > 0
                                ? const Color(0xFF22C55E)
                                : AppColors.error,
                            bold: true,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                const Gap(20),
                Row(
                  children: [
                    Expanded(
                      child: _MacroBar(
                        label: 'Proteína',
                        consumed: log.totalProtein,
                        goal: goals.protein,
                        color: const Color(0xFF3B82F6),
                        unit: 'g',
                      ),
                    ),
                    const Gap(10),
                    Expanded(
                      child: _MacroBar(
                        label: 'Carbos',
                        consumed: log.totalCarbs,
                        goal: goals.carbs,
                        color: const Color(0xFFF59E0B),
                        unit: 'g',
                      ),
                    ),
                    const Gap(10),
                    Expanded(
                      child: _MacroBar(
                        label: 'Grasas',
                        consumed: log.totalFat,
                        goal: goals.fat,
                        color: const Color(0xFFEF4444),
                        unit: 'g',
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
}

class _CalorieRow extends StatelessWidget {
  final String label;
  final String value;
  final Color color;
  final bool bold;

  const _CalorieRow({
    required this.label,
    required this.value,
    required this.color,
    this.bold = false,
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          label,
          style: GoogleFonts.inter(
            fontSize: 12,
            color: AppColors.textSecondary,
          ),
        ),
        Text(
          value,
          style: GoogleFonts.lexend(
            fontSize: 13,
            fontWeight: bold ? FontWeight.w700 : FontWeight.w500,
            color: color,
          ),
        ),
      ],
    );
  }
}

class _MacroBar extends StatelessWidget {
  final String label;
  final double consumed;
  final double goal;
  final Color color;
  final String unit;

  const _MacroBar({
    required this.label,
    required this.consumed,
    required this.goal,
    required this.color,
    required this.unit,
  });

  @override
  Widget build(BuildContext context) {
    final pct = goal > 0 ? (consumed / goal).clamp(0.0, 1.0) : 0.0;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              label,
              style: GoogleFonts.inter(
                fontSize: 11,
                fontWeight: FontWeight.w600,
                color: AppColors.textPrimary,
              ),
            ),
            Text(
              '${consumed.round()}/${ goal.round()}$unit',
              style: GoogleFonts.inter(
                fontSize: 10,
                color: AppColors.textSecondary,
              ),
            ),
          ],
        ),
        const Gap(5),
        LinearPercentIndicator(
          padding: EdgeInsets.zero,
          lineHeight: 6,
          percent: pct,
          progressColor: color,
          backgroundColor: AppColors.surfaceContainerLow,
          barRadius: const Radius.circular(99),
        ),
      ],
    );
  }
}
