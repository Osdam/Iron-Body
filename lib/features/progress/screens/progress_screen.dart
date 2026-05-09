import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/constants/app_assets.dart';
import '../../../data/mock/mock_data.dart';
import '../../../shared/widgets/app_lottie_icon.dart';
import '../../../shared/widgets/iron_card.dart';
import '../../../shared/widgets/metric_card.dart';
import '../../../shared/widgets/section_header.dart';
import 'physical_evaluation_screen.dart';

class ProgressScreen extends StatelessWidget {
  const ProgressScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final user = AppSession.currentUser!;

    return Scaffold(
      backgroundColor: AppColors.surface0,
      body: CustomScrollView(
        slivers: [
          SliverAppBar(
            backgroundColor: AppColors.surface0,
            elevation: 0,
            pinned: true,
            title: Text('Progreso', style: GoogleFonts.lexend(fontSize: 20, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
            actions: [
              TextButton.icon(
                onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const PhysicalEvaluationScreen())),
                icon: AppLottieIcon(path: AppAssets.lottieEvaluacion, size: 22),
                label: Text('Evaluación', style: GoogleFonts.inter(fontSize: 13, color: AppColors.textSecondary)),
              ),
            ],
          ),
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 120),
            sliver: SliverList(
              delegate: SliverChildListDelegate([
                // Métricas
                GridView.count(
                  crossAxisCount: 2,
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  crossAxisSpacing: 12,
                  mainAxisSpacing: 12,
                  childAspectRatio: 1.2,
                  children: [
                    MetricCard(
                      label: 'Peso actual',
                      value: '${user.weight}',
                      unit: 'kg',
                      lottiePath: AppAssets.lottieBascula,
                      backgroundImage: AppAssets.backgroundProgreso,
                      trend: '-1.5 kg',
                      trendPositive: true,
                    ),
                    MetricCard(
                      label: 'Entrenamientos',
                      value: '${user.workoutsCompleted}',
                      lottiePath: AppAssets.lottieEntrenamientos,
                      iconBg: AppColors.primary.withValues(alpha: 0.12),
                      backgroundImage: AppAssets.backgroundProgreso,
                      trend: '+5 este mes',
                      trendPositive: true,
                    ),
                    MetricCard(
                      label: 'Racha actual',
                      value: '${user.streak}',
                      unit: 'días',
                      lottiePath: AppAssets.lottieRacha,
                      iconBg: const Color(0xFFFFEDD5),
                      backgroundImage: AppAssets.backgroundProgreso,
                    ),
                    MetricCard(
                      label: 'IMC',
                      value: user.bmi.toStringAsFixed(1),
                      lottiePath: AppAssets.lottieImc,
                      iconBg: const Color(0xFFCFFAFE),
                      backgroundImage: AppAssets.backgroundProgreso,
                    ),
                  ],
                ).animate().fadeIn(),
                const Gap(24),

                SectionHeader(title: 'Evolución de peso').animate().fadeIn(delay: 150.ms),
                const Gap(12),
                _WeightChart().animate().fadeIn(delay: 200.ms),
                const Gap(24),

                SectionHeader(title: 'Volumen semanal').animate().fadeIn(delay: 300.ms),
                const Gap(12),
                _VolumeChart().animate().fadeIn(delay: 350.ms),
                const Gap(24),

                SectionHeader(title: 'Récords personales').animate().fadeIn(delay: 450.ms),
                const Gap(12),
                ..._records.map((r) => Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: _RecordTile(exercise: r.$1, value: r.$2, unit: r.$3).animate().fadeIn(delay: 500.ms),
                )),
              ]),
            ),
          ),
        ],
      ),
    );
  }

  static const _records = [
    ('Press de Banca', '80', 'kg'),
    ('Sentadilla', '100', 'kg'),
    ('Peso Muerto', '120', 'kg'),
    ('Press Militar', '60', 'kg'),
  ];
}

class _WeightChart extends StatelessWidget {
  const _WeightChart();

