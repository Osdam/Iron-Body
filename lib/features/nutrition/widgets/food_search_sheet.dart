import 'package:flutter/material.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/food_item.dart';
import '../../../data/models/meal_entry.dart';
import '../../../data/nutrition/food_library.dart';
import '../services/nutrition_service.dart';

class FoodSearchSheet extends StatefulWidget {
  final MealType mealType;
  final VoidCallback onAdded;

  const FoodSearchSheet({
    super.key,
    required this.mealType,
    required this.onAdded,
  });

  @override
  State<FoodSearchSheet> createState() => _FoodSearchSheetState();
}

class _FoodSearchSheetState extends State<FoodSearchSheet> {
  final _searchCtrl = TextEditingController();
  final _qtyCtrl = TextEditingController();
  FoodItem? _selected;
  List<FoodItem> _filtered = [];
  bool _adding = false;

  @override
  void initState() {
    super.initState();
    _refresh('');
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    _qtyCtrl.dispose();
    super.dispose();
  }

  void _refresh(String q) {
    final all = [
      ...foodLibrary,
      ...NutritionService.instance.customFoods,
    ];
    setState(() {
      _filtered = q.isEmpty
          ? all
          : all
              .where((f) =>
                  f.name.toLowerCase().contains(q.toLowerCase()))
              .toList();
    });
  }

  void _select(FoodItem food) {
    setState(() {
      _selected = food;
      _qtyCtrl.text = food.baseQuantity.toStringAsFixed(0);
    });
  }

  double get _qty => double.tryParse(_qtyCtrl.text) ?? 0;

  Future<void> _add() async {
    if (_selected == null || _qty <= 0) return;
    setState(() => _adding = true);
    final entry = MealEntry(
      id: DateTime.now().millisecondsSinceEpoch.toString(),
      mealType: widget.mealType,
      food: _selected!,
      quantity: _qty,
    );
    await NutritionService.instance.addEntry(entry);
    if (mounted) {
      widget.onAdded();
      Navigator.pop(context);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.of(context).viewInsets.bottom;
    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      padding: EdgeInsets.fromLTRB(20, 12, 20, 20 + bottom),
      child: AnimatedSwitcher(
        duration: const Duration(milliseconds: 220),
        child: _selected == null ? _buildSearch() : _buildQuantity(),
      ),
    );
  }

