import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import 'package:percent_indicator/linear_percent_indicator.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/user_model.dart';
import '../../../data/models/workout_model.dart';
import '../../../data/models/class_session_model.dart';

// ─────────────────────────────────────────────────────────────────────────────
// MEMBERSHIP HERO CARD
// ─────────────────────────────────────────────────────────────────────────────

class MembershipHeroCard extends StatelessWidget {
  final UserModel user;
  final VoidCallback onRenew;

  const MembershipHeroCard({super.key, required this.user, required this.onRenew});

  @override
  Widget build(BuildContext context) {
    final progress = (user.daysRemaining / 30.0).clamp(0.0, 1.0);
    final isExpiringSoon = user.membershipStatus == MembershipStatus.expiringSoon;
    final isExpired = user.membershipStatus == MembershipStatus.expired;

    return Container(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        boxShadow: [
          BoxShadow(
            color: AppColors.dark.withValues(alpha: 0.28),
            blurRadius: 28,
            offset: const Offset(0, 10),
            spreadRadius: -4,
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(24),
        child: Stack(
          children: [
            // Imagen de fondo
            Positioned.fill(
              child: Image.asset(AppAssets.inicio1, fit: BoxFit.cover),
            ),
            // Capa de contraste mínima para legibilidad del texto blanco
            Positioned.fill(
              child: Container(color: Colors.black.withValues(alpha: 0.42)),
            ),

            Padding(
              padding: const EdgeInsets.all(22),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Título + Badge
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Membresía',
                            style: GoogleFonts.inter(
                              fontSize: 12,
                              color: AppColors.onDark.withValues(alpha: 0.5),
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                          const Gap(2),
                          Text(
                            user.planName,
                            style: GoogleFonts.lexend(
                              fontSize: 20,
                              fontWeight: FontWeight.w800,
                              color: AppColors.onDark,
                            ),
                          ),
                        ],
                      ),
                      _StatusPill(status: user.membershipStatus),
                    ],
                  ),

                  const Gap(20),

                  // Días restantes + progress
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        '${user.daysRemaining} días restantes',
                        style: GoogleFonts.inter(
                          fontSize: 13,
                          color: AppColors.onDark.withValues(alpha: 0.65),
                        ),
                      ),
                      Text(
                        isExpired ? 'VENCIDA' : '${(progress * 100).round()}% del ciclo',
                        style: GoogleFonts.lexend(
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          color: isExpired ? AppColors.error : AppColors.primary,
                        ),
                      ),
                    ],
                  ),
                  const Gap(8),
                  LinearPercentIndicator(
                    percent: progress,
                    lineHeight: 5,
                    backgroundColor: AppColors.onDark.withValues(alpha: 0.12),
                    progressColor: isExpired ? AppColors.error : isExpiringSoon ? const Color(0xFFF59E0B) : AppColors.primary,
                    barRadius: const Radius.circular(99),
                    padding: EdgeInsets.zero,
                    animation: true,
                    animationDuration: 800,
                  ),
                  const Gap(20),

                  // Botón renovar
                  GestureDetector(
                    onTap: onRenew,
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 10),
                      decoration: BoxDecoration(
                        color: AppColors.primary,
                        borderRadius: BorderRadius.circular(99),
                        boxShadow: [
                          BoxShadow(
                            color: AppColors.primary.withValues(alpha: 0.45),
                            blurRadius: 12,
                            offset: const Offset(0, 4),
                          ),
                        ],
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(Icons.refresh_rounded, size: 14, color: AppColors.dark),
                          const Gap(6),
                          Text(
                            'Renovar ahora',
                            style: GoogleFonts.lexend(
                              fontSize: 13,
                              fontWeight: FontWeight.w700,
                              color: AppColors.dark,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    ).animate().fadeIn(delay: 150.ms, duration: 500.ms).slideY(begin: 0.15);
  }
}

class _StatusPill extends StatelessWidget {
  final MembershipStatus status;
  const _StatusPill({required this.status});

  @override
  Widget build(BuildContext context) {
    final (label, color, icon) = switch (status) {
      MembershipStatus.active       => ('Activa',     const Color(0xFF22C55E), Icons.check_circle_rounded),
      MembershipStatus.expiringSoon => ('Por vencer', const Color(0xFFF59E0B), Icons.warning_rounded),
      MembershipStatus.expired      => ('Vencida',    AppColors.error,         Icons.cancel_rounded),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(99),
        border: Border.all(color: color.withValues(alpha: 0.4)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (status == MembershipStatus.active)
            Lottie.asset(AppAssets.lottieCheckGreen, width: 14, height: 14, repeat: true, fit: BoxFit.contain)
          else
            Icon(icon, size: 11, color: color),
          const Gap(4),
          Text(
            label,
            style: GoogleFonts.lexend(fontSize: 11, fontWeight: FontWeight.w700, color: color),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// TODAY'S WORKOUT CARD
// ─────────────────────────────────────────────────────────────────────────────

class WorkoutTodayCard extends StatelessWidget {
  final WorkoutModel workout;
  final VoidCallback onStart;

  const WorkoutTodayCard({super.key, required this.workout, required this.onStart});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onStart,
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(24),
          boxShadow: [
            BoxShadow(
              color: AppColors.dark.withValues(alpha: 0.30),
              blurRadius: 24,
              offset: const Offset(0, 8),
              spreadRadius: -4,
            ),
          ],
        ),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(24),
          child: Stack(
            children: [
              // Imagen de fondo
              Positioned.fill(
                child: Image.asset(AppAssets.inicio2, fit: BoxFit.cover),
              ),
              // Capa de contraste mínima para legibilidad
              Positioned.fill(
                child: Container(color: Colors.black.withValues(alpha: 0.48)),
              ),

              Padding(
                padding: const EdgeInsets.all(20),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Entrenamiento de hoy',
                            style: GoogleFonts.inter(
                              fontSize: 11,
                              fontWeight: FontWeight.w600,
                              color: AppColors.onDark.withValues(alpha: 0.6),
                              letterSpacing: 0.3,
                            ),
                          ),
                          const Gap(4),
                          Text(
                            workout.name,
                            style: GoogleFonts.lexend(
                              fontSize: 20,
                              fontWeight: FontWeight.w800,
                              color: AppColors.onDark,
                              height: 1.1,
                            ),
                          ),
                          const Gap(10),
                          Wrap(
                            spacing: 10,
                            children: [
                              _tag(Icons.fitness_center_rounded, '${workout.exerciseCount} ejercicios'),
                              _tag(Icons.timer_outlined, '${workout.estimatedMinutes} min'),
                              _tag(Icons.signal_cellular_alt_rounded, workout.level),
                            ],
                          ),
                        ],
                      ),
                    ),
                    const Gap(16),
                    // Botón play
                    Container(
                      width: 60,
                      height: 60,
                      decoration: BoxDecoration(
                        color: AppColors.dark,
                        borderRadius: BorderRadius.circular(18),
                        boxShadow: [
                          BoxShadow(
                            color: AppColors.dark.withValues(alpha: 0.3),
                            blurRadius: 12,
                            offset: const Offset(0, 4),
                          ),
                        ],
                      ),
                      child: const Icon(Icons.play_arrow_rounded, color: AppColors.primary, size: 34),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    ).animate().fadeIn(delay: 250.ms, duration: 500.ms).slideY(begin: 0.15);
  }

  Widget _tag(IconData icon, String label) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 12, color: AppColors.onDark.withValues(alpha: 0.6)),
          const Gap(3),
          Text(label, style: GoogleFonts.inter(fontSize: 12, fontWeight: FontWeight.w500, color: AppColors.onDark.withValues(alpha: 0.7))),
        ],
      );
}

// ─────────────────────────────────────────────────────────────────────────────
// QUICK ACTIONS GRID
// ─────────────────────────────────────────────────────────────────────────────

class QuickActionsGrid extends StatelessWidget {
  final List<QuickAction> actions;
  const QuickActionsGrid({super.key, required this.actions});

