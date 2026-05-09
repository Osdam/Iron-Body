import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../core/constants/app_assets.dart';
import '../core/theme/app_colors.dart';
import '../shared/widgets/auth_background.dart';
import '../shared/widgets/iron_button.dart';
import '../features/auth/screens/login_screen.dart';
import '../features/auth/screens/register_screen.dart';

class WelcomeScreen extends StatefulWidget {
  const WelcomeScreen({super.key});

  @override
  State<WelcomeScreen> createState() => _WelcomeScreenState();
}

class _WelcomeScreenState extends State<WelcomeScreen>
    with TickerProviderStateMixin {
  late final AnimationController _logoController;
  late final AnimationController _headlineController;
  late final AnimationController _bodyController;
  late final AnimationController _btnsController;

  late final Animation<double> _logoOpacity;
  late final Animation<double> _logoScale;
  late final Animation<double> _headlineOpacity;
  late final Animation<Offset> _headlineSlide;
  late final Animation<double> _bodyOpacity;
  late final Animation<double> _btn1Opacity;
  late final Animation<Offset> _btn1Slide;
  late final Animation<double> _btn2Opacity;
  late final Animation<Offset> _btn2Slide;

  @override
  void initState() {
    super.initState();

    _logoController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 800),
    );
    _headlineController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 650),
    );
    _bodyController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 550),
    );
    _btnsController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 700),
    );

    _logoOpacity = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(parent: _logoController, curve: Curves.easeOut),
    );
    _logoScale = Tween<double>(begin: 0.88, end: 1).animate(
      CurvedAnimation(parent: _logoController, curve: Curves.easeOutCubic),
    );
    _headlineOpacity = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(parent: _headlineController, curve: Curves.easeOut),
    );
    _headlineSlide = Tween<Offset>(
      begin: const Offset(0, 0.25),
      end: Offset.zero,
    ).animate(
      CurvedAnimation(parent: _headlineController, curve: Curves.easeOutCubic),
    );
    _bodyOpacity = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(parent: _bodyController, curve: Curves.easeOut),
    );
    _btn1Opacity = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(
        parent: _btnsController,
        curve: const Interval(0.0, 0.65, curve: Curves.easeOut),
      ),
    );
    _btn1Slide = Tween<Offset>(
      begin: const Offset(0, 0.4),
      end: Offset.zero,
    ).animate(
      CurvedAnimation(
        parent: _btnsController,
        curve: const Interval(0.0, 0.65, curve: Curves.easeOutCubic),
      ),
    );
    _btn2Opacity = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(
        parent: _btnsController,
        curve: const Interval(0.25, 1.0, curve: Curves.easeOut),
      ),
    );
    _btn2Slide = Tween<Offset>(
      begin: const Offset(0, 0.4),
      end: Offset.zero,
    ).animate(
      CurvedAnimation(
        parent: _btnsController,
        curve: const Interval(0.25, 1.0, curve: Curves.easeOutCubic),
      ),
    );

    _playSequence();
  }

  Future<void> _playSequence() async {
    await Future.delayed(const Duration(milliseconds: 80));
    _logoController.forward();
    await Future.delayed(const Duration(milliseconds: 320));
    _headlineController.forward();
    await Future.delayed(const Duration(milliseconds: 260));
    _bodyController.forward();
    await Future.delayed(const Duration(milliseconds: 180));
    _btnsController.forward();
  }

  @override
  void dispose() {
    _logoController.dispose();
    _headlineController.dispose();
    _bodyController.dispose();
    _btnsController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.sizeOf(context);

    return Scaffold(
      backgroundColor: AppColors.surface0,
      body: AuthBackground(
        child: SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 28),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                SizedBox(height: size.height * 0.06),

                // Logo
                FadeTransition(
                  opacity: _logoOpacity,
                  child: ScaleTransition(
                    scale: _logoScale,
                    child: Image.asset(
                      AppAssets.logo,
                      height: size.height * 0.13,
                      fit: BoxFit.contain,
                    ),
                  ),
                ),

                const Spacer(),

                // Headline
                SlideTransition(
                  position: _headlineSlide,
                  child: FadeTransition(
                    opacity: _headlineOpacity,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.center,
                      children: [
                        Text(
                          'Entrena más\ninteligente.',
                          textAlign: TextAlign.center,
                          style: GoogleFonts.lexend(
                            fontSize: size.width < 360 ? 34 : 40,
                            fontWeight: FontWeight.w700,
                            height: 1.1,
                            letterSpacing: -0.8,
                            color: AppColors.textPrimary,
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          'Progresa más rápido.',
                          textAlign: TextAlign.center,
                          style: GoogleFonts.lexend(
                            fontSize: size.width < 360 ? 22 : 28,
                            fontWeight: FontWeight.w700,
                            height: 1.2,
                            color: AppColors.primary,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),

                const SizedBox(height: 20),

                FadeTransition(
                  opacity: _bodyOpacity,
                  child: Text(
                    'Tu asistente de entrenamiento inteligente. Rutinas personalizadas, seguimiento de progreso y asesoría con IA.',
                    textAlign: TextAlign.center,
                    style: GoogleFonts.inter(
                      fontSize: size.width < 360 ? 14 : 16,
                      fontWeight: FontWeight.w400,
                      height: 1.6,
                      color: AppColors.textSecondary,
                    ),
                  ),
                ),

                const Spacer(),

                SlideTransition(
                  position: _btn1Slide,
                  child: FadeTransition(
                    opacity: _btn1Opacity,
                    child: IronButton(
                      label: 'INICIAR SESIÓN',
                      isPrimary: true,
                      onPressed: () => Navigator.push(
                        context,
                        _fadeRoute(const LoginScreen()),
                      ),
                    ),
                  ),
                ),

                const SizedBox(height: 12),

                SlideTransition(
                  position: _btn2Slide,
                  child: FadeTransition(
                    opacity: _btn2Opacity,
                    child: IronButton(
                      label: 'CREAR CUENTA',
                      isPrimary: false,
                      onPressed: () => Navigator.push(
                        context,
                        _fadeRoute(const RegisterScreen()),
                      ),
                    ),
                  ),
                ),

                SizedBox(height: size.height * 0.04),
              ],
            ),
          ),
        ),
      ),
    );
  }

  PageRoute _fadeRoute(Widget page) => PageRouteBuilder(
        pageBuilder: (context, animation, secondaryAnimation) => page,
        transitionDuration: const Duration(milliseconds: 300),
        transitionsBuilder: (context, animation, secondaryAnimation, child) =>
            FadeTransition(opacity: animation, child: child),
      );
}