  Widget _buildSearch() {
    return Column(
      key: const ValueKey('search'),
      mainAxisSize: MainAxisSize.min,
      children: [
        // Handle bar
        Center(
          child: Container(
            width: 36, height: 4,
            decoration: BoxDecoration(
              color: AppColors.border,
              borderRadius: BorderRadius.circular(99),
            ),
          ),
        ),
        const Gap(14),
        Row(
          children: [
            Expanded(
              child: Text(
                'Agregar a ${widget.mealType.displayName}',
                style: GoogleFonts.lexend(
                  fontSize: 16, fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
              ),
            ),
            IconButton(
              onPressed: () => _showCreateFood(context),
              icon: SizedBox(
                width: 28,
                height: 28,
                child: Lottie.asset(
                  AppAssets.lottieMas,
                  repeat: true,
                  fit: BoxFit.contain,
                ),
              ),
              tooltip: 'Crear alimento',
            ),
          ],
        ),
        const Gap(10),
        TextField(
          controller: _searchCtrl,
          autofocus: true,
          onChanged: _refresh,
          style: GoogleFonts.inter(fontSize: 14, color: AppColors.textPrimary),
          decoration: InputDecoration(
            hintText: 'Buscar alimento…',
            hintStyle: GoogleFonts.inter(
                fontSize: 14, color: AppColors.textDisabled),
            prefixIcon: const Icon(Icons.search_rounded,
                color: AppColors.textDisabled),
            filled: true,
            fillColor: AppColors.surfaceContainerLow,
            contentPadding:
                const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: BorderSide.none,
            ),
          ),
        ),
        const Gap(10),
        ConstrainedBox(
          constraints: BoxConstraints(
            maxHeight: MediaQuery.of(context).size.height * 0.45,
          ),
          child: _filtered.isEmpty
              ? Padding(
                  padding: const EdgeInsets.symmetric(vertical: 24),
                  child: Center(
                    child: Text(
                      'Sin resultados. Crea un alimento personalizado.',
                      style: GoogleFonts.inter(
                          fontSize: 13, color: AppColors.textSecondary),
                      textAlign: TextAlign.center,
                    ),
                  ),
                )
              : ListView.builder(
                  shrinkWrap: true,
                  itemCount: _filtered.length,
                  itemBuilder: (_, i) {
                    final f = _filtered[i];
                    return ListTile(
                      contentPadding:
                          const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
                      leading: Container(
                        width: 36, height: 36,
                        decoration: BoxDecoration(
                          color: f.isCustom
                              ? AppColors.primary.withValues(alpha: 0.12)
                              : AppColors.surfaceContainerLow,
                          borderRadius: BorderRadius.circular(9),
                        ),
                        child: Padding(
                          padding: const EdgeInsets.all(4),
                          child: Lottie.asset(
                            AppAssets.lottieComida,
                            repeat: true,
                            fit: BoxFit.contain,
                          ),
                        ),
                      ),
                      title: Text(
                        f.name,
                        style: GoogleFonts.inter(
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                          color: AppColors.textPrimary,
                        ),
                      ),
                      subtitle: Text(
                        '${f.calories.round()} kcal · ${f.baseQuantity.round()}${f.unit}  |  P: ${f.protein.toStringAsFixed(1)}g  C: ${f.carbs.toStringAsFixed(1)}g  G: ${f.fat.toStringAsFixed(1)}g',
                        style: GoogleFonts.inter(
                            fontSize: 11, color: AppColors.textSecondary),
                      ),
                      onTap: () => _select(f),
                    );
                  },
                ),
        ),
      ],
    );
  }

  Widget _buildQuantity() {
    final food = _selected!;
    final qty = _qty;
    final previewCal = qty > 0 ? food.scaledCalories(qty) : 0.0;
    final previewProt = qty > 0 ? food.scaledProtein(qty) : 0.0;
    final previewCarbs = qty > 0 ? food.scaledCarbs(qty) : 0.0;
    final previewFat = qty > 0 ? food.scaledFat(qty) : 0.0;

    return Column(
      key: const ValueKey('quantity'),
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Center(
          child: Container(
            width: 36, height: 4,
            decoration: BoxDecoration(
              color: AppColors.border,
              borderRadius: BorderRadius.circular(99),
            ),
          ),
        ),
        const Gap(14),
        Row(
          children: [
            GestureDetector(
              onTap: () => setState(() => _selected = null),
              child: const Icon(Icons.arrow_back_rounded,
                  size: 20, color: AppColors.textSecondary),
            ),
            const Gap(10),
            Expanded(
              child: Text(
                food.name,
                style: GoogleFonts.lexend(
                  fontSize: 16, fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                ),
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ],
        ),
        const Gap(16),
        Text(
          'Cantidad (${food.unit})',
          style: GoogleFonts.inter(
              fontSize: 13, fontWeight: FontWeight.w600,
              color: AppColors.textPrimary),
        ),
        const Gap(8),
        TextField(
          controller: _qtyCtrl,
          keyboardType:
              const TextInputType.numberWithOptions(decimal: true),
          onChanged: (_) => setState(() {}),
          style: GoogleFonts.lexend(
              fontSize: 20, fontWeight: FontWeight.w700,
              color: AppColors.textPrimary),
          decoration: InputDecoration(
            suffixText: food.unit,
            suffixStyle: GoogleFonts.inter(
                fontSize: 14, color: AppColors.textSecondary),
            filled: true,
            fillColor: AppColors.surfaceContainerLow,
            contentPadding:
                const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: BorderSide.none,
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide:
                  const BorderSide(color: AppColors.primary, width: 2),
            ),
          ),
        ),
        const Gap(16),
        // Preview
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: AppColors.surfaceContainerLow,
            borderRadius: BorderRadius.circular(14),
          ),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: [
              _PreviewMacro(label: 'Calorías',
                  value: '${previewCal.round()}', unit: 'kcal'),
              _PreviewMacro(label: 'Proteína',
                  value: previewProt.toStringAsFixed(1), unit: 'g'),
              _PreviewMacro(label: 'Carbos',
                  value: previewCarbs.toStringAsFixed(1), unit: 'g'),
              _PreviewMacro(label: 'Grasas',
                  value: previewFat.toStringAsFixed(1), unit: 'g'),
            ],
          ),
        ),
        const Gap(20),
        SizedBox(
          width: double.infinity,
          height: 52,
          child: ElevatedButton(
            onPressed: (_qty > 0 && !_adding) ? _add : null,
            style: ElevatedButton.styleFrom(
              backgroundColor: AppColors.primary,
              foregroundColor: AppColors.onPrimary,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(14)),
              elevation: 0,
            ),
            child: _adding
                ? const SizedBox(
                    width: 20, height: 20,
                    child: CircularProgressIndicator(
                        strokeWidth: 2, color: AppColors.onPrimary),
                  )
                : Text(
                    'Agregar',
                    style: GoogleFonts.lexend(
                        fontSize: 14, fontWeight: FontWeight.w700),
                  ),
          ),
        ),
      ],
    );
  }

  void _showCreateFood(BuildContext context) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _CreateFoodSheet(
        onCreated: (food) async {
          await NutritionService.instance.addCustomFood(food);
          _refresh(_searchCtrl.text);
        },
      ),
    );
  }
}

