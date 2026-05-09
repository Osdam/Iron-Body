import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/workout_model.dart';
import '../../../shared/widgets/app_lottie_icon.dart';
import '../../../shared/widgets/iron_card.dart';
import '../../../shared/widgets/section_header.dart';
import 'active_workout_screen.dart';
import 'exercise_library_screen.dart';

class WorkoutsScreen extends StatelessWidget {
  const WorkoutsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final workouts = mockWorkouts;
    final assigned = workouts.where((w) => w.isAssigned).toList();
    final all = workouts;

    return Scaffold(
      backgroundColor: AppColors.surface0,
      body: CustomScrollView(
        slivers: [
          SliverAppBar(
            backgroundColor: AppColors.surface0,
            elevation: 0,
            pinned: true,
            title: Text('Entrenar', style: GoogleFonts.lexend(fontSize: 20, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
            actions: [
              TextButton.icon(
                onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ExerciseLibraryScreen())),
                icon: AppLottieIcon(path: AppAssets.lottieEvaluacion, size: 22),
                label: Text('Biblioteca', style: GoogleFonts.inter(fontSize: 13, color: AppColors.textSecondary)),
              ),
            ],
          ),
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 120),
            sliver: SliverList(
              delegate: SliverChildListDelegate([
                // Mis rutinas
                SectionHeader(title: 'Mis rutinas asignadas').animate().fadeIn(),
                const Gap(12),
                ...assigned.asMap().entries.map((e) => Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: _WorkoutCard(workout: e.value, highlighted: true)
                      .animate().fadeIn(delay: (100 + e.key * 80).ms).slideX(begin: -0.1),
                )),
                const Gap(12),
                SectionHeader(title: 'Más rutinas', action: 'Nueva', onAction: () {}).animate().fadeIn(delay: 300.ms),
                const Gap(12),
                ...all.where((w) => !w.isAssigned).map((w) => Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: _WorkoutCard(workout: w, highlighted: false).animate().fadeIn(delay: 350.ms),
                )),
              ]),
            ),
          ),
        ],
      ),
    );
  }
}

class _WorkoutCard extends StatefulWidget {
  final WorkoutModel workout;
  final bool highlighted;
  const _WorkoutCard({required this.workout, required this.highlighted});

  @override
  State<_WorkoutCard> createState() => _WorkoutCardState();
}

class _WorkoutCardState extends State<_WorkoutCard> with SingleTickerProviderStateMixin {
  late final AnimationController _playCtrl;

  @override
  void initState() {
    super.initState();
    _playCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 800),
    )..repeat();
  }

  @override
  void dispose() {
    _playCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return IronCard(
      onTap: () => Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => ActiveWorkoutScreen(workout: widget.workout)),
      ),
      backgroundImage: AppAssets.backgroundEntrenar,
      child: Row(
        children: [
          Container(
            width: 52,
            height: 52,
            decoration: BoxDecoration(
              color: widget.highlighted ? AppColors.primary.withValues(alpha: 0.12) : AppColors.surfaceContainerLow,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Center(
              child: Lottie.asset(AppAssets.lottieGym, width: 32, height: 32, repeat: true, fit: BoxFit.contain),
            ),
          ),
          const Gap(14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(widget.workout.name, style: GoogleFonts.lexend(fontSize: 15, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                const Gap(2),
                Text(
                  widget.workout.muscleGroup,
                  style: GoogleFonts.inter(fontSize: 12, color: AppColors.textSecondary),
                ),
                const Gap(6),
                Row(children: [
                  _chip(Icons.timer_outlined, '${widget.workout.estimatedMinutes} min'),
                  const Gap(8),
                  _chip(Icons.repeat_rounded, '${widget.workout.exerciseCount} ejercicios'),
                  const Gap(8),
                  _chip(Icons.star_outline_rounded, widget.workout.level),
                ]),
              ],
            ),
          ),
          const Gap(8),
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              color: AppColors.primary,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Center(
              child: Lottie.asset(
                AppAssets.lottiePlayRutinas,
                controller: _playCtrl,
                width: 30,
                height: 30,
                fit: BoxFit.contain,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _chip(IconData icon, String label) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 11, color: AppColors.textDisabled),
          const Gap(3),
          Text(label, style: GoogleFonts.inter(fontSize: 11, color: AppColors.textDisabled)),
        ],
      );
}
