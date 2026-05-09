import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/exercise_model.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import '../../../shared/widgets/iron_card.dart';
import '../../../shared/widgets/status_badge.dart';
import 'exercise_detail_screen.dart';

class ExerciseLibraryScreen extends StatefulWidget {
  const ExerciseLibraryScreen({super.key});

  @override
  State<ExerciseLibraryScreen> createState() => _ExerciseLibraryScreenState();
}

class _ExerciseLibraryScreenState extends State<ExerciseLibraryScreen> {
  String _search = '';
  String _filter = 'Todos';

  final _filters = ['Todos', 'Pecho', 'Espalda', 'Piernas', 'Hombros', 'Brazos', 'Core', 'Cardio'];

  List<ExerciseModel> get _filtered {
    return mockExercises.where((e) {
      final matchSearch = e.name.toLowerCase().contains(_search.toLowerCase()) ||
          e.muscleGroup.toLowerCase().contains(_search.toLowerCase());
      final matchFilter = _filter == 'Todos' || e.muscleGroup == _filter;
      return matchSearch && matchFilter;
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: const IronAppBar(title: 'Biblioteca de ejercicios'),
      body: Column(
        children: [
          // Buscador
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 0),
            child: TextField(
              onChanged: (v) => setState(() => _search = v),
              style: GoogleFonts.inter(fontSize: 14, color: AppColors.textPrimary),
              decoration: InputDecoration(
                hintText: 'Buscar ejercicio...',
                hintStyle: GoogleFonts.inter(color: AppColors.textDisabled),
                prefixIcon: const Icon(Icons.search_rounded, color: AppColors.textSecondary, size: 20),
                filled: true,
                fillColor: AppColors.surfaceContainerLow,
                contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
              ),
            ),
          ),
          const Gap(12),
          // Filtros
          SizedBox(
            height: 36,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 20),
              itemCount: _filters.length,
              separatorBuilder: (_, __) => const Gap(8),
              itemBuilder: (_, i) {
                final f = _filters[i];
                final active = f == _filter;
                return GestureDetector(
                  onTap: () => setState(() => _filter = f),
                  child: AnimatedContainer(
                    duration: 200.ms,
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                    decoration: BoxDecoration(
                      color: active ? AppColors.dark : AppColors.surfaceContainerLow,
                      borderRadius: BorderRadius.circular(99),
                      border: Border.all(color: active ? AppColors.dark : AppColors.border),
                    ),
                    child: Text(
                      f,
                      style: GoogleFonts.lexend(fontSize: 12, fontWeight: FontWeight.w700, color: active ? AppColors.onDark : AppColors.textSecondary),
                    ),
                  ),
                );
              },
            ),
          ),
          const Gap(12),
          // Lista
          Expanded(
            child: ListView.separated(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 100),
              itemCount: _filtered.length,
              separatorBuilder: (_, __) => const Gap(10),
              itemBuilder: (_, i) => _ExerciseCard(exercise: _filtered[i]).animate().fadeIn(delay: (i * 60).ms),
            ),
          ),
        ],
      ),
    );
  }
}

class _ExerciseCard extends StatelessWidget {
  final ExerciseModel exercise;
  const _ExerciseCard({required this.exercise});

  BadgeVariant get _difficultyVariant => switch (exercise.difficulty) {
    'Principiante' => BadgeVariant.success,
    'Avanzado' => BadgeVariant.error,
    _ => BadgeVariant.warning,
  };

  @override
  Widget build(BuildContext context) {
    return IronCard(
      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => ExerciseDetailScreen(exercise: exercise))),
      child: Row(
        children: [
          Container(
            width: 52,
            height: 52,
            decoration: BoxDecoration(color: AppColors.surfaceContainerLow, borderRadius: BorderRadius.circular(14)),
            child: const Icon(Icons.fitness_center_rounded, color: AppColors.textSecondary, size: 24),
          ),
          const Gap(14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(exercise.name, style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                const Gap(2),
                Text('${exercise.muscleGroup} · ${exercise.equipment}', style: GoogleFonts.inter(fontSize: 12, color: AppColors.textSecondary)),
                const Gap(6),
                StatusBadge(label: exercise.difficulty, variant: _difficultyVariant),
              ],
            ),
          ),
          const Icon(Icons.chevron_right_rounded, color: AppColors.textDisabled),
        ],
      ),
    );
  }
}
