import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../models/payment_form_models.dart';

class PaymentMethodSelector extends StatelessWidget {
  final PaymentMethodType selected;
  final ValueChanged<PaymentMethodType> onChanged;

  const PaymentMethodSelector({
    super.key,
    required this.selected,
    required this.onChanged,
  });

  static const _methods = PaymentMethodType.values;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 80,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: EdgeInsets.zero,
        itemCount: _methods.length,
        separatorBuilder: (_, index) => const SizedBox(width: 10),
        itemBuilder: (_, i) {
          final m = _methods[i];
          return _MethodChip(
            method: m,
            isSelected: m == selected,
            onTap: () => onChanged(m),
          ).animate().fadeIn(delay: (i * 50).ms).slideX(begin: 0.15);
        },
      ),
    );
  }
}

String _lottieForMethod(PaymentMethodType method) {
  switch (method) {
    case PaymentMethodType.credit:
      return AppAssets.lottieCredito;
    case PaymentMethodType.debit:
      return AppAssets.lottieDebito;
    case PaymentMethodType.pse:
      return AppAssets.lottiePse;
    case PaymentMethodType.nequi:
      return AppAssets.lottieNequi;
    case PaymentMethodType.daviplata:
      return AppAssets.lottieDaviplata;
  }
}

class _MethodChip extends StatelessWidget {
  final PaymentMethodType method;
  final bool isSelected;
  final VoidCallback onTap;

  const _MethodChip({
    required this.method,
    required this.isSelected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 240),
        curve: Curves.easeInOut,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: isSelected ? AppColors.primary : AppColors.surface0,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color: isSelected ? AppColors.primary : AppColors.border,
            width: isSelected ? 1.5 : 1,
          ),
          boxShadow: isSelected
              ? [
                  BoxShadow(
                    color: AppColors.primary.withValues(alpha: 0.28),
                    blurRadius: 14,
                    offset: const Offset(0, 4),
                  )
                ]
              : [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.04),
                    blurRadius: 4,
                  )
                ],
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            SizedBox(
              width: 26,
              height: 26,
              child: Lottie.asset(
                _lottieForMethod(method),
                repeat: true,
                fit: BoxFit.contain,
              ),
            ),
            const SizedBox(height: 5),
            Text(
              method.label,
              style: GoogleFonts.lexend(
                fontSize: 10,
                fontWeight: isSelected ? FontWeight.w700 : FontWeight.w500,
                color: isSelected ? AppColors.dark : AppColors.textSecondary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
