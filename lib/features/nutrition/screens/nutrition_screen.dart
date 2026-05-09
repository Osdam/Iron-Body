import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import 'package:shimmer/shimmer.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/meal_entry.dart';
import '../services/nutrition_service.dart';
import '../widgets/nutrition_summary_card.dart';
import '../widgets/nutrition_streak_card.dart';
import '../widgets/meal_section_card.dart';
import '../widgets/weekly_history_card.dart';
import '../widgets/goals_sheet.dart';

class NutritionScreen extends StatefulWidget {
  const NutritionScreen({super.key});

  @override
  State<NutritionScreen> createState() => _NutritionScreenState();
}

class _NutritionScreenState extends State<NutritionScreen> {
  bool _ready = false;

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    await NutritionService.instance.init();
    if (mounted) setState(() => _ready = true);
  }

  void _refresh() => setState(() {});

  void _openGoals() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => GoalsSheet(onSaved: _refresh),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: AppBar(
        backgroundColor: AppColors.surface0,
        elevation: 0,
        surfaceTintColor: Colors.transparent,
        leading: IconButton(
          icon: const Icon(Icons.keyboard_arrow_down_rounded,
              size: 28, color: AppColors.textPrimary),
          onPressed: () => Navigator.pop(context),
        ),
        title: Text(
          'Nutrición',
          style: GoogleFonts.lexend(
            fontSize: 20,
            fontWeight: FontWeight.w700,
            color: AppColors.textPrimary,
          ),
        ),
        actions: [
          IconButton(
            onPressed: _ready ? _openGoals : null,
            icon: SizedBox(
              width: 28,
              height: 28,
              child: Lottie.asset(
                AppAssets.lottieOpcionNutricion,
                repeat: true,
                fit: BoxFit.contain,
              ),
            ),
            tooltip: 'Editar metas',
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: _ready ? _buildBody() : _buildSkeleton(),
    );
  }

  // ── Shimmer skeleton while service initialises ──────────────────────────────
  Widget _buildSkeleton() {
    return Shimmer.fromColors(
      baseColor: AppColors.surfaceContainerLow,
      highlightColor: AppColors.surface0,
      child: SingleChildScrollView(
        physics: const NeverScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 12, 20, 40),
        child: Column(
          children: [
            _SkeletonBox(height: 190, radius: 16),
            const Gap(12),
            _SkeletonBox(height: 76, radius: 16),
            const Gap(20),
            _SkeletonBox(height: 20, radius: 8, width: 120),
            const Gap(10),
            ...List.generate(
              4,
              (_) => Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: _SkeletonBox(height: 72, radius: 16),
              ),
            ),
            const Gap(10),
            _SkeletonBox(height: 20, radius: 8, width: 120),
            const Gap(10),
            _SkeletonBox(height: 190, radius: 16),
          ],
        ),
      ),
    );
  }

  // ── Main content ─────────────────────────────────────────────────────────────
  Widget _buildBody() {
    final svc = NutritionService.instance;
    final log = svc.todayLog;
    final goals = svc.goals;
    final streak = svc.streak;
    final history = svc.getWeeklyHistory();

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 120),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            _todayLabel(),
            style: GoogleFonts.inter(
                fontSize: 13, color: AppColors.textSecondary),
          ),
          const Gap(12),

          NutritionSummaryCard(log: log, goals: goals)
              .animate()
              .fadeIn(duration: 350.ms)
              .slideY(begin: 0.04, curve: Curves.easeOut),
          const Gap(12),

          NutritionStreakCard(streak: streak, log: log, goals: goals)
              .animate()
              .fadeIn(delay: 60.ms, duration: 350.ms)
              .slideY(begin: 0.04, curve: Curves.easeOut),
          const Gap(20),

          Text(
            'Mis comidas',
            style: GoogleFonts.lexend(
              fontSize: 16,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
          const Gap(10),

          ...MealType.values.asMap().entries.map((e) {
            final delay = Duration(milliseconds: 120 + e.key * 55);
            return Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: MealSectionCard(
                mealType: e.value,
                entries: log.entriesFor(e.value),
                onRefresh: _refresh,
              )
                  .animate()
                  .fadeIn(delay: delay, duration: 350.ms)
                  .slideY(begin: 0.04, curve: Curves.easeOut),
            );
          }),

          const Gap(10),

          Text(
            'Historial',
            style: GoogleFonts.lexend(
              fontSize: 16,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
          const Gap(10),

          WeeklyHistoryCard(history: history, goalCalories: goals.calories)
              .animate()
              .fadeIn(delay: 380.ms, duration: 350.ms)
              .slideY(begin: 0.04, curve: Curves.easeOut),

          if (log.entries.isEmpty) ...[
            const Gap(12),
            Center(
              child: Text(
                'Desliza ← en cada alimento para eliminarlo.\nToca "Agregar alimento" en cada comida para empezar.',
                style: GoogleFonts.inter(
                    fontSize: 12, color: AppColors.textDisabled, height: 1.6),
                textAlign: TextAlign.center,
              ),
            ),
          ],
        ],
      ),
    );
  }

  String _todayLabel() {
    final now = DateTime.now();
    const months = [
      'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
      'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];
    const days = [
      'Lunes', 'Martes', 'Miércoles', 'Jueves',
      'Viernes', 'Sábado', 'Domingo',
    ];
    return '${days[now.weekday - 1]}, ${now.day} de ${months[now.month - 1]} ${now.year}';
  }
}

// ─── Skeleton placeholder box ─────────────────────────────────────────────────

class _SkeletonBox extends StatelessWidget {
  final double height;
  final double radius;
  final double? width;

  const _SkeletonBox({required this.height, required this.radius, this.width});

  @override
  Widget build(BuildContext context) {
    return Container(
      height: height,
      width: width ?? double.infinity,
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(radius),
      ),
    );
  }
}
