import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../../core/constants/app_assets.dart';
import '../../core/theme/app_colors.dart';

const _kGuideKey = 'app_guide_v2';

// ─────────────────────────────────────────────────────────────────────────────
// DATA MODEL
// ─────────────────────────────────────────────────────────────────────────────

class _TourStep {
  final String? lottie;
  final IconData icon;
  final Color iconBg;
  final Color iconColor;
  final String title;
  final String body;

  const _TourStep({
    this.lottie,
    this.icon = Icons.home_rounded,
    this.iconBg = AppColors.dark,
    this.iconColor = AppColors.primary,
    required this.title,
    required this.body,
  });
}

// ─────────────────────────────────────────────────────────────────────────────
// APP TOUR WIDGET
// ─────────────────────────────────────────────────────────────────────────────

class AppTour extends StatefulWidget {
  const AppTour({super.key});

  // 8 pasos de la guía
  static const _steps = <_TourStep>[
    _TourStep(
      icon: Icons.home_rounded,
      iconBg: AppColors.dark,
      iconColor: AppColors.primary,
      title: 'Tu panel principal',
      body:
          'Aquí ves tu membresía, el entrenamiento del día, accesos rápidos y tu progreso semanal.',
    ),
    _TourStep(
      icon: Icons.workspace_premium_rounded,
      iconBg: AppColors.dark,
      iconColor: AppColors.primary,
      title: 'Tu membresía',
      body:
          'Consulta tu plan activo, los días restantes y renueva tu membresía cuando lo necesites.',
    ),
    _TourStep(
      lottie: AppAssets.lottieRutina,
      title: 'Entrenamiento de hoy',
      body:
          'Inicia tu rutina asignada para hoy y registra tus series directamente desde aquí.',
    ),
    _TourStep(
      icon: Icons.grid_view_rounded,
      iconBg: Color(0xFFEFF4F9),
      iconColor: Color(0xFF444748),
      title: 'Accesos rápidos',
      body:
          'Llega rápido a rutinas, clases, membresía, progreso, IRON IA y tienda sin perder tiempo.',
    ),
    _TourStep(
      lottie: AppAssets.lottieAgenda,
      title: 'Reserva tus clases',
      body:
          'Explora horarios, reserva tu lugar y consulta tus próximas sesiones programadas.',
    ),
    _TourStep(
      lottie: AppAssets.lottieProgreso,
      title: 'Sigue tu progreso',
      body:
          'Revisa tu evolución, entrenamientos completados, racha de días y métricas físicas.',
    ),
    _TourStep(
      lottie: AppAssets.ironAi,
      title: 'IRON IA',
      body:
          'Tu asistente inteligente. Pregúntale sobre rutinas, técnica, progreso y recomendaciones personalizadas.',
    ),
    _TourStep(
      icon: Icons.person_rounded,
      iconBg: AppColors.dark,
      iconColor: AppColors.primary,
      title: 'Tu perfil',
      body:
          'Edita tus datos, revisa tu plan, ajusta preferencias y vuelve a abrir esta guía cuando quieras.',
    ),
  ];

  // ── Public API ──────────────────────────────────────────────────────────────

  static Future<void> show(BuildContext context) => Navigator.of(context).push(
        PageRouteBuilder(
          opaque: false,
          fullscreenDialog: false,
          pageBuilder: (ctx, a, b) => const AppTour(),
          transitionDuration: const Duration(milliseconds: 350),
          transitionsBuilder: (ctx, anim, b, child) =>
              FadeTransition(opacity: anim, child: child),
        ),
      );

  static Future<bool> shouldShow() async {
    final prefs = await SharedPreferences.getInstance();
    return !(prefs.getBool(_kGuideKey) ?? false);
  }

  static Future<void> markShown() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kGuideKey, true);
  }

  static Future<void> reset() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kGuideKey);
  }

  @override
  State<AppTour> createState() => _AppTourState();
}

// ─────────────────────────────────────────────────────────────────────────────
// STATE
// ─────────────────────────────────────────────────────────────────────────────

class _AppTourState extends State<AppTour> with SingleTickerProviderStateMixin {
  int _current = 0;
  late AnimationController _fadeCtrl;

  int get _total => AppTour._steps.length;