  @override
  Widget build(BuildContext context) {
    return GridView.builder(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 3,
        crossAxisSpacing: 12,
        mainAxisSpacing: 12,
        childAspectRatio: 0.95,
      ),
      itemCount: actions.length,
      itemBuilder: (_, i) => _QuickActionCard(action: actions[i])
          .animate()
          .fadeIn(delay: (300 + i * 60).ms, duration: 400.ms)
          .scale(begin: const Offset(0.85, 0.85)),
    );
  }
}

class QuickAction {
  final IconData icon;
  final String label;
  final Color iconColor;
  final Color iconBg;
  final VoidCallback onTap;

  const QuickAction({
    required this.icon,
    required this.label,
    required this.iconColor,
    required this.iconBg,
    required this.onTap,
  });
}

class _QuickActionCard extends StatelessWidget {
  final QuickAction action;
  const _QuickActionCard({required this.action});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: action.onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        decoration: BoxDecoration(
          color: AppColors.surface0,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: AppColors.border),
          boxShadow: [
            BoxShadow(
              color: AppColors.dark.withValues(alpha: 0.06),
              blurRadius: 10,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 8),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 48,
              height: 48,
              decoration: BoxDecoration(
                color: action.iconBg,
                borderRadius: BorderRadius.circular(14),
              ),
              child: Icon(action.icon, color: action.iconColor, size: 24),
            ),
            const Gap(8),
            Text(
              action.label,
              textAlign: TextAlign.center,
              maxLines: 2,
              style: GoogleFonts.inter(
                fontSize: 11,
                fontWeight: FontWeight.w600,
                color: AppColors.textSecondary,
                height: 1.3,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// NEXT CLASS CARD
// ─────────────────────────────────────────────────────────────────────────────

class NextClassCard extends StatelessWidget {
  final ClassSessionModel session;
  final VoidCallback onViewAll;

  const NextClassCard({super.key, required this.session, required this.onViewAll});

  @override
  Widget build(BuildContext context) {
    final h = session.dateTime.hour;
    final m = session.dateTime.minute.toString().padLeft(2, '0');
    final ampm = h >= 12 ? 'PM' : 'AM';
    final hour = h > 12 ? h - 12 : (h == 0 ? 12 : h);

    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.border),
        boxShadow: [
          BoxShadow(
            color: AppColors.dark.withValues(alpha: 0.06),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      clipBehavior: Clip.antiAlias,
      child: Stack(
        children: [
          Positioned.fill(
            child: Image.asset(AppAssets.funcionalcard, fit: BoxFit.cover),
          ),
          Positioned.fill(
            child: Container(color: Colors.white.withValues(alpha: 0.55)),
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
                  child: Center(
                    child: Lottie.asset(AppAssets.lottieFuncional, width: 32, height: 32, repeat: true, fit: BoxFit.contain),
                  ),
                ),
                const Gap(14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(session.name, style: GoogleFonts.lexend(fontSize: 15, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                      const Gap(3),
                      Row(children: [
                        _info(Icons.person_outline_rounded, session.instructor),
                        const Gap(10),
                        _info(Icons.access_time_rounded, '$hour:$m $ampm'),
                      ]),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                  decoration: BoxDecoration(
                    color: const Color(0xFFD4EDDA),
                    borderRadius: BorderRadius.circular(99),
                  ),
                  child: Lottie.asset(AppAssets.lottieCheckGreen, width: 20, height: 20, repeat: true, fit: BoxFit.contain),
                ),
              ],
            ),
          ),
        ],
      ),
    ).animate().fadeIn(delay: 400.ms, duration: 400.ms).slideX(begin: 0.1);
  }

  Widget _info(IconData icon, String label) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 12, color: AppColors.textSecondary),
          const Gap(3),
          Text(label, style: GoogleFonts.inter(fontSize: 12, color: AppColors.textSecondary)),
        ],
      );
}

