import 'package:flutter/material.dart';
import 'package:flutter_slidable/flutter_slidable.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/meal_entry.dart';
import '../services/nutrition_service.dart';
import 'food_search_sheet.dart';

class MealSectionCard extends StatefulWidget {
  final MealType mealType;
  final List<MealEntry> entries;
  final VoidCallback onRefresh;

  const MealSectionCard({
    super.key,
    required this.mealType,
    required this.entries,
    required this.onRefresh,
  });

  @override
  State<MealSectionCard> createState() => _MealSectionCardState();
}

class _MealSectionCardState extends State<MealSectionCard> {
  bool _expanded = true;

  double get _totalCalories =>
      widget.entries.fold(0.0, (s, e) => s + e.calories);

  String _bgForMeal(MealType m) => switch (m) {
        MealType.breakfast => AppAssets.desayunoCard,
        MealType.lunch => AppAssets.almuerzoCard,
        MealType.dinner => AppAssets.cenaCard,
        MealType.snacks => AppAssets.meriendaCard,
      };

  String _lottieForMeal(MealType m) => switch (m) {
        MealType.breakfast => AppAssets.lottieDesayuno,
        MealType.lunch => AppAssets.lottieAlmuerzo,
        MealType.dinner => AppAssets.lottieCena,
        MealType.snacks => AppAssets.lottieMerienda,
      };

  @override
  Widget build(BuildContext context) {
    final bg = _bgForMeal(widget.mealType);
    final lottiePath = _lottieForMeal(widget.mealType);

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
              opacity: 0.35,
              child: Image.asset(bg, fit: BoxFit.cover),
            ),
          ),
          Positioned.fill(
            child: Container(
              color: AppColors.surface0.withValues(alpha: 0.48),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                // ── Header ──────────────────────────────────────────────
                GestureDetector(
                  behavior: HitTestBehavior.opaque,
                  onTap: () => setState(() => _expanded = !_expanded),
                  child: Row(
                    children: [
                      Container(
                        width: 40,
                        height: 40,
                        decoration: BoxDecoration(
                          color: AppColors.primary.withValues(alpha: 0.12),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: Padding(
                          padding: const EdgeInsets.all(4),
                          child: Lottie.asset(
                            lottiePath,
                            repeat: true,
                            fit: BoxFit.contain,
                          ),
                        ),
                      ),
                      const Gap(10),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              widget.mealType.displayName,
                              style: GoogleFonts.lexend(
                                fontSize: 14,
                                fontWeight: FontWeight.w700,
                                color: AppColors.textPrimary,
                              ),
                            ),
                            Text(
                              widget.entries.isEmpty
                                  ? 'Sin alimentos'
                                  : '${widget.entries.length} alimento${widget.entries.length != 1 ? 's' : ''}',
                              style: GoogleFonts.inter(
                                  fontSize: 11,
                                  color: AppColors.textSecondary),
                            ),
                          ],
                        ),
                      ),
                      if (_totalCalories > 0) ...[
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 10, vertical: 4),
                          decoration: BoxDecoration(
                            color: AppColors.primary.withValues(alpha: 0.12),
                            borderRadius: BorderRadius.circular(99),
                          ),
                          child: Text(
                            '${_totalCalories.round()} kcal',
                            style: GoogleFonts.inter(
                              fontSize: 11,
                              fontWeight: FontWeight.w700,
                              color: AppColors.primary,
                            ),
                          ),
                        ),
                        const Gap(4),
                      ],
                      Icon(
                        _expanded
                            ? Icons.keyboard_arrow_up_rounded
                            : Icons.keyboard_arrow_down_rounded,
                        color: AppColors.textSecondary,
                        size: 20,
                      ),
                    ],
                  ),
                ),

                // ── Entries + Add button ─────────────────────────────────
                if (_expanded) ...[
                  if (widget.entries.isNotEmpty) ...[
                    const Gap(10),
                    const Divider(color: AppColors.border, height: 1),
                    const Gap(4),
                    SlidableAutoCloseBehavior(
                      child: Column(
                        children: widget.entries
                            .map((e) => FoodEntryTile(
                                  key: ValueKey(e.id),
                                  entry: e,
                                  onDelete: widget.onRefresh,
                                ))
                            .toList(),
                      ),
                    ),
                  ],
                  const Gap(4),
                  SizedBox(
                    width: double.infinity,
                    child: TextButton.icon(
                      onPressed: () => _openFoodSearch(context),
                      icon: const Icon(Icons.add_rounded, size: 16),
                      label: Text(
                        'Agregar alimento',
                        style: GoogleFonts.inter(
                            fontSize: 13, fontWeight: FontWeight.w600),
                      ),
                      style: TextButton.styleFrom(
                        foregroundColor: AppColors.textSecondary,
                        padding: const EdgeInsets.symmetric(vertical: 8),
                      ),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  void _openFoodSearch(BuildContext context) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => FoodSearchSheet(
        mealType: widget.mealType,
        onAdded: widget.onRefresh,
      ),
    );
  }
}

// ─── Food Entry Tile with swipe-to-delete ────────────────────────────────────

class FoodEntryTile extends StatelessWidget {
  final MealEntry entry;
  final VoidCallback onDelete;

  const FoodEntryTile({
    super.key,
    required this.entry,
    required this.onDelete,
  });

  @override
  Widget build(BuildContext context) {
    return Slidable(
      key: ValueKey(entry.id),
      endActionPane: ActionPane(
        motion: const DrawerMotion(),
        extentRatio: 0.22,
        children: [
          CustomSlidableAction(
            onPressed: (_) async {
              await NutritionService.instance.removeEntry(entry.id);
              onDelete();
            },
            backgroundColor: const Color(0xFFFFEBEB),
            foregroundColor: const Color(0xFFD32F2F),
            borderRadius: BorderRadius.circular(10),
            padding: EdgeInsets.zero,
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                SizedBox(
                  width: 30,
                  height: 30,
                  child: Lottie.asset(
                    AppAssets.lottieBorrar,
                    repeat: true,
                    fit: BoxFit.contain,
                  ),
                ),
                const Gap(2),
                const Text(
                  'Borrar',
                  style: TextStyle(fontSize: 10, fontWeight: FontWeight.w600),
                ),
              ],
            ),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    entry.food.name,
                    style: GoogleFonts.inter(
                      fontSize: 13,
                      fontWeight: FontWeight.w600,
                      color: AppColors.textPrimary,
                    ),
                    overflow: TextOverflow.ellipsis,
                  ),
                  Text(
                    '${entry.quantityLabel}  ·  P: ${entry.protein.toStringAsFixed(1)}g  C: ${entry.carbs.toStringAsFixed(1)}g  G: ${entry.fat.toStringAsFixed(1)}g',
                    style: GoogleFonts.inter(
                        fontSize: 11, color: AppColors.textSecondary),
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            const Gap(8),
            Text(
              '${entry.calories.round()} kcal',
              style: GoogleFonts.lexend(
                fontSize: 13,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
            ),
            const Gap(4),
            const Icon(Icons.chevron_left_rounded,
                size: 14, color: AppColors.textDisabled),
          ],
        ),
      ),
    );
  }
}
