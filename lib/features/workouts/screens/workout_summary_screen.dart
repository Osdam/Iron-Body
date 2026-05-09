import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/workout_model.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../../shared/widgets/iron_card.dart';
import '../../../app_shell.dart';

class WorkoutSummaryScreen extends StatelessWidget {
  final WorkoutModel workout;
  final Duration elapsed;
  const WorkoutSummaryScreen({super.key, required this.workout, required this.elapsed});

  @override
  Widget build(BuildContext context) {
    final mins = elapsed.inMinutes;
    final vol = workout.exercises.fold<double>(0, (sum, ex) => sum + (ex.weight * ex.sets * (int.tryParse(ex.reps) ?? 10)));
    final totalSets = workout.exercises.fold<int>(0, (sum, ex) => sum + ex.sets);

    return Scaffold(
      backgroundColor: AppColors.surface0,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(
            children: [
              const Gap(16),
              Container(
                width: 80,
                height: 80,
                decoration: BoxDecoration(color: AppColors.primary, borderRadius: BorderRadius.circular(22)),
                child: Center(
                  child: Lottie.asset(
                    AppAssets.lottieEntrenamientoCompletado,
                    width: 60,
                    height: 60,
                    repeat: true,
                    fit: BoxFit.contain,
                  ),
                ),
              ).animate().scale(begin: const Offset(0.5, 0.5), curve: Curves.elasticOut, duration: 700.ms),
              const Gap(16),
              Text('¡Entrenamiento completado!', style: GoogleFonts.lexend(fontSize: 24, fontWeight: FontWeight.w700, color: AppColors.textPrimary), textAlign: TextAlign.center)
                  .animate().fadeIn(delay: 300.ms).slideY(begin: 0.2),
              const Gap(4),
              Text(workout.name, style: GoogleFonts.inter(fontSize: 15, color: AppColors.textSecondary))
                  .animate().fadeIn(delay: 400.ms),
              const Gap(28),

              // Stats grid
              GridView.count(
                crossAxisCount: 2,
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                crossAxisSpacing: 12,
                mainAxisSpacing: 12,
                childAspectRatio: 1.35,
                children: [
                  _stat(AppAssets.lottieReloj, '$mins min', 'Duración'),
                  _stat(AppAssets.lottieGym, '${vol.round()} kg', 'Volumen total'),
                  _stat(AppAssets.lottieIndexEjercicio, '${workout.exerciseCount}', 'Ejercicios'),
                  _stat(AppAssets.lottieSeries, '$totalSets', 'Series'),
                ],
              ).animate().fadeIn(delay: 500.ms),

              const Gap(20),

              // Músculos trabajados
              IronCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Grupos musculares', style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                    const Gap(12),
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      children: workout.muscleGroup.split(' · ').map((m) => Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                        decoration: BoxDecoration(
                          color: AppColors.primary.withValues(alpha: 0.12),
                          borderRadius: BorderRadius.circular(99),
                        ),
                        child: Text(m, style: GoogleFonts.lexend(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.dark)),
                      )).toList(),
                    ),
                  ],
                ),
              ).animate().fadeIn(delay: 600.ms),

              const Gap(28),
              IronButton(
                label: 'GUARDAR ENTRENAMIENTO',
                onPressed: () => Navigator.pushAndRemoveUntil(
                  context,
                  MaterialPageRoute(builder: (_) => const AppShell()),
                  (_) => false,
                ),
              ).animate().fadeIn(delay: 700.ms),
              const Gap(12),
              IronButton(
                label: 'VOLVER AL INICIO',
                isPrimary: false,
                onPressed: () => Navigator.pushAndRemoveUntil(
                  context,
                  MaterialPageRoute(builder: (_) => const AppShell()),
                  (_) => false,
                ),
              ).animate().fadeIn(delay: 800.ms),
            ],
          ),
        ),
      ),
    );
  }

  Widget _stat(String lottiePath, String value, String label) => IronCard(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Lottie.asset(lottiePath, width: 32, height: 32, repeat: true, fit: BoxFit.contain),
            const Gap(4),
            Text(value, style: GoogleFonts.lexend(fontSize: 18, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
            Text(label, style: GoogleFonts.inter(fontSize: 11, color: AppColors.textSecondary), maxLines: 1, overflow: TextOverflow.ellipsis),
          ],
        ),
      );
}
