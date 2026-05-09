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
import 'store_checkout_screen.dart';

class CartScreen extends StatefulWidget {
  final List<CartItem> cart;
  final VoidCallback onUpdate;
  const CartScreen({super.key, required this.cart, required this.onUpdate});

  @override
  State<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends State<CartScreen> {
  double get _total => widget.cart.fold(0, (sum, c) => sum + c.subtotal);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: IronAppBar(title: 'Carrito (${widget.cart.length})'),
      body: widget.cart.isEmpty
          ? Center(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Icon(Icons.shopping_bag_outlined, size: 64, color: AppColors.textDisabled),
                  const Gap(12),
                  Text('Tu carrito está vacío', style: GoogleFonts.lexend(fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.textSecondary)),
                ],
              ),
            )
          : Column(
              children: [
                Expanded(
                  child: ListView.separated(
                    padding: const EdgeInsets.all(20),
                    itemCount: widget.cart.length,
                    separatorBuilder: (_, __) => const Gap(10),
                    itemBuilder: (_, i) {
                      final item = widget.cart[i];
                      return IronCard(
                        child: Row(
                          children: [
                            Container(
                              width: 52,
                              height: 52,
                              decoration: BoxDecoration(color: AppColors.surfaceContainerLow, borderRadius: BorderRadius.circular(12)),
                              child: Center(child: Icon(item.product.iconData, size: 28, color: AppColors.textSecondary)),
                            ),
                            const Gap(12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(item.product.name, style: GoogleFonts.lexend(fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                                  Text(CurrencyFormatter.format(item.product.price), style: GoogleFonts.inter(fontSize: 12, color: AppColors.textSecondary)),
                                ],
                              ),
                            ),
                            Row(children: [
                              _btn(Icons.remove_rounded, () {
                                setState(() {
                                  if (item.quantity > 1) item.quantity--;
                                  else widget.cart.removeAt(i);
                                  widget.onUpdate();
                                });
                              }),
                              Padding(
                                padding: const EdgeInsets.symmetric(horizontal: 10),
                                child: Text('${item.quantity}', style: GoogleFonts.lexend(fontSize: 15, fontWeight: FontWeight.w700)),
                              ),
                              _btn(Icons.add_rounded, () { setState(() { item.quantity++; widget.onUpdate(); }); }),
                            ]),
                          ],
                        ),
                      ).animate().fadeIn(delay: (i * 60).ms);
                    },
                  ),
                ),
                Container(
                  padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
                  decoration: const BoxDecoration(
                    color: AppColors.surface0,
                    border: Border(top: BorderSide(color: AppColors.border)),
                  ),
                  child: Column(
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text('Total', style: GoogleFonts.lexend(fontSize: 16, fontWeight: FontWeight.w700, color: AppColors.textSecondary)),
                          Text(CurrencyFormatter.format(_total), style: GoogleFonts.lexend(fontSize: 22, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
                        ],
                      ),
                      const Gap(14),
                      IronButton(
                        label: 'PROCEDER AL PAGO',
                        onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => StoreCheckoutScreen(cart: widget.cart, total: _total))),
                      ),
                    ],
                  ),
                ),
              ],
            ),
    );
  }

  Widget _btn(IconData icon, VoidCallback onTap) => GestureDetector(
        onTap: onTap,
        child: Container(
          width: 30,
          height: 30,
          decoration: BoxDecoration(color: AppColors.surfaceContainerLow, borderRadius: BorderRadius.circular(8), border: Border.all(color: AppColors.border)),
          child: Icon(icon, size: 16, color: AppColors.textPrimary),
        ),
      );
}