// ─────────────────────────────────────────────────────────────────────────────
// WEEKLY SUMMARY CARD
// ─────────────────────────────────────────────────────────────────────────────

class WeeklySummaryCard extends StatelessWidget {
  final int completed;
  final int goal;
  final int streak;

  const WeeklySummaryCard({
    super.key,
    required this.completed,
    required this.goal,
    required this.streak,
  });

  @override
  Widget build(BuildContext context) {
    final days = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
    final done = [true, true, false, true, false, false, false];

    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.border),
        boxShadow: [
          BoxShadow(color: AppColors.dark.withValues(alpha: 0.06), blurRadius: 12, offset: const Offset(0, 4)),
        ],
      ),
      clipBehavior: Clip.antiAlias,
      child: Stack(
        children: [
          Positioned.fill(
            child: Opacity(
              opacity: 0.50,
              child: Image.asset(AppAssets.semanacard, fit: BoxFit.cover),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Esta semana', style: GoogleFonts.lexend(fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                        Text('$completed de $goal entrenamientos', style: GoogleFonts.inter(fontSize: 12, color: AppColors.textSecondary)),
                      ],
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                      decoration: BoxDecoration(
                        color: AppColors.dark,
                        borderRadius: BorderRadius.circular(99),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Lottie.asset(AppAssets.lottieRacha, width: 18, height: 18, repeat: true, fit: BoxFit.contain),
                          const Gap(4),
                          Text('$streak días', style: GoogleFonts.lexend(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.primary)),
                        ],
                      ),
                    ),
                  ],
                ),
                const Gap(16),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: List.generate(7, (i) {
                    final isDone = done[i];
                    return Column(
                      children: [
                        AnimatedContainer(
                          duration: Duration(milliseconds: 200 + i * 50),
                          width: 36,
                          height: 36,
                          decoration: BoxDecoration(
                            color: isDone ? AppColors.primary : AppColors.surfaceContainerLow,
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(
                              color: isDone ? AppColors.primary : AppColors.border,
                            ),
                          ),
                          child: Center(
                            child: isDone
                                ? const Icon(Icons.check_rounded, size: 18, color: AppColors.dark)
                                : Text(days[i], style: GoogleFonts.inter(fontSize: 11, fontWeight: FontWeight.w600, color: AppColors.textDisabled)),
                          ),
                        ),
                      ],
                    );
                  }),
                ),
              ],
            ),
          ),
        ],
      ),
    ).animate().fadeIn(delay: 500.ms, duration: 400.ms).slideY(begin: 0.1);
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// IRON AI PROMO CARD
// ─────────────────────────────────────────────────────────────────────────────

