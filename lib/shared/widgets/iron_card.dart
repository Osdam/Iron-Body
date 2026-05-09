import 'package:flutter/material.dart';
import '../../core/theme/app_colors.dart';

class IronCard extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry? padding;
  final Color? color;
  final double radius;
  final VoidCallback? onTap;
  final Border? border;
  final String? backgroundImage;
  final double backgroundImageOpacity;

  const IronCard({
    super.key,
    required this.child,
    this.padding,
    this.color,
    this.radius = 16,
    this.onTap,
    this.border,
    this.backgroundImage,
    this.backgroundImageOpacity = 0.07,
  });

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: Ink(
        decoration: BoxDecoration(
          color: color ?? AppColors.surface0,
          borderRadius: BorderRadius.circular(radius),
          border: border ?? Border.all(color: AppColors.border),
          image: backgroundImage != null
              ? DecorationImage(
                  image: AssetImage(backgroundImage!),
                  fit: BoxFit.cover,
                  opacity: backgroundImageOpacity,
                )
              : null,
          boxShadow: [
            BoxShadow(
              color: AppColors.dark.withValues(alpha: 0.06),
              blurRadius: 12,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(radius),
          child: Padding(
            padding: padding ?? const EdgeInsets.all(16),
            child: child,
          ),
        ),
      ),
    );
  }
}
