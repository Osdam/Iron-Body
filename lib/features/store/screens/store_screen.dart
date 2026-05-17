import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:lottie/lottie.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/product_model.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import 'cart_screen.dart';

double _lerp(double a, double b, double t) => a + (b - a) * t;

String lottieForProduct(ProductModel p) {
  switch (p.category) {
    case 'Suplementos':
      return AppAssets.lottieProductoSuplemento;
    case 'Bebidas':
      return AppAssets.lottieProductoBebida;
    case 'Snacks':
      return AppAssets.lottieProductoSnack;
    default:
      return AppAssets.lottieTienda;
  }
}

// ── StoreScreen ──────────────────────────────────────────────────────────────

class StoreScreen extends StatefulWidget {
  const StoreScreen({super.key});

  @override
  State<StoreScreen> createState() => _StoreScreenState();
}

class _StoreScreenState extends State<StoreScreen> {
  String _search = '';
  String _category = 'Todos';
  final List<CartItem> _cart = [];

  static const _categories = ['Todos', 'Suplementos', 'Bebidas', 'Snacks'];

  List<ProductModel> get _filtered => mockProducts.where((p) {
        if (p.category == 'Accesorios') return false;
        final matchSearch =
            p.name.toLowerCase().contains(_search.toLowerCase());
        final matchCat = _category == 'Todos' || p.category == _category;
        return matchSearch && matchCat;
      }).toList();

  void _addToCart(ProductModel p, [int qty = 1]) {
    setState(() {
      final existing = _cart.where((c) => c.product.id == p.id).firstOrNull;
      if (existing != null) {
        existing.quantity += qty;
      } else {
        _cart.add(CartItem(product: p, quantity: qty));
      }
    });
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(
        qty > 1 ? '${p.name} ×$qty agregado' : '${p.name} agregado al carrito',
        style: GoogleFonts.inter(),
      ),
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
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
    final cartTotal = _cart.fold<int>(0, (s, c) => s + c.quantity);
    final products = _filtered;

    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: IronAppBar(
        title: 'Tienda',
        actions: [
          Stack(children: [
            IconButton(
              onPressed: _openCart,
              icon: const Icon(Icons.shopping_bag_outlined,
                  color: AppColors.textPrimary),
            ),
            if (cartTotal > 0)
              Positioned(
                right: 6,
                top: 6,
                child: Container(
                  width: 18,
                  height: 18,
                  decoration: const BoxDecoration(
                      color: AppColors.error, shape: BoxShape.circle),
                  child: Center(
                      child: Text('$cartTotal',
                          style: GoogleFonts.lexend(
                              fontSize: 10,
                              fontWeight: FontWeight.w700,
                              color: AppColors.onDark))),
                ),
              ),
          ]),
        ],
      ),
      body: Column(
        children: [
          // ── Search ──────────────────────────────────────────────────────
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 0),
            child: TextField(
              onChanged: (v) => setState(() => _search = v),
              style: GoogleFonts.inter(fontSize: 14),
              decoration: InputDecoration(
                hintText: 'Buscar producto...',
                hintStyle: GoogleFonts.inter(color: AppColors.textDisabled),
                prefixIcon: const Icon(Icons.search_rounded,
                    color: AppColors.textSecondary, size: 20),
                filled: true,
                fillColor: AppColors.surfaceContainerLow,
                border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide: BorderSide.none),
                contentPadding:
                    const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              ),
            ),
          ),
          const Gap(10),

