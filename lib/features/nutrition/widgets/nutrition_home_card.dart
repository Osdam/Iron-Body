import 'package:flutter/material.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:percent_indicator/circular_percent_indicator.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../services/nutrition_service.dart';
import '../screens/nutrition_screen.dart';

class NutritionHomeCard extends StatefulWidget {
  const NutritionHomeCard({super.key});

  @override
  State<NutritionHomeCard> createState() => _NutritionHomeCardState();
}

class _NutritionHomeCardState extends State<NutritionHomeCard> {
  bool _ready = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    await NutritionService.instance.init();
    if (mounted) setState(() => _ready = true);
  }

  Future<void> _openNutrition() async {
    await Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const NutritionScreen()),
    );
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: _openNutrition,
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.surface0,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: AppColors.border),
          boxShadow: [
            BoxShadow(
              color: AppColors.dark.withValues(alpha: 0.08),
              blurRadius: 14,
              offset: const Offset(0, 5),
            ),
          ],
        ),
        clipBehavior: Clip.antiAlias,
        child: Stack(
          children: [
            // ── Imagen de fondo integrada ─────────────────────────────
            Positioned.fill(
              child: Opacity(
                opacity: 0.22,
                child: Image.asset(
                  AppAssets.nutricionCard,
                  fit: BoxFit.cover,
                ),
              ),
            ),
            // ── Overlay blanco muy sutil para mantener legibilidad ────
            Positioned.fill(
              child: Container(
                color: AppColors.surface0.withValues(alpha: 0.55),
              ),
            ),
            // ── Contenido ─────────────────────────────────────────────
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 16),
              child: _ready ? _buildContent() : _buildSkeleton(),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildContent() {
    final svc = NutritionService.instance;
    final log = svc.todayLog;
    final goals = svc.goals;

    final consumed = log.totalCalories;
    final remaining = (goals.calories - consumed).clamp(0.0, goals.calories);
    final exceeded = consumed > goals.calories;
    final calPct =
        goals.calories > 0 ? (consumed / goals.calories).clamp(0.0, 1.0) : 0.0;
    final hasEntries = log.entries.isNotEmpty;

    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        // ── Gráfica circular ──────────────────────────────────────────
        CircularPercentIndicator(
          radius: 46.0,
          lineWidth: 7.5,
          percent: calPct,
          backgroundColor: AppColors.dark.withValues(alpha: 0.08),
          progressColor: AppColors.primary,
          circularStrokeCap: CircularStrokeCap.round,
          center: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                consumed.round().toString(),
                style: GoogleFonts.lexend(
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                  color: AppColors.textPrimary,
                  height: 1.1,
                ),
              ),
              Text(
                'kcal',
                style: GoogleFonts.inter(
                  fontSize: 9,
                  color: AppColors.textSecondary,
                ),
              ),
            ],
          ),
        ),

        const Gap(14),

        // ── Datos y macros ────────────────────────────────────────────
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              // Título + flecha
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'Nutrición del día',
                      style: GoogleFonts.lexend(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  const Gap(4),
                  const Icon(Icons.chevron_right_rounded,
                      size: 16, color: AppColors.textDisabled),
                ],
              ),
              const Gap(5),

              // Fila meta
              _InfoRow(
                label: 'Meta',
                value: '${goals.calories.round()} kcal',
                valueColor: AppColors.textSecondary,
              ),
              const Gap(2),

              // Fila consumidas
              _InfoRow(
                label: 'Consumidas',
                value: '${consumed.round()} kcal',
                valueColor: AppColors.textPrimary,
                bold: true,
              ),
              const Gap(2),

              // Fila restantes
              _InfoRow(
                label: exceeded ? 'Superadas' : 'Restantes',
                value: exceeded
                    ? '+${(consumed - goals.calories).round()} kcal'
                    : '${remaining.round()} kcal',
                valueColor: exceeded ? AppColors.error : AppColors.primary,
                bold: true,
              ),
              const Gap(8),

              // Píldoras de macros con Wrap → nunca desbordan
              Wrap(
                spacing: 5,
                runSpacing: 5,
                children: [
                  _MacroPill(label: 'P', value: log.totalProtein, goal: goals.protein),
                  _MacroPill(label: 'C', value: log.totalCarbs, goal: goals.carbs),
                  _MacroPill(label: 'G', value: log.totalFat, goal: goals.fat),
                  if (!hasEntries)
                    _TagPill(label: 'Registrar', highlight: true),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildSkeleton() {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Container(
          width: 92,
          height: 92,
          decoration: const BoxDecoration(
            color: AppColors.surfaceContainerLow,
            shape: BoxShape.circle,
          ),
        ),
        const Gap(14),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              _SkeletonBar(width: 100, height: 13),
              const Gap(8),
              _SkeletonBar(height: 10),
              const Gap(5),
              _SkeletonBar(height: 10),
              const Gap(5),
              _SkeletonBar(width: 130, height: 10),
              const Gap(10),
              Row(children: [
                _SkeletonBar(width: 44, height: 20, radius: 99),
                const Gap(5),
                _SkeletonBar(width: 44, height: 20, radius: 99),
                const Gap(5),
                _SkeletonBar(width: 44, height: 20, radius: 99),
              ]),
            ],
          ),
        ),
      ],
    );
  }
}

