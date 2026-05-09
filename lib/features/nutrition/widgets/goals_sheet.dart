import 'package:flutter/material.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/models/nutrition_goals.dart';
import '../services/nutrition_service.dart';

class GoalsSheet extends StatefulWidget {
  final VoidCallback onSaved;
  const GoalsSheet({super.key, required this.onSaved});

  @override
  State<GoalsSheet> createState() => _GoalsSheetState();
}

class _GoalsSheetState extends State<GoalsSheet> {
  late final TextEditingController _calCtrl;
  late final TextEditingController _protCtrl;
  late final TextEditingController _carbCtrl;
  late final TextEditingController _fatCtrl;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    final g = NutritionService.instance.goals;
    _calCtrl = TextEditingController(text: g.calories.round().toString());
    _protCtrl = TextEditingController(text: g.protein.round().toString());
    _carbCtrl = TextEditingController(text: g.carbs.round().toString());
    _fatCtrl = TextEditingController(text: g.fat.round().toString());
  }

  @override
  void dispose() {
    _calCtrl.dispose();
    _protCtrl.dispose();
    _carbCtrl.dispose();
    _fatCtrl.dispose();
    super.dispose();
  }

  bool get _valid =>
      (double.tryParse(_calCtrl.text) ?? -1) > 0 &&
      (double.tryParse(_protCtrl.text) ?? -1) >= 0 &&
      (double.tryParse(_carbCtrl.text) ?? -1) >= 0 &&
      (double.tryParse(_fatCtrl.text) ?? -1) >= 0;

  Future<void> _save() async {
    if (!_valid) return;
    setState(() => _saving = true);
    await NutritionService.instance.saveGoals(NutritionGoals(
      calories: double.parse(_calCtrl.text),
      protein: double.parse(_protCtrl.text),
      carbs: double.parse(_carbCtrl.text),
      fat: double.parse(_fatCtrl.text),
    ));
    if (mounted) {
      widget.onSaved();
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
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Center(
            child: Container(
              width: 36,
              height: 4,
              decoration: BoxDecoration(
                color: AppColors.border,
                borderRadius: BorderRadius.circular(99),
              ),
            ),
          ),
          const Gap(14),
          Text(
            'Meta nutricional diaria',
            style: GoogleFonts.lexend(
              fontSize: 16,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary,
            ),
          ),
          const Gap(4),
          Text(
            'Personaliza tus objetivos de calorías y macros.',
            style: GoogleFonts.inter(
                fontSize: 13, color: AppColors.textSecondary),
          ),
          const Gap(18),
          _GoalField(
            ctrl: _calCtrl,
            label: 'Calorías diarias',
            suffix: 'kcal',
            onChanged: (_) => setState(() {}),
          ),
          const Gap(12),
          Row(
            children: [
              Expanded(
                child: _GoalField(
                  ctrl: _protCtrl,
                  label: 'Proteína',
                  suffix: 'g',
                  onChanged: (_) => setState(() {}),
                ),
              ),
              const Gap(10),
              Expanded(
                child: _GoalField(
                  ctrl: _carbCtrl,
                  label: 'Carbohidratos',
                  suffix: 'g',
                  onChanged: (_) => setState(() {}),
                ),
              ),
              const Gap(10),
              Expanded(
                child: _GoalField(
                  ctrl: _fatCtrl,
                  label: 'Grasas',
                  suffix: 'g',
                  onChanged: (_) => setState(() {}),
                ),
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
                disabledBackgroundColor: AppColors.surfaceContainerLow,
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14)),
                elevation: 0,
              ),
              child: _saving
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                          strokeWidth: 2, color: AppColors.onPrimary),
                    )
                  : Text(
                      'Guardar meta',
                      style: GoogleFonts.lexend(
                          fontSize: 14, fontWeight: FontWeight.w700),
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

class _GoalField extends StatelessWidget {
  final TextEditingController ctrl;
  final String label;
  final String suffix;
  final void Function(String) onChanged;

  const _GoalField({
    required this.ctrl,
    required this.label,
    required this.suffix,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: GoogleFonts.inter(
            fontSize: 12,
            fontWeight: FontWeight.w600,
            color: AppColors.textSecondary,
          ),
        ),
        const Gap(5),
        TextField(
          controller: ctrl,
          onChanged: onChanged,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          style: GoogleFonts.lexend(
              fontSize: 15,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary),
          decoration: InputDecoration(
            suffixText: suffix,
            suffixStyle: GoogleFonts.inter(
                fontSize: 12, color: AppColors.textSecondary),
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
