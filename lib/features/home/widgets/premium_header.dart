import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/user_model.dart';

class PremiumHeader extends StatelessWidget {
  final UserModel user;
  final int unreadNotifications;
  final VoidCallback onNotificationTap;
  final VoidCallback onAvatarTap;

  const PremiumHeader({
    super.key,
    required this.user,
    required this.unreadNotifications,
    required this.onNotificationTap,
    required this.onAvatarTap,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(20, 12, 16, 20),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Hola, ${user.firstName}',
                  style: GoogleFonts.lexend(
                    fontSize: 24,
                    fontWeight: FontWeight.w800,
                    color: AppColors.textPrimary,
                    height: 1.1,
                  ),
                ).animate().fadeIn(duration: 500.ms).slideX(begin: -0.1),
                const Gap(2),
                Text(
                  'Listo para entrenar hoy',
                  style: GoogleFonts.inter(
                    fontSize: 14,
                    fontWeight: FontWeight.w400,
                    color: AppColors.textSecondary,
                  ),
                ).animate().fadeIn(delay: 100.ms, duration: 500.ms).slideX(begin: -0.1),
              ],
            ),
          ),

          // Notification bell
          GestureDetector(
            onTap: onNotificationTap,
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: BoxDecoration(
                    color: AppColors.surfaceContainerLow,
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: AppColors.border),
                  ),
                  child: Center(
                    child: Lottie.asset(
                      AppAssets.lottieNotificaciones,
                      width: 28,
                      height: 28,
                      repeat: true,
                      fit: BoxFit.contain,
                    ),
                  ),
                ),
                if (unreadNotifications > 0)
                  Positioned(
                    right: -2,
                    top: -2,
                    child: Container(
                      width: 18,
                      height: 18,
                      decoration: const BoxDecoration(
                        color: AppColors.error,
                        shape: BoxShape.circle,
                      ),
                      child: Center(
                        child: Text(
                          '$unreadNotifications',
                          style: GoogleFonts.lexend(
                            fontSize: 9,
                            fontWeight: FontWeight.w800,
                            color: Colors.white,
                          ),
                        ),
                      ),
                    ),
                  ),
              ],
            ).animate().fadeIn(delay: 200.ms).scale(begin: const Offset(0.8, 0.8)),
          ),

          const Gap(10),

          // Avatar button — opens profile
          GestureDetector(
            onTap: onAvatarTap,
            child: Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.primary.withValues(alpha: 0.15),
                border: Border.all(color: AppColors.primary, width: 2),
              ),
              child: Center(
                child: Text(
                  user.firstName.substring(0, 1).toUpperCase(),
                  style: GoogleFonts.lexend(
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                    color: AppColors.primary,
                  ),
                ),
              ),
            ).animate().fadeIn(delay: 250.ms).scale(begin: const Offset(0.8, 0.8)),
          ),
        ],
      ),
    );
  }
}