// ─── Sub-widgets ──────────────────────────────────────────────────────────────

class _InfoRow extends StatelessWidget {
  final String label;
  final String value;
  final Color valueColor;
  final bool bold;

  const _InfoRow({
    required this.label,
    required this.value,
    required this.valueColor,
    this.bold = false,
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Text(
          label,
          style: GoogleFonts.inter(
            fontSize: 11,
            color: AppColors.textSecondary,
          ),
        ),
        const Spacer(),
        Flexible(
          child: Text(
            value,
            style: GoogleFonts.lexend(
              fontSize: 11,
              fontWeight: bold ? FontWeight.w700 : FontWeight.w500,
              color: valueColor,
            ),
            overflow: TextOverflow.ellipsis,
            textAlign: TextAlign.right,
          ),
        ),
      ],
    );
  }
}

// Píldora de macro: usa solo la paleta Iron Body
class _MacroPill extends StatelessWidget {
  final String label;
  final double value;
  final double goal;

  const _MacroPill({
    required this.label,
    required this.value,
    required this.goal,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
      decoration: BoxDecoration(
        color: AppColors.dark.withValues(alpha: 0.07),
        borderRadius: BorderRadius.circular(99),
        border: Border.all(color: AppColors.dark.withValues(alpha: 0.10)),
      ),
      child: Text(
        '$label  ${value.toStringAsFixed(0)}/${goal.toStringAsFixed(0)}g',
        style: GoogleFonts.inter(
          fontSize: 10,
          fontWeight: FontWeight.w600,
          color: AppColors.textPrimary,
        ),
      ),
    );
  }
}

class _TagPill extends StatelessWidget {
  final String label;
  final bool highlight;

  const _TagPill({required this.label, this.highlight = false});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
      decoration: BoxDecoration(
        color: highlight
            ? AppColors.primary.withValues(alpha: 0.15)
            : AppColors.dark.withValues(alpha: 0.07),
        borderRadius: BorderRadius.circular(99),
      ),
      child: Text(
        label,
        style: GoogleFonts.inter(
          fontSize: 10,
          fontWeight: FontWeight.w700,
          color: highlight ? AppColors.primary : AppColors.textSecondary,
        ),
      ),
    );
  }
}

class _SkeletonBar extends StatelessWidget {
  final double? width;
  final double height;
  final double radius;

  const _SkeletonBar({
    this.width,
    required this.height,
    this.radius = 6,
  });

  @override
  Widget build(BuildContext context) => Container(
        width: width,
        height: height,
        decoration: BoxDecoration(
          color: AppColors.surfaceContainerLow,
          borderRadius: BorderRadius.circular(radius),
        ),
      );
}