class IronAiPromoCard extends StatelessWidget {
  final VoidCallback onTap;
  const IronAiPromoCard({super.key, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          color: AppColors.dark,
          borderRadius: BorderRadius.circular(20),
          image: DecorationImage(image: AssetImage(AppAssets.ironCard), fit: BoxFit.cover, opacity: 0.18),
          boxShadow: [
            BoxShadow(color: AppColors.dark.withValues(alpha: 0.25), blurRadius: 20, offset: const Offset(0, 8)),
          ],
        ),
        child: Row(
          children: [
            Container(
              width: 48,
              height: 48,
              decoration: BoxDecoration(color: AppColors.dark, borderRadius: BorderRadius.circular(14)),
              child: Lottie.asset(AppAssets.ironAi, width: 32, height: 32, repeat: true, fit: BoxFit.contain),
            ),
            const Gap(14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('IRON IA recomienda', style: GoogleFonts.lexend(fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.primary)),
                  const Gap(3),
                  Text(
                    'Llevas 3 semanas en press banca con el mismo peso. Sube 2.5 kg esta semana.',
                    style: GoogleFonts.inter(fontSize: 12, height: 1.45, color: AppColors.onDark.withValues(alpha: 0.8)),
                  ),
                ],
              ),
            ),
            const Gap(8),
            const Icon(Icons.chevron_right_rounded, color: AppColors.primary, size: 22),
          ],
        ),
      ),
    ).animate().fadeIn(delay: 600.ms, duration: 400.ms).slideY(begin: 0.1);
  }
}