  @override
  Widget build(BuildContext context) {
    final spots = [82.0, 81.2, 80.5, 80.0, 79.5, 79.0, 78.5].asMap()
        .entries.map((e) => FlSpot(e.key.toDouble(), e.value)).toList();

    return IronCard(
      child: SizedBox(
        height: 160,
        child: LineChart(
          LineChartData(
            gridData: FlGridData(
              show: true,
              drawVerticalLine: false,
              getDrawingHorizontalLine: (v) => FlLine(color: AppColors.border, strokeWidth: 1),
            ),
            titlesData: FlTitlesData(
              bottomTitles: AxisTitles(
                sideTitles: SideTitles(
                  showTitles: true,
                  getTitlesWidget: (v, _) {
                    const labels = ['Sem 1', 'Sem 2', 'Sem 3', 'Sem 4', 'Sem 5', 'Sem 6', 'Hoy'];
                    if (v.toInt() < labels.length) {
                      return Text(labels[v.toInt()], style: GoogleFonts.inter(fontSize: 10, color: AppColors.textDisabled));
                    }
                    return const SizedBox();
                  },
                  reservedSize: 22,
                ),
              ),
              leftTitles: AxisTitles(
                sideTitles: SideTitles(
                  showTitles: true,
                  reservedSize: 36,
                  getTitlesWidget: (v, _) => Text('${v.round()}', style: GoogleFonts.inter(fontSize: 10, color: AppColors.textDisabled)),
                ),
              ),
              topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
              rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
            ),
            borderData: FlBorderData(show: false),
            lineBarsData: [
              LineChartBarData(
                spots: spots,
                isCurved: true,
                color: AppColors.primary,
                barWidth: 3,
                dotData: FlDotData(
                  show: true,
                  getDotPainter: (_, __, ___, ____) => FlDotCirclePainter(radius: 4, color: AppColors.primary, strokeWidth: 2, strokeColor: AppColors.surface0),
                ),
                belowBarData: BarAreaData(
                  show: true,
                  gradient: LinearGradient(
                    colors: [AppColors.primary.withValues(alpha: 0.2), AppColors.primary.withValues(alpha: 0)],
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                  ),
                ),
              ),
            ],
            minY: 77,
            maxY: 83,
          ),
        ),
      ),
    );
  }
}

class _VolumeChart extends StatelessWidget {
  const _VolumeChart();

  @override
  Widget build(BuildContext context) {
    final values = [4200.0, 5100.0, 3800.0, 6200.0, 5500.0, 4900.0, 6800.0];
    return IronCard(
      child: SizedBox(
        height: 140,
        child: BarChart(
          BarChartData(
            alignment: BarChartAlignment.spaceAround,
            gridData: const FlGridData(show: false),
            borderData: FlBorderData(show: false),
            titlesData: FlTitlesData(
              bottomTitles: AxisTitles(
                sideTitles: SideTitles(
                  showTitles: true,
                  getTitlesWidget: (v, _) {
                    const labels = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
                    if (v.toInt() < labels.length) {
                      return Text(labels[v.toInt()], style: GoogleFonts.inter(fontSize: 11, color: AppColors.textDisabled));
                    }
                    return const SizedBox();
                  },
                  reservedSize: 20,
                ),
              ),
              leftTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
              topTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
              rightTitles: const AxisTitles(sideTitles: SideTitles(showTitles: false)),
            ),
            barGroups: values.asMap().entries.map((e) => BarChartGroupData(
              x: e.key,
              barRods: [
                BarChartRodData(
                  toY: e.value / 1000,
                  color: e.key == 6 ? AppColors.primary : AppColors.surfaceContainer,
                  width: 28,
                  borderRadius: BorderRadius.circular(6),
                ),
              ],
            )).toList(),
          ),
        ),
      ),
    );
  }
}

class _RecordTile extends StatelessWidget {
  final String exercise;
  final String value;
  final String unit;
  const _RecordTile({required this.exercise, required this.value, required this.unit});

  @override
  Widget build(BuildContext context) {
    return IronCard(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(color: AppColors.primary.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(10)),
            child: Lottie.asset(AppAssets.lottieEntrenamientoCompletado, width: 24, height: 24, repeat: true, fit: BoxFit.contain),
          ),
          const Gap(12),
          Expanded(child: Text(exercise, style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary))),
          Text('$value $unit', style: GoogleFonts.lexend(fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
        ],
      ),
    );
  }
}
