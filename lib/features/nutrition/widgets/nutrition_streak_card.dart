import 'package:flutter/material.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/daily_nutrition_log.dart';
import '../../../data/models/nutrition_goals.dart';
import '../../../data/models/nutrition_streak.dart';

class NutritionStreakCard extends StatelessWidget {
  final NutritionStreak streak;
  final DailyNutritionLog log;
  final NutritionGoals goals;

  const NutritionStreakCard({
    super.key,
    required this.streak,
    required this.log,
    required this.goals,
  });

  @override
  Widget build(BuildContext context) {
    final calPct = goals.calories > 0 ? log.totalCalories / goals.calories : 0.0;
    final protPct = goals.protein > 0 ? log.totalProtein / goals.protein : 0.0;

    final String statusLabel;
    final Color statusColor;

    if (calPct >= 0.9 && calPct <= 1.1 && protPct >= 0.85) {
      statusLabel = 'Meta cumplida';
      statusColor = const Color(0xFF22C55E);
    } else if (calPct > 0) {
      statusLabel = 'En progreso';
      statusColor = const Color(0xFFF59E0B);
    } else {
      statusLabel = 'Sin registros hoy';
      statusColor = AppColors.textDisabled;
    }

    final progressPct = (calPct * 100).clamp(0.0, 100.0);

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
              child: Image.asset(AppAssets.rachaNutricional, fit: BoxFit.cover),
            ),
          ),
          Positioned.fill(
            child: Container(
              color: AppColors.surface0.withValues(alpha: 0.42),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(18),
            child: Row(
              children: [
                Container(
                  width: 52,
                  height: 52,
                  decoration: BoxDecoration(
                    color: AppColors.primary.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(6),
                    child: Lottie.asset(
                      AppAssets.lottieRachaNutricion,
                      repeat: true,
                      fit: BoxFit.contain,
                    ),
                  ),
                ),
                const Gap(14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Text(
                            'Racha nutricional',
                            style: GoogleFonts.lexend(
                              fontSize: 14,
                              fontWeight: FontWeight.w700,
                              color: AppColors.textPrimary,
                            ),
                          ),
                          const Spacer(),
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: statusColor.withValues(alpha: 0.12),
                              borderRadius: BorderRadius.circular(99),
                            ),
                            child: Text(
                              statusLabel,
                              style: GoogleFonts.inter(
                                fontSize: 10,
                                fontWeight: FontWeight.w600,
                                color: statusColor,
                              ),
                            ),
                          ),
                        ],
                      ),
                      const Gap(4),
                      Text(
                        '${streak.current} días consecutivos',
                        style: GoogleFonts.inter(
                          fontSize: 12,
                          color: AppColors.textSecondary,
                        ),
                      ),
                      const Gap(6),
                      Row(
                        children: [
                          Expanded(
                            child: ClipRRect(
                              borderRadius: BorderRadius.circular(99),
                              child: LinearProgressIndicator(
                                value: (calPct).clamp(0.0, 1.0),
                                backgroundColor: AppColors.surfaceContainerLow,
                                valueColor: AlwaysStoppedAnimation<Color>(
                                  AppColors.primary,
                                ),
                                minHeight: 6,
                              ),
                            ),
                          ),
                          const Gap(8),
                          Text(
                            '${progressPct.round()}%',
                            style: GoogleFonts.inter(
                              fontSize: 11,
                              fontWeight: FontWeight.w600,
                              color: AppColors.textSecondary,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
