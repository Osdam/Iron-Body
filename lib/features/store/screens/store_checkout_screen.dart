import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../data/models/product_model.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../../shared/widgets/iron_card.dart';
import '../../../app_shell.dart';

class StoreCheckoutScreen extends StatefulWidget {
  final List<CartItem> cart;
  final double total;
  const StoreCheckoutScreen({super.key, required this.cart, required this.total});

  @override
  State<StoreCheckoutScreen> createState() => _StoreCheckoutScreenState();
}

class _StoreCheckoutScreenState extends State<StoreCheckoutScreen> {
  bool _processing = false;

  Future<void> _pay() async {
    setState(() => _processing = true);
    await Future.delayed(const Duration(milliseconds: 1500));
    if (!mounted) return;
    widget.cart.clear();
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => Dialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 72,
                height: 72,
                decoration: const BoxDecoration(color: AppColors.primary, shape: BoxShape.circle),
                child: const Icon(Icons.check_rounded, size: 40, color: AppColors.dark),
              ).animate().scale(begin: const Offset(0.5, 0.5), curve: Curves.elasticOut, duration: 700.ms),
              const Gap(16),
              Text('¡Compra exitosa!', style: GoogleFonts.lexend(fontSize: 20, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
              const Gap(4),
              Text(CurrencyFormatter.format(widget.total), style: GoogleFonts.lexend(fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.primary)),
              const Gap(4),
              Text('Tu pedido será entregado en caja.', style: GoogleFonts.inter(fontSize: 13, color: AppColors.textSecondary), textAlign: TextAlign.center),
              const Gap(20),
              IronButton(label: 'IR AL INICIO', onPressed: () => Navigator.pushAndRemoveUntil(context, MaterialPageRoute(builder: (_) => const AppShell()), (_) => false)),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: const IronAppBar(title: 'Pagar pedido'),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Resumen del pedido', style: GoogleFonts.lexend(fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.textPrimary)).animate().fadeIn(),
            const Gap(12),
            IronCard(
              child: Column(
                children: [
                  ...widget.cart.map((item) => Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text('${item.product.name} x${item.quantity}', style: GoogleFonts.inter(fontSize: 13, color: AppColors.textPrimary)),
                        Text(CurrencyFormatter.format(item.subtotal), style: GoogleFonts.lexend(fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                      ],
                    ),
                  )),
                  const Divider(color: AppColors.border),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text('Total', style: GoogleFonts.lexend(fontSize: 16, fontWeight: FontWeight.w700)),
                      Text(CurrencyFormatter.format(widget.total), style: GoogleFonts.lexend(fontSize: 18, fontWeight: FontWeight.w700, color: AppColors.primary)),
                    ],
                  ),
                ],
              ),
            ).animate().fadeIn(delay: 100.ms),
            const Gap(20),

            IronCard(
              child: Row(
                children: [
                  const Icon(Icons.credit_card_rounded, color: AppColors.textSecondary),
                  const Gap(12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Método de pago', style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                        Text('Wompi · Tarjeta · Nequi · PSE', style: GoogleFonts.inter(fontSize: 12, color: AppColors.textSecondary)),
                      ],
                    ),
                  ),
                ],
              ),
            ).animate().fadeIn(delay: 150.ms),
            const Gap(32),

            if (_processing)
              const Center(child: CircularProgressIndicator(color: AppColors.primary))
            else
              IronButton(label: 'PAGAR ${CurrencyFormatter.format(widget.total)}', onPressed: _pay)
                  .animate().fadeIn(delay: 200.ms),
          ],
        ),
      ),
    );
  }
}
