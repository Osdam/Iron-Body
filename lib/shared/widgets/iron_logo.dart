import 'package:flutter/material.dart';
import '../../core/constants/app_assets.dart';

class IronLogo extends StatelessWidget {
  final double height;
  final double? width;

  const IronLogo({super.key, this.height = 80, this.width});

  @override
  Widget build(BuildContext context) {
    return Image.asset(
      AppAssets.logo,
      height: height,
      width: width,
      fit: BoxFit.contain,
    );
  }
}
