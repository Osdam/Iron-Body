import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'app_colors.dart';

class AppTheme {
  static ThemeData get light {
    final base = ThemeData(
      useMaterial3: true,
      colorScheme: const ColorScheme(
        brightness: Brightness.light,
        primary: AppColors.primary,
        onPrimary: AppColors.onPrimary,
        secondary: AppColors.dark,
        onSecondary: AppColors.onDark,
        error: AppColors.error,
        onError: AppColors.onDark,
        surface: AppColors.surface0,
        onSurface: AppColors.onSurface,
        surfaceContainerLow: AppColors.surfaceContainerLow,
        surfaceContainer: AppColors.surfaceContainer,
        surfaceContainerHighest: AppColors.surfaceVariant,
        onSurfaceVariant: AppColors.onSurfaceVariant,
        inverseSurface: AppColors.inverseSurface,
        onInverseSurface: AppColors.inverseOnSurface,
        outline: AppColors.border,
      ),
    );

    return base.copyWith(
      textTheme: GoogleFonts.lexendTextTheme(base.textTheme).copyWith(
        // H1
        displayLarge: GoogleFonts.lexend(
          fontSize: 40,
          fontWeight: FontWeight.w700,
          height: 1.1,
          letterSpacing: -0.02 * 40,
          color: AppColors.textPrimary,
        ),
        // H2
        displayMedium: GoogleFonts.lexend(
          fontSize: 32,
          fontWeight: FontWeight.w700,
          height: 1.2,
          letterSpacing: -0.01 * 32,
          color: AppColors.textPrimary,
        ),
        // H3
        displaySmall: GoogleFonts.lexend(
          fontSize: 24,
          fontWeight: FontWeight.w600,
          height: 1.3,
          color: AppColors.textPrimary,
        ),
        // Body Large
        bodyLarge: GoogleFonts.inter(
          fontSize: 18,
          fontWeight: FontWeight.w400,
          height: 1.6,
          color: AppColors.textPrimary,
        ),
        // Body Medium
        bodyMedium: GoogleFonts.inter(
          fontSize: 16,
          fontWeight: FontWeight.w400,
          height: 1.5,
          color: AppColors.textPrimary,
        ),
        // Body Small
        bodySmall: GoogleFonts.inter(
          fontSize: 14,
          fontWeight: FontWeight.w500,
          height: 1.4,
          color: AppColors.textSecondary,
        ),
        // Label Caps
        labelSmall: GoogleFonts.lexend(
          fontSize: 12,
          fontWeight: FontWeight.w700,
          height: 1,
          letterSpacing: 0.05 * 12,
          color: AppColors.textPrimary,
        ),
      ),
      scaffoldBackgroundColor: AppColors.surface0,
      appBarTheme: AppBarTheme(
        backgroundColor: AppColors.surface0,
        elevation: 0,
        scrolledUnderElevation: 0,
        systemOverlayStyle: SystemUiOverlayStyle.dark,
        titleTextStyle: GoogleFonts.lexend(
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: AppColors.textPrimary,
        ),
        iconTheme: const IconThemeData(color: AppColors.textPrimary),
      ),
    );
  }
}