class _PreviewMacro extends StatelessWidget {
  final String label;
  final String value;
  final String unit;

  const _PreviewMacro(
      {required this.label, required this.value, required this.unit});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text(
          '$value $unit',
          style: GoogleFonts.lexend(
              fontSize: 15, fontWeight: FontWeight.w700,
              color: AppColors.textPrimary),
        ),
        Text(
          label,
          style: GoogleFonts.inter(
              fontSize: 10, color: AppColors.textSecondary),
        ),
      ],
    );
  }
}

// ─── Create Custom Food Sheet ─────────────────────────────────────────────────

class _CreateFoodSheet extends StatefulWidget {
  final Future<void> Function(FoodItem) onCreated;
  const _CreateFoodSheet({required this.onCreated});

  @override
  State<_CreateFoodSheet> createState() => _CreateFoodSheetState();
}

class _CreateFoodSheetState extends State<_CreateFoodSheet> {
  final _nameCtrl = TextEditingController();
  final _calCtrl = TextEditingController();
  final _protCtrl = TextEditingController();
  final _carbCtrl = TextEditingController();
  final _fatCtrl = TextEditingController();
  final _qtyCtrl = TextEditingController(text: '100');
  bool _saving = false;

  @override
  void dispose() {
    _nameCtrl.dispose();
    _calCtrl.dispose();
    _protCtrl.dispose();
    _carbCtrl.dispose();
    _fatCtrl.dispose();
    _qtyCtrl.dispose();
    super.dispose();
  }

  bool get _valid =>
      _nameCtrl.text.trim().isNotEmpty &&
      (double.tryParse(_calCtrl.text) ?? -1) >= 0 &&
      (double.tryParse(_protCtrl.text) ?? -1) >= 0 &&
      (double.tryParse(_carbCtrl.text) ?? -1) >= 0 &&
      (double.tryParse(_fatCtrl.text) ?? -1) >= 0 &&
      (double.tryParse(_qtyCtrl.text) ?? 0) > 0;