          // ── Categories ──────────────────────────────────────────────────
          SizedBox(
            height: 36,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 20),
              itemCount: _categories.length,
              separatorBuilder: (_, i) => const Gap(8),
              itemBuilder: (_, i) {
                final c = _categories[i];
                final active = c == _category;
                return GestureDetector(
                  onTap: () => setState(() => _category = c),
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 200),
                    padding: const EdgeInsets.symmetric(
                        horizontal: 14, vertical: 6),
                    decoration: BoxDecoration(
                      color: active
                          ? AppColors.dark
                          : AppColors.surfaceContainerLow,
                      borderRadius: BorderRadius.circular(99),
                      border: Border.all(
                          color: active ? AppColors.dark : AppColors.border),
                    ),
                    child: Text(c,
                        style: GoogleFonts.lexend(
                            fontSize: 12,
                            fontWeight: FontWeight.w700,
                            color: active
                                ? AppColors.onDark
                                : AppColors.textSecondary)),
                  ),
                );
              },
            ),
          ),
          const Gap(12),

          // ── Stack deck ──────────────────────────────────────────────────
          Expanded(
            child: products.isEmpty
                ? _emptyState()
                : Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 24),
                    child: _StackDeck(
                      key: ValueKey('${_search}_$_category'),
                      products: products,
                      onAdd: (p, qty) => _addToCart(p, qty),
                    ),
                  ),
          ),
        ],
      ),
    );
  }

  Widget _emptyState() => Center(
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

// ── Stack Deck ───────────────────────────────────────────────────────────────

class _StackDeck extends StatefulWidget {
  final List<ProductModel> products;
  final void Function(ProductModel, int qty) onAdd;

  const _StackDeck({
    super.key,
    required this.products,
    required this.onAdd,
  });

  @override
  State<_StackDeck> createState() => _StackDeckState();
}

class _StackDeckState extends State<_StackDeck> with TickerProviderStateMixin {
  int _idx = 0;
  double _drag = 0;
  double _dragSnapshot = 0;

  late final AnimationController _commitCtrl;
  late final AnimationController _snapCtrl;

  bool _committing = false;
  bool _snapping = false;
  double _snapStart = 0;
  int _dir = 0;

  static const _thresh = 72.0;
  static const _kTY = [88.0, 36.0, 0.0];
  static const _kSX = [1.00, 0.93, 0.86];
  static const _kOP = [1.00, 0.72, 0.44];

  @override
  void initState() {
    super.initState();

    _commitCtrl = AnimationController(
        vsync: this, duration: const Duration(milliseconds: 460))
      ..addListener(() => setState(() {}))
      ..addStatusListener((s) {
        if (s == AnimationStatus.completed) {
          setState(() {
            _idx = (_idx + _dir).clamp(0, widget.products.length - 1);
            _drag = 0;
            _dragSnapshot = 0;
            _committing = false;
            _dir = 0;
          });
          _commitCtrl.reset();
        }
      });

    _snapCtrl = AnimationController(
        vsync: this, duration: const Duration(milliseconds: 320))
      ..addListener(() {
        if (!_snapping) return;
        setState(() {
          _drag = _lerp(
              _snapStart, 0, Curves.easeOutQuart.transform(_snapCtrl.value));
        });
      })
      ..addStatusListener((s) {
        if (s == AnimationStatus.completed && _snapping) {
          setState(() {
            _drag = 0;
            _snapping = false;
          });
          _snapCtrl.reset();
        }
      });
  }

  @override
  void dispose() {
    _commitCtrl.dispose();
    _snapCtrl.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(_StackDeck old) {
    super.didUpdateWidget(old);
    if (old.products != widget.products) {
      _idx = 0;
      _drag = 0;
      _committing = false;
      _snapping = false;
      _dir = 0;
      _commitCtrl.reset();
      _snapCtrl.reset();
    }
  }

  void _onDragUpdate(DragUpdateDetails d) {
    if (_committing || _snapping) return;
    setState(() => _drag += d.delta.dy * 0.88);
  }

  void _onDragEnd(DragEndDetails d) {
    if (_committing || _snapping) return;
    if (_drag > _thresh && _idx < widget.products.length - 1) {
      _commit(1);
    } else if (_drag < -_thresh && _idx > 0) {
      _commit(-1);
    } else {
      _snapStart = _drag;
      _snapping = true;
      _snapCtrl.forward(from: 0);
    }
  }

  void _commit(int dir) {
    HapticFeedback.lightImpact();
    _dragSnapshot = _drag;
    setState(() {
      _committing = true;
      _dir = dir;
    });
    _commitCtrl.forward(from: 0);
  }

  double get _animP => Curves.easeInOutCubic.transform(_commitCtrl.value);
  double get _animPExit => Curves.easeInCubic.transform(_commitCtrl.value);
  double get _animPEnter => Curves.easeOutCubic.transform(_commitCtrl.value);
  double get _dragP {
    final raw = (_drag.abs() / _thresh).clamp(0.0, 1.0);
    return Curves.easeOut.transform(raw);
  }

  @override
  Widget build(BuildContext context) {
    final ps = widget.products;
    return LayoutBuilder(builder: (_, box) {
      final cardH = (box.maxHeight * 0.63).clamp(240.0, 420.0);
      return Stack(
        children: [
          Positioned.fill(
            child: GestureDetector(
              behavior: HitTestBehavior.translucent,
              onVerticalDragUpdate: _onDragUpdate,
              onVerticalDragEnd: _onDragEnd,
              child: Stack(
                clipBehavior: Clip.none,
                alignment: Alignment.topCenter,
                children: _buildLayers(ps, cardH),
              ),
            ),
          ),
          Positioned(
            right: 0,
            top: 0,
            bottom: 0,
            child: Center(
              child: _SwipeIndicator(
                canGoUp: _idx > 0,
                canGoDown: _idx < ps.length - 1,
              ),
            ),
          ),
        ],
      );
    });
  }

  List<Widget> _buildLayers(List<ProductModel> ps, double cardH) {
    final layers = <({int z, Widget w})>[];
    final goingNext = _dir == 1 || (_dir == 0 && _drag >= 0);

    if (goingNext) {
      final p = _committing ? _animP : _dragP;

      for (int slot = 0; slot < 3; slot++) {
        final pi = _idx + slot;
        if (pi >= ps.length) break;

        double ty, sx, op;
        if (slot == 0) {
          if (_committing) {
            ty = _lerp(_kTY[0] + _dragSnapshot, cardH + 120, _animPExit);
            sx = _lerp(_kSX[0], _kSX[1], _animPExit);
            op = (1.0 - _animPExit * 2.5).clamp(0.0, 1.0);
          } else {
            ty = _kTY[0] + _drag;
            sx = _lerp(_kSX[0], _kSX[1], p);
            op = _lerp(1.0, 0.0, (p * 1.3).clamp(0, 1));
          }
        } else {
          final ep = _committing ? _animPEnter : p;
          ty = _lerp(_kTY[slot], _kTY[slot - 1], ep);
          sx = _lerp(_kSX[slot], _kSX[slot - 1], ep);
          op = _lerp(_kOP[slot], _kOP[slot - 1], ep);
        }

        layers.add((
          z: 2 - slot,
          w: _card(ps[pi], ty, sx, op, cardH,
              interactive: slot == 0 && !_committing)
        ));
      }

      if (_committing && _idx + 3 < ps.length) {
        final ty = _lerp(_kTY[2] - 30, _kTY[2], _animPEnter);
        final sx = _lerp(_kSX[2] - 0.08, _kSX[2], _animPEnter);
        final op = _lerp(0.0, _kOP[2], _animPEnter);
        layers.add((z: 0, w: _card(ps[_idx + 3], ty, sx, op, cardH)));
      }
    } else {
      final p = _committing ? _animP : _dragP;

      if (_idx > 0) {
        final ty = _lerp(cardH * 0.6, _kTY[0], p);
        final sx = _lerp(_kSX[1], _kSX[0], p);
        final op = _lerp(0.0, _kOP[0], p);
        layers.add((
          z: 3,
          w: _card(ps[_idx - 1], ty, sx, op, cardH,
              interactive: _committing && _commitCtrl.value > 0.7)
        ));
      }

      for (int slot = 0; slot < 3; slot++) {
        final pi = _idx + slot;
        if (pi >= ps.length) break;

        double ty, sx, op;
        if (slot == 2) {
          ty = _lerp(_kTY[2], _kTY[2] - 8, p);
          sx = _lerp(_kSX[2], _kSX[2] - 0.05, p);
          op = _lerp(_kOP[2], 0.0, p);
        } else {
          ty = _lerp(_kTY[slot], _kTY[slot + 1], p);
          sx = _lerp(_kSX[slot], _kSX[slot + 1], p);
          op = _lerp(_kOP[slot], _kOP[slot + 1], p);
        }

        layers.add((z: 2 - slot, w: _card(ps[pi], ty, sx, op, cardH)));
      }
    }

    layers.sort((a, b) => a.z.compareTo(b.z));
    return layers.map((l) => l.w).toList();
  }

  Widget _card(ProductModel p, double ty, double sx, double op, double cardH,
      {bool interactive = false}) {
    return Transform.translate(
      offset: Offset(0, ty),
      child: Transform.scale(
        scaleX: sx,
        scaleY: 1.0,
        alignment: Alignment.topCenter,
        child: Opacity(
          opacity: op.clamp(0.0, 1.0),
          child: IgnorePointer(
            ignoring: !interactive,
            child: SizedBox(
              width: double.infinity,
              height: cardH,
              child: _ProductCard(
                key: ValueKey(p.id),
                product: p,
                onAdd: (qty) => widget.onAdd(p, qty),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

// ── Swipe Indicator ───────────────────────────────────────────────────────────

class _SwipeIndicator extends StatefulWidget {
  final bool canGoUp;
  final bool canGoDown;
  const _SwipeIndicator({required this.canGoUp, required this.canGoDown});

  @override
  State<_SwipeIndicator> createState() => _SwipeIndicatorState();
}

class _SwipeIndicatorState extends State<_SwipeIndicator>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  late final Animation<double> _anim;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
        vsync: this, duration: const Duration(milliseconds: 1800))
      ..repeat(reverse: true);
    _anim = CurvedAnimation(parent: _ctrl, curve: Curves.easeInOut);
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _anim,
      builder: (_, _) {
        final t = _anim.value;
        return Container(
          width: 30,
          padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 5),
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: 0.82),
            borderRadius: BorderRadius.circular(15),
            border: Border.all(color: const Color(0xFFE4E0D8)),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.07),
                blurRadius: 8,
                offset: const Offset(0, 2),
              ),
            ],
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Transform.translate(
                offset: Offset(0, widget.canGoUp ? -(t * 2.5) : 0),
                child: Icon(
                  Icons.keyboard_arrow_up_rounded,
                  size: 16,
                  color: AppColors.dark
                      .withValues(alpha: widget.canGoUp ? 0.32 + t * 0.52 : 0.15),
                ),
              ),
              const SizedBox(height: 4),
              ...List.generate(
                3,
                (i) => Container(
                  width: 3,
                  height: 3,
                  margin: const EdgeInsets.symmetric(vertical: 2),
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: AppColors.dark.withValues(alpha: 0.10 + t * 0.14),
                  ),
                ),
              ),
              const SizedBox(height: 4),
              Transform.translate(
                offset: Offset(0, widget.canGoDown ? t * 2.5 : 0),
                child: Icon(
                  Icons.keyboard_arrow_down_rounded,
                  size: 16,
                  color: AppColors.dark.withValues(
                      alpha: widget.canGoDown ? 0.32 + t * 0.52 : 0.15),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

// ── Product Card (with 3D flip) ───────────────────────────────────────────────

class _ProductCard extends StatefulWidget {
  final ProductModel product;
  final void Function(int qty) onAdd;

  const _ProductCard({
    super.key,
    required this.product,
    required this.onAdd,
  });

  @override
  State<_ProductCard> createState() => _ProductCardState();
}

class _ProductCardState extends State<_ProductCard>
    with SingleTickerProviderStateMixin {
  late final AnimationController _flipCtrl;
  int _qty = 1;

  @override
  void initState() {
    super.initState();
    _flipCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 500),
    )..addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _flipCtrl.dispose();
    super.dispose();
  }

  void _flip() {
    if (_flipCtrl.isAnimating) return;
    HapticFeedback.lightImpact();
    _flipCtrl.isDismissed ? _flipCtrl.forward() : _flipCtrl.reverse();
  }

  void _addAndFlipBack() {
    widget.onAdd(_qty);
    _flip();
    Future.delayed(const Duration(milliseconds: 520), () {
      if (mounted) setState(() => _qty = 1);
    });
  }

  static _CategoryStyle _styleFor(String category) {
    switch (category) {
      case 'Suplementos':
        return const _CategoryStyle(
          gradientColors: [Color(0xFFF9F6EE), Color(0xFFEDE6D0)],
          glowColor: Color(0xFFFFF4CC),
          badgeLabel: 'SUPLEMENTO',
          badgeBg: Color(0xFFF5EDD0),
          badgeText: Color(0xFF7A5800),
        );
      case 'Bebidas':
        return const _CategoryStyle(
          gradientColors: [Color(0xFFECF4FD), Color(0xFFD5E8F8)],
          glowColor: Color(0xFFD0E8FF),
          badgeLabel: 'BEBIDA',
          badgeBg: Color(0xFFD4EAFB),
          badgeText: Color(0xFF1A528A),
        );
      case 'Snacks':
        return const _CategoryStyle(
          gradientColors: [Color(0xFFF9F4E8), Color(0xFFEDE2C8)],
          glowColor: Color(0xFFFFEAC0),
          badgeLabel: 'SNACK',
          badgeBg: Color(0xFFF5E4C0),
          badgeText: Color(0xFF7A4800),
        );
      default:
        return const _CategoryStyle(
          gradientColors: [Color(0xFFF5F5F5), Color(0xFFEAEAEA)],
          glowColor: Color(0xFFE8E8E8),
          badgeLabel: 'PRODUCTO',
          badgeBg: Color(0xFFEAEAEA),
          badgeText: Color(0xFF555555),
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final isBack = _flipCtrl.value >= 0.5;
    final angle = _flipCtrl.value * math.pi;

    return Transform(
      alignment: Alignment.center,
      transform: Matrix4.identity()
        ..setEntry(3, 2, 0.0014)
        ..rotateY(isBack ? angle - math.pi : angle),
      child: isBack ? _buildBack() : _buildFront(),
    );
  }

  // ── Front ─────────────────────────────────────────────────────────────────

  Widget _buildFront() {
    final p = widget.product;
    final style = _styleFor(p.category);

    return GestureDetector(
      onTap: _flip,
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(24),
          border: Border.all(color: const Color(0xFFD4CFC7), width: 1.2),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.11),
              blurRadius: 28,
              offset: const Offset(0, 10),
            ),
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.04),
              blurRadius: 6,
              offset: const Offset(0, 2),
            ),
          ],
        ),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              // ── Top: background image + gradient + Lottie ─────────────
              Expanded(
                flex: 6,
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    Image.asset('assets/images/tienda.png', fit: BoxFit.cover),
                    Container(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                          colors: [
                            style.gradientColors[0].withValues(alpha: 0.60),
                            style.gradientColors[1].withValues(alpha: 0.60),
                          ],
                        ),
                      ),
                    ),
                    Stack(
                      alignment: Alignment.center,
                      children: [
                        Container(
                          width: 140,
                          height: 140,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: style.glowColor.withValues(alpha: 0.55),
                          ),
                        ),
                        Container(
                          width: 90,
                          height: 90,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: Colors.white.withValues(alpha: 0.45),
                          ),
                        ),
                        Lottie.asset(
                          lottieForProduct(p),
                          width: 120,
                          height: 120,
                          fit: BoxFit.contain,
                          repeat: true,
                        ),
                        Positioned(
                          top: 14,
                          left: 16,
                          child: Text(
                            'IRON BODY',
                            style: GoogleFonts.lexend(
                              fontSize: 8,
                              fontWeight: FontWeight.w800,
                              color: Colors.black.withValues(alpha: 0.12),
                              letterSpacing: 2.5,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),

              // ── Separator ─────────────────────────────────────────────
              Container(height: 1, color: const Color(0xFFF0ECE4)),

              // ── Bottom: info ───────────────────────────────────────────
              Expanded(
                flex: 4,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 11, 16, 14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: style.badgeBg,
                              borderRadius: BorderRadius.circular(99),
                            ),
                            child: Text(
                              style.badgeLabel,
                              style: GoogleFonts.lexend(
                                fontSize: 7.5,
                                fontWeight: FontWeight.w700,
                                color: style.badgeText,
                                letterSpacing: 1.5,
                              ),
                            ),
                          ),
                          const Spacer(),
                          if (p.isLowStock)
                            _StatusPill(
                              label: 'Poco stock',
                              bg: const Color(0xFFFFF3E0),
                              fg: const Color(0xFFB85C00),
                            ),
                          if (!p.isAvailable)
                            _StatusPill(
                              label: 'Agotado',
                              bg: const Color(0xFFFFEEEE),
                              fg: const Color(0xFFCC2200),
                            ),
                        ],
                      ),
                      const Gap(6),
                      Text(
                        p.name,
                        style: GoogleFonts.lexend(
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                          height: 1.2,
                        ),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const Spacer(),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                'precio',
                                style: GoogleFonts.inter(
                                  fontSize: 9,
                                  color: AppColors.textDisabled,
                                  letterSpacing: 0.3,
                                ),
                              ),
                              Text(
                                CurrencyFormatter.format(p.price),
                                style: GoogleFonts.lexend(
                                  fontSize: 20,
                                  fontWeight: FontWeight.w700,
                                  color: AppColors.dark,
                                ),
                              ),
                            ],
                          ),
                          // Quick-add button — absorbs tap so the card doesn't flip
                          _AddButton(
                            isAvailable: p.isAvailable,
                            onTap: () => widget.onAdd(1),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ── Back ──────────────────────────────────────────────────────────────────

  Widget _buildBack() {
    final p = widget.product;
    final maxQty = p.stock.clamp(1, 99);
    final total = p.price * _qty;

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: const Color(0xFFD4CFC7), width: 1.2),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.11),
            blurRadius: 28,
            offset: const Offset(0, 10),
          ),
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.04),
            blurRadius: 6,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // ── Header ───────────────────────────────────────────────────
            Container(
              padding: const EdgeInsets.fromLTRB(18, 16, 12, 16),
              color: AppColors.dark,
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          p.category.toUpperCase(),
                          style: GoogleFonts.lexend(
                            fontSize: 8,
                            fontWeight: FontWeight.w700,
                            color: AppColors.primary,
                            letterSpacing: 1.8,
                          ),
                        ),
                        const Gap(3),
                        Text(
                          p.name,
                          style: GoogleFonts.lexend(
                            fontSize: 15,
                            fontWeight: FontWeight.w700,
                            color: Colors.white,
                            height: 1.2,
                          ),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ],
                    ),
                  ),
                  const Gap(8),
                  GestureDetector(
                    onTap: _flip,
                    child: Container(
                      width: 34,
                      height: 34,
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: const Icon(Icons.close_rounded,
                          size: 18, color: Colors.white70),
                    ),
                  ),
                ],
              ),
            ),

            // ── Content ───────────────────────────────────────────────────
            Expanded(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 14, 16, 0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // Description
                    Text(
                      'Descripción',
                      style: GoogleFonts.lexend(
                        fontSize: 10,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textSecondary,
                        letterSpacing: 0.5,
                      ),
                    ),
                    const Gap(4),
                    Text(
                      p.description,
                      style: GoogleFonts.inter(
                        fontSize: 12,
                        color: AppColors.textSecondary,
                        height: 1.5,
                      ),
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                    ),

                    const Gap(14),

                    // Price + quantity selector
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 14, vertical: 11),
                      decoration: BoxDecoration(
                        color: AppColors.surfaceContainerLow,
                        borderRadius: BorderRadius.circular(14),
                      ),
                      child: Row(
                        children: [
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                'precio unit.',
                                style: GoogleFonts.inter(
                                    fontSize: 9,
                                    color: AppColors.textDisabled),
                              ),
                              Text(
                                CurrencyFormatter.format(p.price),
                                style: GoogleFonts.lexend(
                                  fontSize: 16,
                                  fontWeight: FontWeight.w700,
                                  color: AppColors.textPrimary,
                                ),
                              ),
                            ],
                          ),
                          const Spacer(),
                          // Quantity selector
                          Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              _QtyButton(
                                icon: Icons.remove_rounded,
                                enabled: _qty > 1,
                                onTap: () =>
                                    setState(() => _qty = (_qty - 1).clamp(1, maxQty)),
                              ),
                              Container(
                                width: 40,
                                alignment: Alignment.center,
                                child: Text(
                                  '$_qty',
                                  style: GoogleFonts.lexend(
                                    fontSize: 16,
                                    fontWeight: FontWeight.w700,
                                    color: AppColors.textPrimary,
                                  ),
                                ),
                              ),
                              _QtyButton(
                                icon: Icons.add_rounded,
                                enabled: _qty < maxQty && p.isAvailable,
                                onTap: () =>
                                    setState(() => _qty = (_qty + 1).clamp(1, maxQty)),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),

                    const Gap(10),

                    // Total row
                    Row(
                      children: [
                        Text(
                          'Total',
                          style: GoogleFonts.inter(
                              fontSize: 13,
                              color: AppColors.textSecondary),
                        ),
                        const Spacer(),
                        Text(
                          CurrencyFormatter.format(total),
                          style: GoogleFonts.lexend(
                            fontSize: 17,
                            fontWeight: FontWeight.w700,
                            color: AppColors.textPrimary,
                          ),
                        ),
                      ],
                    ),

                    const Spacer(),

                    // Add to cart button
                    GestureDetector(
                      onTap: p.isAvailable ? _addAndFlipBack : null,
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        height: 48,
                        decoration: BoxDecoration(
                          color: p.isAvailable
                              ? AppColors.dark
                              : AppColors.surfaceContainer,
                          borderRadius: BorderRadius.circular(14),
                          boxShadow: p.isAvailable
                              ? [
                                  BoxShadow(
                                    color:
                                        AppColors.dark.withValues(alpha: 0.20),
                                    blurRadius: 12,
                                    offset: const Offset(0, 4),
                                  ),
                                ]
                              : [],
                        ),
                        alignment: Alignment.center,
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(
                              Icons.shopping_bag_rounded,
                              size: 16,
                              color: p.isAvailable
                                  ? AppColors.primary
                                  : AppColors.textDisabled,
                            ),
                            const Gap(8),
                            Text(
                              p.isAvailable ? 'Agregar al carrito' : 'Agotado',
                              style: GoogleFonts.lexend(
                                fontSize: 13,
                                fontWeight: FontWeight.w700,
                                color: p.isAvailable
                                    ? AppColors.primary
                                    : AppColors.textDisabled,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    const Gap(16),
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

// ── Qty Button ────────────────────────────────────────────────────────────────

class _QtyButton extends StatelessWidget {
  final IconData icon;
  final bool enabled;
  final VoidCallback onTap;
  const _QtyButton(
      {required this.icon, required this.enabled, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: enabled ? onTap : null,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        width: 30,
        height: 30,
        decoration: BoxDecoration(
          color: enabled ? AppColors.dark : AppColors.surfaceContainer,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Icon(
          icon,
          size: 15,
          color: enabled ? AppColors.primary : AppColors.textDisabled,
        ),
      ),
    );
  }
}

// ── Category Style ────────────────────────────────────────────────────────────

class _CategoryStyle {
  final List<Color> gradientColors;
  final Color glowColor;
  final String badgeLabel;
  final Color badgeBg;
  final Color badgeText;

  const _CategoryStyle({
    required this.gradientColors,
    required this.glowColor,
    required this.badgeLabel,
    required this.badgeBg,
    required this.badgeText,
  });
}

class _StatusPill extends StatelessWidget {
  final String label;
  final Color bg;
  final Color fg;

  const _StatusPill({required this.label, required this.bg, required this.fg});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(99),
      ),
      child: Text(label,
          style: GoogleFonts.inter(
              fontSize: 7.5, fontWeight: FontWeight.w600, color: fg)),
    );
  }
}

// ── Add Button (quick +1 on front) ───────────────────────────────────────────

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
        vsync: this, duration: const Duration(milliseconds: 110));
    _scale = Tween<double>(begin: 1.0, end: 0.78)
        .animate(CurvedAnimation(parent: _ctrl, curve: Curves.easeInOut));
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
          width: 46,
          height: 46,
          decoration: BoxDecoration(
            color: widget.isAvailable
                ? AppColors.dark
                : const Color(0xFFEAEAEA),
            borderRadius: BorderRadius.circular(14),
            boxShadow: widget.isAvailable
                ? [
                    BoxShadow(
                      color: AppColors.dark.withValues(alpha: 0.22),
                      blurRadius: 10,
                      offset: const Offset(0, 4),
                    )
                  ]
                : [],
          ),
          child: Icon(
            Icons.add_rounded,
            size: 22,
            color: widget.isAvailable
                ? AppColors.primary
                : AppColors.textDisabled,
          ),
        ),
      ),
    );
  }
}
