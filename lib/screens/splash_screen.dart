import 'package:flutter/material.dart';
import '../core/constants/app_assets.dart';
import '../data/mock/mock_data.dart';
import '../app_shell.dart';
import 'welcome_screen.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  late final Animation<double> _opacity;
  late final Animation<double> _scale;

  @override
  void initState() {
    super.initState();

    _ctrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 900),
    );

    _opacity = CurvedAnimation(parent: _ctrl, curve: Curves.easeIn);

    _scale = Tween<double>(begin: 0.82, end: 1.0).animate(
      CurvedAnimation(parent: _ctrl, curve: Curves.easeOutCubic),
    );

    _ctrl.forward();
    _navigateAfterDelay();
  }

  Future<void> _navigateAfterDelay() async {
    await Future.delayed(const Duration(milliseconds: 2600));
    if (!mounted) return;

    final dest = AppSession.currentUser != null
        ? const AppShell()
        : const WelcomeScreen();

    Navigator.of(context).pushReplacement(
      PageRouteBuilder(
        pageBuilder: (ctx, a, b) => dest,
        transitionDuration: const Duration(milliseconds: 500),
        transitionsBuilder: (ctx, anim, b, child) =>
            FadeTransition(opacity: anim, child: child),
      ),
    );
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(
        fit: StackFit.expand,
        children: [
          // Background image
          Image.asset(AppAssets.sessionBg, fit: BoxFit.cover),

          // Dark overlay for premium look and logo readability
          Container(color: Colors.black.withValues(alpha: 0.50)),

          // Logo centrado
          Center(
            child: FadeTransition(
              opacity: _opacity,
              child: ScaleTransition(
                scale: _scale,
                child: Image.asset(
                  AppAssets.logo,
                  height: MediaQuery.sizeOf(context).width * 0.45,
                  fit: BoxFit.contain,
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
