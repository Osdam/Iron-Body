import 'package:flutter/material.dart';
import 'package:lottie/lottie.dart';

class AppLottieIcon extends StatelessWidget {
  final String path;
  final double size;

  const AppLottieIcon({super.key, required this.path, this.size = 22});

  @override
  Widget build(BuildContext context) {
    return Lottie.asset(
      path,
      width: size,
      height: size,
      repeat: true,
      fit: BoxFit.contain,
    );
  }
}
