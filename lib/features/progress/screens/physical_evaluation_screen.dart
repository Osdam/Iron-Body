import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../../shared/widgets/iron_card.dart';
import '../../../shared/widgets/iron_input.dart';

class PhysicalEvaluationScreen extends StatefulWidget {
  const PhysicalEvaluationScreen({super.key});

  @override
  State<PhysicalEvaluationScreen> createState() => _PhysicalEvaluationScreenState();
}

class _PhysicalEvaluationScreenState extends State<PhysicalEvaluationScreen> {
  final _weightCtrl = TextEditingController(text: '78.5');
  final _heightCtrl = TextEditingController(text: '175');
  final _fatCtrl = TextEditingController(text: '18');
  final _muscleCtrl = TextEditingController(text: '38');
  final _waistCtrl = TextEditingController(text: '82');
  final _hipCtrl = TextEditingController(text: '94');
  final _chestCtrl = TextEditingController(text: '102');
  final _armCtrl = TextEditingController(text: '37');
  final _legCtrl = TextEditingController(text: '58');

  double get _bmi {
    final w = double.tryParse(_weightCtrl.text) ?? 0;
    final h = (double.tryParse(_heightCtrl.text) ?? 1) / 100;
    return h == 0 ? 0 : w / (h * h);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: IronAppBar(
        title: 'Evaluación física',
        actions: [
          TextButton(
            onPressed: () {},
            child: Text('Historial', style: GoogleFonts.inter(fontSize: 13, color: AppColors.textSecondary)),
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 100),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // IMC en tiempo real
            _BmiCard(bmi: _bmi).animate().fadeIn(),
            const Gap(20),

            _sectionTitle('Composición corporal'),
            const Gap(12),
            Row(children: [
              Expanded(child: IronInput(label: 'Peso (kg)', controller: _weightCtrl, keyboardType: TextInputType.number, onChanged: (_) => setState(() {}))),
              const Gap(12),
              Expanded(child: IronInput(label: 'Estatura (cm)', controller: _heightCtrl, keyboardType: TextInputType.number, onChanged: (_) => setState(() {}))),
            ]),
            const Gap(12),
            Row(children: [
              Expanded(child: IronInput(label: '% Grasa', controller: _fatCtrl, keyboardType: TextInputType.number)),
              const Gap(12),
              Expanded(child: IronInput(label: 'Masa muscular %', controller: _muscleCtrl, keyboardType: TextInputType.number)),
            ]),
            const Gap(20),

            _sectionTitle('Medidas (cm)'),
            const Gap(12),
            Row(children: [
              Expanded(child: IronInput(label: 'Cintura', controller: _waistCtrl, keyboardType: TextInputType.number)),
              const Gap(12),
              Expanded(child: IronInput(label: 'Cadera', controller: _hipCtrl, keyboardType: TextInputType.number)),
            ]),
            const Gap(12),
            Row(children: [
              Expanded(child: IronInput(label: 'Pecho', controller: _chestCtrl, keyboardType: TextInputType.number)),
              const Gap(12),
              Expanded(child: IronInput(label: 'Brazo', controller: _armCtrl, keyboardType: TextInputType.number)),
            ]),
            const Gap(12),
            IronInput(label: 'Pierna', controller: _legCtrl, keyboardType: TextInputType.number),
            const Gap(20),

            _sectionTitle('Notas clínicas'),
            const Gap(12),
            IronInput(label: 'Lesiones o restricciones', hint: 'Ninguna...', maxLines: 3),
            const Gap(12),
            IronInput(label: 'Observaciones del entrenador', hint: 'Sin observaciones...', maxLines: 3),
            const Gap(28),

            IronButton(
              label: 'GUARDAR EVALUACIÓN',
              onPressed: () => Navigator.pop(context),
            ).animate().fadeIn(delay: 200.ms),
          ],
        ),
      ),
    );
  }

  Widget _sectionTitle(String t) => Text(
        t,
        style: GoogleFonts.lexend(fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.textPrimary),
      );
}

class _BmiCard extends StatelessWidget {
  final double bmi;
  const _BmiCard({required this.bmi});

  String get _category {
    if (bmi < 18.5) return 'Bajo peso';
    if (bmi < 25) return 'Normal';
    if (bmi < 30) return 'Sobrepeso';
    return 'Obesidad';
  }

  Color get _color {
    if (bmi < 18.5) return const Color(0xFF0891B2);
    if (bmi < 25) return const Color(0xFF16A34A);
    if (bmi < 30) return const Color(0xFFD97706);
    return AppColors.error;
  }

  @override
  Widget build(BuildContext context) {
    return IronCard(
      child: Row(
        children: [
          Container(
            width: 64,
            height: 64,
            decoration: BoxDecoration(
              color: _color.withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(18),
            ),
            child: Center(
              child: Text(
                bmi.toStringAsFixed(1),
                style: GoogleFonts.lexend(fontSize: 20, fontWeight: FontWeight.w800, color: _color),
              ),
            ),
          ),
          const Gap(16),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Índice de Masa Corporal', style: GoogleFonts.inter(fontSize: 12, color: AppColors.textSecondary)),
              Text(_category, style: GoogleFonts.lexend(fontSize: 18, fontWeight: FontWeight.w700, color: _color)),
              Text('Calculado en tiempo real', style: GoogleFonts.inter(fontSize: 11, color: AppColors.textDisabled)),
            ],
          ),
        ],
      ),
    );
  }
}
