import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/exercise_model.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../../shared/widgets/iron_card.dart';
import '../../../shared/widgets/status_badge.dart';

class ExerciseDetailScreen extends StatelessWidget {
  final ExerciseModel exercise;
  const ExerciseDetailScreen({super.key, required this.exercise});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: IronAppBar(title: exercise.name),
      body: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 100),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Hero placeholder
            Container(
              width: double.infinity,
              height: 200,
              decoration: BoxDecoration(
                color: AppColors.dark,
                borderRadius: BorderRadius.circular(20),
              ),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Icon(Icons.fitness_center_rounded, size: 64, color: AppColors.primary),
                  const Gap(8),
                  Text(exercise.name, style: GoogleFonts.lexend(fontSize: 18, fontWeight: FontWeight.w700, color: AppColors.onDark)),
                ],
              ),
            ).animate().fadeIn(),
            const Gap(20),

            // Tags
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                StatusBadge(label: exercise.muscleGroup, variant: BadgeVariant.info),
                StatusBadge(label: exercise.equipment, variant: BadgeVariant.neutral),
                StatusBadge(
                  label: exercise.difficulty,
                  variant: exercise.difficulty == 'Principiante' ? BadgeVariant.success
                      : exercise.difficulty == 'Avanzado' ? BadgeVariant.error : BadgeVariant.warning,
                ),
              ],
            ).animate().fadeIn(delay: 100.ms),
            const Gap(16),

            Text(exercise.description, style: GoogleFonts.inter(fontSize: 15, height: 1.6, color: AppColors.textSecondary))
                .animate().fadeIn(delay: 150.ms),
            const Gap(24),

            // Técnica
            _Section(title: 'Técnica paso a paso', children: exercise.steps.asMap().entries.map((e) =>
                _StepTile(number: e.key + 1, text: e.value)).toList()
            ).animate().fadeIn(delay: 200.ms),
            const Gap(20),

            // Músculos
            IronCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Músculos', style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                  const Gap(12),
                  _muscle('Principal', exercise.muscleGroup),
                  const Gap(8),
                  if (exercise.secondaryMuscles.isNotEmpty)
                    _muscle('Secundarios', exercise.secondaryMuscles.join(', ')),
                ],
              ),
            ).animate().fadeIn(delay: 250.ms),
            const Gap(20),

            if (exercise.commonMistakes.isNotEmpty)
              _Section(
                title: 'Errores comunes',
                children: exercise.commonMistakes.map((m) => Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Icon(Icons.warning_amber_rounded, size: 16, color: AppColors.error),
                      const Gap(8),
                      Expanded(child: Text(m, style: GoogleFonts.inter(fontSize: 13, color: AppColors.textSecondary))),
                    ],
                  ),
                )).toList(),
              ).animate().fadeIn(delay: 300.ms),

            const Gap(24),
            IronButton(label: 'AGREGAR A RUTINA', onPressed: () => Navigator.pop(context))
                .animate().fadeIn(delay: 350.ms),
          ],
        ),
      ),
    );
  }

  Widget _muscle(String label, String value) => Row(
        children: [
          Text('$label: ', style: GoogleFonts.inter(fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.textSecondary)),
          Text(value, style: GoogleFonts.inter(fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
        ],
      );
}

class _Section extends StatelessWidget {
  final String title;
  final List<Widget> children;
  const _Section({required this.title, required this.children});

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: GoogleFonts.lexend(fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
        const Gap(12),
        ...children,
      ],
    );
  }
}

class _StepTile extends StatelessWidget {
  final int number;
  final String text;
  const _StepTile({required this.number, required this.text});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 26,
            height: 26,
            decoration: BoxDecoration(color: AppColors.primary, borderRadius: BorderRadius.circular(8)),
            child: Center(child: Text('$number', style: GoogleFonts.lexend(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.dark))),
          ),
          const Gap(10),
          Expanded(child: Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(text, style: GoogleFonts.inter(fontSize: 13, height: 1.5, color: AppColors.textSecondary)),
          )),
        ],
      ),
    );
  }
}
