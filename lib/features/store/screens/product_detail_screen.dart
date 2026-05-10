import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../data/models/product_model.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../../shared/widgets/iron_card.dart';
import '../../../shared/widgets/status_badge.dart';
import 'store_screen.dart' show lottieForProduct;

class ProductDetailScreen extends StatefulWidget {
  final ProductModel product;
  final VoidCallback onAdd;
  const ProductDetailScreen({super.key, required this.product, required this.onAdd});

  @override
  State<ProductDetailScreen> createState() => _ProductDetailScreenState();
}

class _ProductDetailScreenState extends State<ProductDetailScreen> {
  int _qty = 1;

  @override
  Widget build(BuildContext context) {
    final p = widget.product;
    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: IronAppBar(title: p.name),
      body: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 120),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: double.infinity,
              height: 200,
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [Color(0xFFF7F3E0), Color(0xFFFFF8CC)],
                ),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Center(
                child: Lottie.asset(
                  lottieForProduct(p),
                  width: 110,
                  height: 110,
                  repeat: true,
                  fit: BoxFit.contain,
                ),
              ),
            ).animate().fadeIn(),
            const Gap(20),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Text(p.name, style: GoogleFonts.lexend(fontSize: 22, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                ),
                StatusBadge(label: p.category, variant: BadgeVariant.neutral),
              ],
            ).animate().fadeIn(delay: 100.ms),
            const Gap(4),
            Row(children: [
              Text(CurrencyFormatter.format(p.price), style: GoogleFonts.lexend(fontSize: 24, fontWeight: FontWeight.w700, color: AppColors.primary)),
              const Gap(12),
              if (p.isLowStock) StatusBadge(label: 'Poco stock: ${p.stock}', variant: BadgeVariant.warning),
              if (!p.isAvailable) StatusBadge(label: 'Agotado', variant: BadgeVariant.error),
            ]).animate().fadeIn(delay: 150.ms),
            const Gap(20),
            IronCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Descripción', style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                  const Gap(8),
                  Text(p.description, style: GoogleFonts.inter(fontSize: 14, height: 1.6, color: AppColors.textSecondary)),
                ],
              ),
            ).animate().fadeIn(delay: 200.ms),
            const Gap(20),

            // Cantidad
            IronCard(
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Text('Cantidad', style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                  Row(children: [
                    _qtyBtn(Icons.remove_rounded, () { if (_qty > 1) setState(() => _qty--); }),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      child: Text('$_qty', style: GoogleFonts.lexend(fontSize: 18, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                    ),
                    _qtyBtn(Icons.add_rounded, () => setState(() => _qty++)),
                  ]),
                ],
              ),
            ).animate().fadeIn(delay: 250.ms),
          ],
        ),
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 12, 20, 12),
          child: IronButton(
            label: 'AGREGAR AL CARRITO · ${CurrencyFormatter.format(p.price * _qty)}',
            onPressed: p.isAvailable ? () { widget.onAdd(); Navigator.pop(context); } : () {},
          ),
        ),
      ),
    );
  }

  Widget _qtyBtn(IconData icon, VoidCallback onTap) => GestureDetector(
        onTap: onTap,
        child: Container(
          width: 36,
          height: 36,
          decoration: BoxDecoration(color: AppColors.surfaceContainerLow, borderRadius: BorderRadius.circular(10), border: Border.all(color: AppColors.border)),
          child: Icon(icon, size: 18, color: AppColors.textPrimary),
        ),
      );
}
