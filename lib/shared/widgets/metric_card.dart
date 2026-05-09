import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../core/theme/app_colors.dart';
import 'iron_card.dart';

class MetricCard extends StatelessWidget {
  final String label;
  final String value;
  final String? unit;
  final IconData? icon;
  final Color? iconColor;
  final Color? iconBg;
  final String? trend;
  final bool trendPositive;
  final String? lottiePath;
  final String? backgroundImage;

  const MetricCard({
    super.key,
    required this.label,
    required this.value,
    this.unit,
    this.icon,
    this.iconColor,
    this.iconBg,
    this.trend,
    this.trendPositive = true,
    this.lottiePath,
    this.backgroundImage,
  });

  @override
  Widget build(BuildContext context) {
    return IronCard(
      padding: const EdgeInsets.all(16),
      backgroundImage: backgroundImage,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: iconBg ?? AppColors.primary.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: lottiePath != null
                    ? Lottie.asset(
                        lottiePath!,
                        repeat: true,
                        fit: BoxFit.contain,
                      )
                    : Icon(
                        icon ?? Icons.circle_outlined,
                        size: 20,
                        color: iconColor ?? AppColors.primary,
                      ),
              ),
              if (trend != null)
                Text(
                  trend!,
                  style: GoogleFonts.inter(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: trendPositive ? const Color(0xFF155724) : AppColors.error,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                value,
                style: GoogleFonts.lexend(
                  fontSize: 24,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
              if (unit != null) ...[
                const SizedBox(width: 2),
                Padding(
                  padding: const EdgeInsets.only(bottom: 3),
                  child: Text(
                    unit!,
                    style: GoogleFonts.inter(
                      fontSize: 13,
                      fontWeight: FontWeight.w500,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: 4),
          Text(
            label,
            style: GoogleFonts.inter(
              fontSize: 12,
              fontWeight: FontWeight.w400,
              color: AppColors.textSecondary,
            ),
          ),
        ],
      ),
    );
  }
}