  @override
  void initState() {
    super.initState();
    _fadeCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 260),
      value: 1.0,
    );
  }

  @override
  void dispose() {
    _fadeCtrl.dispose();
    super.dispose();
  }

  Future<void> _goTo(int next) async {
    await _fadeCtrl.reverse();
    if (mounted) setState(() => _current = next);
    _fadeCtrl.forward();
  }

  void _next() {
    if (_current < _total - 1) {
      _goTo(_current + 1);
    } else {
      _finish();
    }
  }

  void _back() {
    if (_current > 0) _goTo(_current - 1);
  }

  void _finish() {
    AppTour.markShown();
    Navigator.of(context).pop();
  }

  void _skip() {
    AppTour.markShown();
    Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    final step = AppTour._steps[_current];
    final isFirst = _current == 0;
    final isLast = _current == _total - 1;
    final size = MediaQuery.sizeOf(context);

    return Scaffold(
      backgroundColor: Colors.black.withValues(alpha: 0.78),
      body: SafeArea(
        child: Column(
          children: [
            // ── Top bar: step counter + skip ─────────────────────────────
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 16, 16, 0),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  // Step pill
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 14,
                      vertical: 6,
                    ),
                    decoration: BoxDecoration(
                      color: AppColors.primary.withValues(alpha: 0.15),
                      borderRadius: BorderRadius.circular(99),
                      border: Border.all(
                        color: AppColors.primary.withValues(alpha: 0.35),
                      ),
                    ),
                    child: Text(
                      'PASO ${_current + 1} DE $_total',
                      style: GoogleFonts.lexend(
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                        color: AppColors.primary,
                        letterSpacing: 0.5,
                      ),
                    ),
                  ),
                  // Skip button
                  if (!isLast)
                    TextButton(
                      onPressed: _skip,
                      style: TextButton.styleFrom(
                        foregroundColor: Colors.white.withValues(alpha: 0.6),
                        padding: const EdgeInsets.symmetric(
                          horizontal: 16,
                          vertical: 8,
                        ),
                      ),
                      child: Text(
                        'Omitir',
                        style: GoogleFonts.inter(
                          fontSize: 14,
                          fontWeight: FontWeight.w500,
                          color: Colors.white.withValues(alpha: 0.6),
                        ),
                      ),
                    )
                  else
                    const SizedBox(width: 70),
                ],
              ),
            ),

            // ── Content card ────────────────────────────────────────────
            Expanded(
              child: Center(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 20),
                  child: FadeTransition(
                    opacity: _fadeCtrl,
                    child: _StepCard(
                      key: ValueKey(_current),
                      step: step,
                      maxWidth: size.width - 40,
                    ),
                  ),
                ),
              ),
            ),

            // ── Progress dots ────────────────────────────────────────────
            Padding(
              padding: const EdgeInsets.only(bottom: 20),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: List.generate(_total, (i) {
                  final active = i == _current;
                  return AnimatedContainer(
                    duration: const Duration(milliseconds: 280),
                    margin: const EdgeInsets.symmetric(horizontal: 3.5),
                    width: active ? 28 : 8,
                    height: 8,
                    decoration: BoxDecoration(
                      color: active
                          ? AppColors.primary
                          : Colors.white.withValues(alpha: 0.25),
                      borderRadius: BorderRadius.circular(99),
                    ),
                  );
                }),
              ),
            ),

            // ── Navigation buttons ────────────────────────────────────────
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 28),
              child: Row(
                children: [
                  // Back button
                  AnimatedOpacity(
                    opacity: isFirst ? 0 : 1,
                    duration: const Duration(milliseconds: 200),
                    child: SizedBox(
                      height: 54,
                      child: OutlinedButton(
                        onPressed: isFirst ? null : _back,
                        style: OutlinedButton.styleFrom(
                          foregroundColor: Colors.white,
                          side: BorderSide(
                            color: Colors.white.withValues(alpha: 0.3),
                          ),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(16),
                          ),
                          padding: const EdgeInsets.symmetric(horizontal: 22),
                        ),
                        child: Text(
                          'Atrás',
                          style: GoogleFonts.inter(
                            fontSize: 15,
                            fontWeight: FontWeight.w600,
                            color: Colors.white.withValues(alpha: 0.85),
                          ),
                        ),
                      ),
                    ),
                  ),
                  const Gap(12),
                  // Next / Finish button
                  Expanded(
                    child: SizedBox(
                      height: 54,
                      child: ElevatedButton(
                        onPressed: _next,
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AppColors.primary,
                          foregroundColor: AppColors.dark,
                          elevation: 0,
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(16),
                          ),
                        ),
                        child: Text(
                          isLast ? 'Comenzar' : 'Siguiente',
                          style: GoogleFonts.lexend(
                            fontSize: 15,
                            fontWeight: FontWeight.w700,
                            color: AppColors.dark,
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// STEP CARD
// ─────────────────────────────────────────────────────────────────────────────

class _StepCard extends StatelessWidget {
  final _TourStep step;
  final double maxWidth;

  const _StepCard({super.key, required this.step, required this.maxWidth});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: maxWidth,
      padding: const EdgeInsets.fromLTRB(24, 28, 24, 28),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(28),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.35),
            blurRadius: 40,
            offset: const Offset(0, 16),
          ),
        ],
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          // Icon / Lottie
          _buildVisual()
              .animate()
              .scale(
                begin: const Offset(0.7, 0.7),
                duration: const Duration(milliseconds: 480),
                curve: Curves.elasticOut,
              ),

          const Gap(24),

          // Title
          Text(
            step.title,
            textAlign: TextAlign.center,
            style: GoogleFonts.lexend(
              fontSize: 22,
              fontWeight: FontWeight.w800,
              color: AppColors.textPrimary,
              height: 1.15,
            ),
          ).animate().fadeIn(
                delay: const Duration(milliseconds: 120),
                duration: const Duration(milliseconds: 350),
              ),

          const Gap(12),

          // Body
          Text(
            step.body,
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(
              fontSize: 14,
              height: 1.65,
              color: AppColors.textSecondary,
            ),
          ).animate().fadeIn(
                delay: const Duration(milliseconds: 200),
                duration: const Duration(milliseconds: 350),
              ),
        ],
      ),
    );
  }

  Widget _buildVisual() {
    if (step.lottie != null) {
      return SizedBox(
        width: 100,
        height: 100,
        child: Lottie.asset(step.lottie!, fit: BoxFit.contain, repeat: true),
      );
    }
    return Container(
      width: 90,
      height: 90,
      decoration: BoxDecoration(
        color: step.iconBg,
        borderRadius: BorderRadius.circular(26),
        boxShadow: [
          BoxShadow(
            color: step.iconBg.withValues(alpha: 0.4),
            blurRadius: 24,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Icon(step.icon, color: step.iconColor, size: 44),
    );
  }
}
