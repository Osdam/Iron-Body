import 'package:flutter/material.dart';
import '../../core/constants/app_assets.dart';

class AuthBackground extends StatelessWidget {
  final Widget child;
  const AuthBackground({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: [
        Image.asset(AppAssets.sessionBg, fit: BoxFit.cover),
        // White overlay — keeps image visible while text stays readable
        Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [
                Colors.white.withValues(alpha: 0.62),
                Colors.white.withValues(alpha: 0.58),
                Colors.white.withValues(alpha: 0.68),
              ],
              stops: const [0.0, 0.45, 1.0],
            ),
          ),
        ),
        child,
      ],
    );
  }
}
