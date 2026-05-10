import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/product_model.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import '../../../shared/widgets/status_badge.dart';
import 'product_detail_screen.dart';
import 'cart_screen.dart';

String lottieForProduct(ProductModel p) {
  switch (p.category) {
    case 'Suplementos':
      return AppAssets.lottieProductoSuplemento;
    case 'Accesorios':
      return AppAssets.lottieProductoAccesorio;
    case 'Bebidas':
      return AppAssets.lottieProductoBebida;
    case 'Snacks':
      return AppAssets.lottieProductoSnack;
    default:
      return AppAssets.lottieTienda;
  }
}

class StoreScreen extends StatefulWidget {
  const StoreScreen({super.key});

  @override
  State<StoreScreen> createState() => _StoreScreenState();
}

class _StoreScreenState extends State<StoreScreen> {
  String _search = '';
  String _category = 'Todos';
  final List<CartItem> _cart = [];

  final _categories = [
    'Todos',
    'Suplementos',
    'Bebidas',
    'Snacks',
    'Accesorios'
  ];

  List<ProductModel> get _filtered => mockProducts.where((p) {
        final matchSearch =
            p.name.toLowerCase().contains(_search.toLowerCase());
        final matchCat = _category == 'Todos' || p.category == _category;
        return matchSearch && matchCat;
      }).toList();

  void _addToCart(ProductModel p) {
    setState(() {
      final existing =
          _cart.where((c) => c.product.id == p.id).firstOrNull;
      if (existing != null) {
        existing.quantity++;
      } else {
        _cart.add(CartItem(product: p));
      }
    });
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text('${p.name} agregado al carrito',
          style: GoogleFonts.inter()),
      behavior: SnackBarBehavior.floating,
      shape:
          RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      backgroundColor: AppColors.dark,
      action: SnackBarAction(
        label: 'Ver',
        textColor: AppColors.primary,
        onPressed: _openCart,
      ),
    ));
  }

  void _openCart() => Navigator.push(
        context,
        MaterialPageRoute(
            builder: (_) =>
                CartScreen(cart: _cart, onUpdate: () => setState(() {}))),
      );

  @override
  Widget build(BuildContext context) {
    final total = _cart.fold<int>(0, (sum, c) => sum + c.quantity);
    final products = _filtered;

    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: IronAppBar(
        title: 'Tienda',
        actions: [
          Stack(
            children: [
              IconButton(
                onPressed: _openCart,
                icon: const Icon(Icons.shopping_bag_outlined,
                    color: AppColors.textPrimary),
              ),
              if (total > 0)
                Positioned(
                  right: 6,
                  top: 6,
                  child: Container(
                    width: 18,
                    height: 18,
                    decoration: const BoxDecoration(
                        color: AppColors.error, shape: BoxShape.circle),
                    child: Center(
                        child: Text('$total',
                            style: GoogleFonts.lexend(
                                fontSize: 10,
                                fontWeight: FontWeight.w700,
                                color: AppColors.onDark))),
                  ),
                ),
            ],
          ),
        ],
      ),
      body: Column(
        children: [
          // ── Search ────────────────────────────────────────────────────
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 0),
            child: TextField(
              onChanged: (v) => setState(() => _search = v),
              style: GoogleFonts.inter(fontSize: 14),
              decoration: InputDecoration(
                hintText: 'Buscar producto...',
                hintStyle:
                    GoogleFonts.inter(color: AppColors.textDisabled),
                prefixIcon: const Icon(Icons.search_rounded,
                    color: AppColors.textSecondary, size: 20),
                filled: true,
                fillColor: AppColors.surfaceContainerLow,
                border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide.none),
                contentPadding: const EdgeInsets.symmetric(
                    horizontal: 16, vertical: 12),
              ),
            ),
          ),
          const Gap(10),

          // ── Category chips ─────────────────────────────────────────────
          SizedBox(
            height: 36,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 20),
              itemCount: _categories.length,
              separatorBuilder: (_, index) => const Gap(8),
              itemBuilder: (_, i) {
                final c = _categories[i];
                final active = c == _category;
                return GestureDetector(
                  onTap: () => setState(() => _category = c),
                  child: AnimatedContainer(
                    duration: 200.ms,
                    padding: const EdgeInsets.symmetric(
                        horizontal: 14, vertical: 6),
                    decoration: BoxDecoration(
                      color: active
                          ? AppColors.dark
                          : AppColors.surfaceContainerLow,
                      borderRadius: BorderRadius.circular(99),
                      border: Border.all(
                          color:
                              active ? AppColors.dark : AppColors.border),
                    ),
                    child: Text(
                      c,
                      style: GoogleFonts.lexend(
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          color: active
                              ? AppColors.onDark
                              : AppColors.textSecondary),
                    ),
                  ),
                );
              },
            ),
          ),
          const Gap(14),

          // ── Product list ───────────────────────────────────────────────
          Expanded(
            child: products.isEmpty
                ? _emptyState()
                : ListView.builder(
                    padding:
                        const EdgeInsets.fromLTRB(20, 0, 20, 100),
                    itemCount: products.length,
                    itemBuilder: (_, i) {
                      final p = products[i];
                      return Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: _PremiumProductCard(
                          product: p,
                          onAdd: () => _addToCart(p),
                          onTap: () => Navigator.push(
                            context,
                            MaterialPageRoute(
                              builder: (_) => ProductDetailScreen(
                                product: p,
                                onAdd: () => _addToCart(p),
                              ),
                            ),
                          ),
                        ).animate().fadeIn(delay: (i * 55).ms).slideY(
                              begin: 0.12,
                              duration: 350.ms,
                              curve: Curves.easeOut,
                            ),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }

  Widget _emptyState() {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Lottie.asset(AppAssets.lottieTienda,
              width: 100, height: 100, repeat: true),
          const Gap(12),
          Text('Sin resultados',
              style: GoogleFonts.lexend(
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary)),
          const Gap(4),
          Text('Prueba con otra búsqueda o categoría',
              style: GoogleFonts.inter(
                  fontSize: 13, color: AppColors.textSecondary)),
        ],
      ),
    );
  }
}