  Future<void> _save() async {
    if (!_valid) return;
    setState(() => _saving = true);
    final food = FoodItem(
      id: 'custom_${DateTime.now().millisecondsSinceEpoch}',
      name: _nameCtrl.text.trim(),
      calories: double.parse(_calCtrl.text),
      protein: double.parse(_protCtrl.text),
      carbs: double.parse(_carbCtrl.text),
      fat: double.parse(_fatCtrl.text),
      baseQuantity: double.parse(_qtyCtrl.text),
      unit: 'g',
      isCustom: true,
    );
    await widget.onCreated(food);
    if (mounted) Navigator.pop(context);
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.of(context).viewInsets.bottom;
    return Container(
      decoration: const BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      padding: EdgeInsets.fromLTRB(20, 12, 20, 20 + bottom),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Center(
              child: Container(
                width: 36, height: 4,
                decoration: BoxDecoration(
                  color: AppColors.border,
                  borderRadius: BorderRadius.circular(99),
                ),
              ),
            ),
            const Gap(14),
            Text(
              'Crear alimento personalizado',
              style: GoogleFonts.lexend(
                fontSize: 16, fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
            ),
            const Gap(16),
            _Field(ctrl: _nameCtrl, label: 'Nombre del alimento',
                hint: 'ej. Proteína casera', onChanged: (_) => setState(() {})),
            const Gap(10),
            Row(
              children: [
                Expanded(
                  child: _Field(ctrl: _calCtrl, label: 'Calorías',
                      hint: '0', numeric: true, onChanged: (_) => setState(() {})),
                ),
                const Gap(10),
                Expanded(
                  child: _Field(ctrl: _qtyCtrl, label: 'Porción base (g)',
                      hint: '100', numeric: true, onChanged: (_) => setState(() {})),
                ),
              ],
            ),
            const Gap(10),
            Row(
              children: [
                Expanded(
                  child: _Field(ctrl: _protCtrl, label: 'Proteína (g)',
                      hint: '0', numeric: true, onChanged: (_) => setState(() {})),
                ),
                const Gap(10),
                Expanded(
                  child: _Field(ctrl: _carbCtrl, label: 'Carbos (g)',
                      hint: '0', numeric: true, onChanged: (_) => setState(() {})),
                ),
                const Gap(10),
                Expanded(
                  child: _Field(ctrl: _fatCtrl, label: 'Grasas (g)',
                      hint: '0', numeric: true, onChanged: (_) => setState(() {})),
                ),
              ],
            ),
            const Gap(20),
            SizedBox(
              width: double.infinity,
              height: 52,
              child: ElevatedButton(
                onPressed: (_valid && !_saving) ? _save : null,
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.primary,
                  foregroundColor: AppColors.onPrimary,
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(14)),
                  elevation: 0,
                  disabledBackgroundColor:
                      AppColors.surfaceContainerLow,
                ),
                child: _saving
                    ? const SizedBox(
                        width: 20, height: 20,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: AppColors.onPrimary),
                      )
                    : Text('Guardar alimento',
                        style: GoogleFonts.lexend(
                            fontSize: 14, fontWeight: FontWeight.w700)),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Field extends StatelessWidget {
  final TextEditingController ctrl;
  final String label;
  final String hint;
  final bool numeric;
  final void Function(String) onChanged;

  const _Field({
    required this.ctrl,
    required this.label,
    required this.hint,
    this.numeric = false,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label,
            style: GoogleFonts.inter(
                fontSize: 12, fontWeight: FontWeight.w600,
                color: AppColors.textSecondary)),
        const Gap(5),
        TextField(
          controller: ctrl,
          onChanged: onChanged,
          keyboardType: numeric
              ? const TextInputType.numberWithOptions(decimal: true)
              : TextInputType.text,
          style: GoogleFonts.inter(
              fontSize: 14, color: AppColors.textPrimary),
          decoration: InputDecoration(
            hintText: hint,
            hintStyle: GoogleFonts.inter(color: AppColors.textDisabled),
            filled: true,
            fillColor: AppColors.surfaceContainerLow,
            contentPadding:
                const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(10),
              borderSide: BorderSide.none,
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: BorderRadius.circular(10),
              borderSide: const BorderSide(color: AppColors.primary),
            ),
          ),
        ),
      ],
    );
  }
}
