import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/product_model.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import '../../../shared/widgets/iron_card.dart';
import '../../../shared/widgets/status_badge.dart';
import 'product_detail_screen.dart';
import 'cart_screen.dart';

class StoreScreen extends StatefulWidget {
  const StoreScreen({super.key});

  @override
  State<StoreScreen> createState() => _StoreScreenState();
}

class _StoreScreenState extends State<StoreScreen> {
  String _search = '';
  String _category = 'Todos';
  final List<CartItem> _cart = [];

  final _categories = ['Todos', 'Suplementos', 'Bebidas', 'Snacks', 'Accesorios'];

  List<ProductModel> get _filtered => mockProducts.where((p) {
        final matchSearch = p.name.toLowerCase().contains(_search.toLowerCase());
        final matchCat = _category == 'Todos' || p.category == _category;
        return matchSearch && matchCat;
      }).toList();

  void _addToCart(ProductModel p) {
    setState(() {
      final existing = _cart.where((c) => c.product.id == p.id).firstOrNull;
      if (existing != null) existing.quantity++;
      else _cart.add(CartItem(product: p));
    });
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text('${p.name} agregado al carrito'),
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      action: SnackBarAction(label: 'Ver carrito', onPressed: () => _openCart()),
    ));
  }

  void _openCart() => Navigator.push(context, MaterialPageRoute(builder: (_) => CartScreen(cart: _cart, onUpdate: () => setState(() {}))));

  @override
  Widget build(BuildContext context) {
    final total = _cart.fold<int>(0, (sum, c) => sum + c.quantity);
    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: IronAppBar(
        title: 'Tienda',
        actions: [
          Stack(
            children: [
              IconButton(onPressed: _openCart, icon: const Icon(Icons.shopping_bag_outlined, color: AppColors.textPrimary)),
              if (total > 0)
                Positioned(
                  right: 6, top: 6,
                  child: Container(
                    width: 18, height: 18,
                    decoration: const BoxDecoration(color: AppColors.error, shape: BoxShape.circle),
                    child: Center(child: Text('$total', style: GoogleFonts.lexend(fontSize: 10, fontWeight: FontWeight.w700, color: AppColors.onDark))),
                  ),
                ),
            ],
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 0),
            child: TextField(
              onChanged: (v) => setState(() => _search = v),
              style: GoogleFonts.inter(fontSize: 14),
              decoration: InputDecoration(
                hintText: 'Buscar producto...',
                hintStyle: GoogleFonts.inter(color: AppColors.textDisabled),
                prefixIcon: const Icon(Icons.search_rounded, color: AppColors.textSecondary, size: 20),
                filled: true,
                fillColor: AppColors.surfaceContainerLow,
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: BorderSide.none),
                contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              ),
            ),
          ),
          const Gap(10),
          SizedBox(
            height: 36,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 20),
              itemCount: _categories.length,
              separatorBuilder: (_, __) => const Gap(8),
              itemBuilder: (_, i) {
                final c = _categories[i];
                final active = c == _category;
                return GestureDetector(
                  onTap: () => setState(() => _category = c),
                  child: AnimatedContainer(
                    duration: 200.ms,
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                    decoration: BoxDecoration(
                      color: active ? AppColors.dark : AppColors.surfaceContainerLow,
                      borderRadius: BorderRadius.circular(99),
                      border: Border.all(color: active ? AppColors.dark : AppColors.border),
                    ),
                    child: Text(c, style: GoogleFonts.lexend(fontSize: 12, fontWeight: FontWeight.w700, color: active ? AppColors.onDark : AppColors.textSecondary)),
                  ),
                );
              },
            ),
          ),
          const Gap(12),
          Expanded(
            child: GridView.builder(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 100),
              gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 2,
                crossAxisSpacing: 12,
                mainAxisSpacing: 12,
                childAspectRatio: 0.75,
              ),
              itemCount: _filtered.length,
              itemBuilder: (_, i) => _ProductCard(
                product: _filtered[i],
                onAdd: () => _addToCart(_filtered[i]),
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => ProductDetailScreen(product: _filtered[i], onAdd: () => _addToCart(_filtered[i])))),
              ).animate().fadeIn(delay: (i * 60).ms),
            ),
          ),
        ],
      ),
    );
  }
}

class _ProductCard extends StatelessWidget {
  final ProductModel product;
  final VoidCallback onAdd;
  final VoidCallback onTap;
  const _ProductCard({required this.product, required this.onAdd, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return IronCard(
      padding: const EdgeInsets.all(12),
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: double.infinity,
            height: 90,
            decoration: BoxDecoration(color: AppColors.surfaceContainerLow, borderRadius: BorderRadius.circular(12)),
            child: Center(child: Icon(product.iconData, size: 48, color: AppColors.textSecondary)),
          ),
          const Gap(10),
          if (product.isLowStock)
            StatusBadge(label: 'Poco stock', variant: BadgeVariant.warning),
          if (product.isLowStock) const Gap(4),
          Text(product.name, style: GoogleFonts.lexend(fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.textPrimary), maxLines: 2, overflow: TextOverflow.ellipsis),
          const Gap(4),
          Text(product.category, style: GoogleFonts.inter(fontSize: 11, color: AppColors.textSecondary)),
          const Spacer(),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(CurrencyFormatter.format(product.price), style: GoogleFonts.lexend(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.textPrimary)),
              GestureDetector(
                onTap: product.isAvailable ? onAdd : null,
                child: Container(
                  width: 32,
                  height: 32,
                  decoration: BoxDecoration(
                    color: product.isAvailable ? AppColors.dark : AppColors.border,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Icon(Icons.add_rounded, size: 18, color: product.isAvailable ? AppColors.onDark : AppColors.textDisabled),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