// ── Premium Product Card ────────────────────────────────────────────────────

class _PremiumProductCard extends StatelessWidget {
  final ProductModel product;
  final VoidCallback onAdd;
  final VoidCallback onTap;

  const _PremiumProductCard({
    required this.product,
    required this.onAdd,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final p = product;
    return GestureDetector(
      onTap: onTap,
      child: Container(
        height: 110,
        decoration: BoxDecoration(
          color: AppColors.surface0,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: AppColors.border),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.05),
              blurRadius: 14,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        child: Row(
          children: [
            // ── Lottie icon area ────────────────────────────────────────
            Container(
              width: 90,
              height: double.infinity,
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [Color(0xFFF7F3E0), Color(0xFFFFF8CC)],
                ),
                borderRadius: const BorderRadius.only(
                  topLeft: Radius.circular(18),
                  bottomLeft: Radius.circular(18),
                ),
              ),
              child: Center(
                child: Lottie.asset(
                  lottieForProduct(p),
                  width: 56,
                  height: 56,
                  repeat: true,
                  fit: BoxFit.contain,
                ),
              ),
            ),

            // ── Info ────────────────────────────────────────────────────
            Expanded(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Top row: category + stock badge
                    Row(
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 8, vertical: 2),
                          decoration: BoxDecoration(
                            color: AppColors.surfaceContainerLow,
                            borderRadius: BorderRadius.circular(99),
                          ),
                          child: Text(
                            p.category,
                            style: GoogleFonts.lexend(
                                fontSize: 9,
                                fontWeight: FontWeight.w700,
                                color: AppColors.textSecondary,
                                letterSpacing: 0.5),
                          ),
                        ),
                        if (p.isLowStock) ...[
                          const Gap(6),
                          StatusBadge(
                              label: 'Poco stock',
                              variant: BadgeVariant.warning),
                        ],
                        if (!p.isAvailable) ...[
                          const Gap(6),
                          StatusBadge(
                              label: 'Agotado',
                              variant: BadgeVariant.error),
                        ],
                      ],
                    ),
                    const Gap(6),

                    // Name
                    Text(
                      p.name,
                      style: GoogleFonts.lexend(
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),

                    const Spacer(),

                    // Bottom: price + add button
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          CurrencyFormatter.format(p.price),
                          style: GoogleFonts.lexend(
                              fontSize: 15,
                              fontWeight: FontWeight.w700,
                              color: AppColors.primary),
                        ),
                        _AddButton(
                            isAvailable: p.isAvailable, onTap: onAdd),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _AddButton extends StatefulWidget {
  final bool isAvailable;
  final VoidCallback onTap;
  const _AddButton({required this.isAvailable, required this.onTap});

  @override
  State<_AddButton> createState() => _AddButtonState();
}

class _AddButtonState extends State<_AddButton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  late final Animation<double> _scale;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
        vsync: this, duration: const Duration(milliseconds: 120));
    _scale = Tween<double>(begin: 1, end: 0.85).animate(
        CurvedAnimation(parent: _ctrl, curve: Curves.easeInOut));
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  void _onTap() {
    if (!widget.isAvailable) return;
    _ctrl.forward().then((_) => _ctrl.reverse());
    widget.onTap();
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: _onTap,
      child: ScaleTransition(
        scale: _scale,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 200),
          width: 36,
          height: 36,
          decoration: BoxDecoration(
            color: widget.isAvailable ? AppColors.dark : AppColors.border,
            borderRadius: BorderRadius.circular(10),
          ),
          child: Icon(
            Icons.add_rounded,
            size: 20,
            color: widget.isAvailable
                ? AppColors.primary
                : AppColors.textDisabled,
          ),
        ),
      ),
    );
  }
}
