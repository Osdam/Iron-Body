import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';

class WeeklyHistoryCard extends StatelessWidget {
  final List<({String date, double calories, bool goalMet})> history;
  final double goalCalories;

  const WeeklyHistoryCard({
    super.key,
    required this.history,
    required this.goalCalories,
  });

  static const _dayNames = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];

  String get _todayStr =>
      DateTime.now().toIso8601String().substring(0, 10);

  @override
  Widget build(BuildContext context) {
    if (history.isEmpty) return const SizedBox.shrink();

    final maxVal = history
        .map((h) => h.calories)
        .fold(goalCalories, (a, b) => a > b ? a : b);
    final chartMax = (maxVal * 1.15).ceilToDouble();

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
              opacity: 0.25,
              child: Image.asset(
                AppAssets.historialNutricionCard,
                fit: BoxFit.cover,
              ),
            ),
          ),
          Positioned.fill(
            child: Container(
              color: AppColors.surface0.withValues(alpha: 0.62),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 18, 12, 16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.only(right: 6),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        'Historial semanal',
                        style: GoogleFonts.lexend(
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                        ),
                      ),
                      _Legend(),
                    ],
                  ),
                ),
                const Gap(16),
                SizedBox(
                  height: 140,
                  child: BarChart(
                    BarChartData(
                      maxY: chartMax,
                      minY: 0,
                      alignment: BarChartAlignment.spaceAround,
                      barGroups: _buildBarGroups(),
                      titlesData: FlTitlesData(
                        bottomTitles: AxisTitles(
                          sideTitles: SideTitles(
                            showTitles: true,
                            reservedSize: 24,
                            getTitlesWidget: (value, meta) {
                              final i = value.toInt();
                              if (i < 0 || i >= history.length) {
                                return const SizedBox.shrink();
                              }
                              final weekday =
                                  DateTime.parse(history[i].date).weekday;
                              final isToday = history[i].date == _todayStr;
                              return SideTitleWidget(
                                axisSide: meta.axisSide,
                                child: Text(
                                  _dayNames[(weekday - 1) % 7],
                                  style: GoogleFonts.inter(
                                    fontSize: 11,
                                    fontWeight: isToday
                                        ? FontWeight.w700
                                        : FontWeight.w400,
                                    color: isToday
                                        ? AppColors.textPrimary
                                        : AppColors.textSecondary,
                                  ),
                                ),
                              );
                            },
                          ),
                        ),
                        leftTitles: const AxisTitles(
                            sideTitles: SideTitles(showTitles: false)),
                        topTitles: const AxisTitles(
                            sideTitles: SideTitles(showTitles: false)),
                        rightTitles: const AxisTitles(
                            sideTitles: SideTitles(showTitles: false)),
                      ),
                      gridData: FlGridData(
                        show: true,
                        drawVerticalLine: false,
                        horizontalInterval: chartMax / 4,
                        getDrawingHorizontalLine: (_) => FlLine(
                          color: AppColors.border,
                          strokeWidth: 1,
                          dashArray: [4, 4],
                        ),
                      ),
                      borderData: FlBorderData(show: false),
                      extraLinesData: goalCalories > 0
                          ? ExtraLinesData(
                              horizontalLines: [
                                HorizontalLine(
                                  y: goalCalories,
                                  color:
                                      AppColors.primary.withValues(alpha: 0.5),
                                  strokeWidth: 1.5,
                                  dashArray: [5, 4],
                                  label: HorizontalLineLabel(
                                    show: true,
                                    alignment: Alignment.topRight,
                                    labelResolver: (_) =>
                                        '${(goalCalories / 1000).toStringAsFixed(1)}k',
                                    style: GoogleFonts.inter(
                                      fontSize: 9,
                                      fontWeight: FontWeight.w600,
                                      color: AppColors.primary,
                                    ),
                                  ),
                                ),
                              ],
                            )
                          : null,
                      barTouchData: BarTouchData(
                        touchTooltipData: BarTouchTooltipData(
                          getTooltipColor: (_) => AppColors.dark,
                          tooltipRoundedRadius: 8,
                          getTooltipItem: (group, _, rod, __) {
                            final cal = rod.toY.round();
                            return BarTooltipItem(
                              '$cal kcal',
                              GoogleFonts.lexend(
                                fontSize: 11,
                                fontWeight: FontWeight.w700,
                                color: AppColors.primary,
                              ),
                            );
                          },
                        ),
                      ),
                    ),
                    duration: const Duration(milliseconds: 500),
                    curve: Curves.easeOutCubic,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  List<BarChartGroupData> _buildBarGroups() {
    return history.asMap().entries.map((e) {
      final day = e.value;
      final isToday = day.date == _todayStr;
      Color color;
      if (day.calories == 0) {
        color = AppColors.surfaceContainerLow;
      } else if (day.goalMet) {
        color = const Color(0xFF22C55E);
      } else {
        color = AppColors.primary;
      }

      return BarChartGroupData(
        x: e.key,
        barRods: [
          BarChartRodData(
            toY: day.calories == 0 ? 0.5 : day.calories,
            color: color,
            width: isToday ? 24 : 20,
            borderRadius: const BorderRadius.only(
              topLeft: Radius.circular(6),
              topRight: Radius.circular(6),
            ),
            backDrawRodData: BackgroundBarChartRodData(
              show: true,
              toY: 0.5,
              color: Colors.transparent,
            ),
          ),
        ],
      );
    }).toList();
  }
}

class _Legend extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        _Dot(color: const Color(0xFF22C55E)),
        const Gap(4),
        Text('Meta',
            style: GoogleFonts.inter(
                fontSize: 10, color: AppColors.textSecondary)),
        const Gap(10),
        _Dot(color: AppColors.primary),
        const Gap(4),
        Text('Progreso',
            style: GoogleFonts.inter(
                fontSize: 10, color: AppColors.textSecondary)),
      ],
    );
  }
}

class _Dot extends StatelessWidget {
  final Color color;
  const _Dot({required this.color});

  @override
  Widget build(BuildContext context) => Container(
        width: 8,
        height: 8,
        decoration: BoxDecoration(color: color, shape: BoxShape.circle),
      );
}
