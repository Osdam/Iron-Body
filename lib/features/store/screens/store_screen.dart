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
import 'product_detail_screen.dart';
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

Widget _productObjectFor(
    ProductModel p, VoidCallback onAdd, VoidCallback onTap) {
  switch (p.category) {
    case 'Bebidas':
      return _DrinkObject(product: p, onAdd: onAdd, onTap: onTap);
    case 'Snacks':
      return _SnackObject(product: p, onAdd: onAdd, onTap: onTap);
    default:
      return _SupplementObject(product: p, onAdd: onAdd, onTap: onTap);
  }
}

// ── StoreScreen ─────────────────────────────────────────────────────────────

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

  void _addToCart(ProductModel p) {
    setState(() {
      final existing = _cart.where((c) => c.product.id == p.id).firstOrNull;
      if (existing != null) {
        existing.quantity++;
      } else {
        _cart.add(CartItem(product: p));
      }
    });
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content:
          Text('${p.name} agregado al carrito', style: GoogleFonts.inter()),
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      backgroundColor: AppColors.dark,
      action: SnackBarAction(
          label: 'Ver',
          textColor: AppColors.primary,
          onPressed: _openCart),
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
          // ── Búsqueda ───────────────────────────────────────────────────
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

          // ── Categorías ─────────────────────────────────────────────────
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
                          color: active
                              ? AppColors.dark
                              : AppColors.border),
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

          // ── Stack deck ─────────────────────────────────────────────────
          Expanded(
            child: products.isEmpty
                ? _emptyState()
                : Padding(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 24),
                    child: _StackDeck(
                      key: ValueKey('${_search}_$_category'),
                      products: products,
                      onAdd: _addToCart,
                      onTap: (p) => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => ProductDetailScreen(
                              product: p,
                              onAdd: () => _addToCart(p)),
                        ),
                      ),
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
//
// Slot transforms at rest (more pronounced than before):
//   slot 0 (front): ty=0,  scale=1.00, opacity=1.00
//   slot 1 (mid):   ty=22, scale=0.86, opacity=0.70
//   slot 2 (back):  ty=40, scale=0.74, opacity=0.45
//
// Swipe DOWN → next   (front exits down, ghosts rise)
// Swipe UP   → prev   (prev card descends from above)
// Release < threshold → animated snap-back

class _StackDeck extends StatefulWidget {
  final List<ProductModel> products;
  final void Function(ProductModel) onAdd;
  final void Function(ProductModel) onTap;

  const _StackDeck(
      {super.key,
      required this.products,
      required this.onAdd,
      required this.onTap});

  @override
  State<_StackDeck> createState() => _StackDeckState();
}

class _StackDeckState extends State<_StackDeck>
    with TickerProviderStateMixin {
  int _idx = 0;
  double _drag = 0;
  double _dragSnapshot = 0;

  late final AnimationController _commitCtrl;
  late final AnimationController _snapCtrl;

  bool _committing = false;
  bool _snapping = false;
  double _snapStart = 0;
  int _dir = 0; // 1=next, -1=prev

  static const _thresh = 68.0;
  // Pronounced stack visual separation
  static const _kTY = [0.0, 22.0, 40.0];
  static const _kSC = [1.00, 0.86, 0.74];
  static const _kOP = [1.00, 0.70, 0.45];

  @override
  void initState() {
    super.initState();

    _commitCtrl = AnimationController(
        vsync: this,
        duration: const Duration(milliseconds: 390))
      ..addListener(() => setState(() {}))
      ..addStatusListener((s) {
        if (s == AnimationStatus.completed) {
          setState(() {
            _idx =
                (_idx + _dir).clamp(0, widget.products.length - 1);
            _drag = 0;
            _dragSnapshot = 0;
            _committing = false;
            _dir = 0;
          });
          _commitCtrl.reset();
        }
      });

    _snapCtrl = AnimationController(
        vsync: this,
        duration: const Duration(milliseconds: 300))
      ..addListener(() {
        if (!_snapping) return;
        setState(() {
          _drag = _lerp(_snapStart, 0,
              Curves.easeOutQuart.transform(_snapCtrl.value));
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
    setState(() => _drag += d.delta.dy);
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

  double get _animP =>
      Curves.easeInOutCubic.transform(_commitCtrl.value);

  double get _dragP =>
      (_drag.abs() / _thresh).clamp(0.0, 1.0);

  @override
  Widget build(BuildContext context) {
    final ps = widget.products;
    return LayoutBuilder(builder: (_, box) {
      final cardH = box.maxHeight - 44;
      return Column(
        children: [
          Expanded(
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
          const Gap(6),
          _buildCounter(ps),
          const Gap(6),
        ],
      );
    });
  }

  List<Widget> _buildLayers(List<ProductModel> ps, double cardH) {
    final layers = <({int z, Widget w})>[];
    final goingNext =
        _dir == 1 || (_dir == 0 && _drag >= 0);

    if (goingNext) {
      final p = _committing ? _animP : _dragP;

      for (int slot = 0; slot < 3; slot++) {
        final pi = _idx + slot;
        if (pi >= ps.length) break;

        double ty, sc, op;
        if (slot == 0) {
          if (_committing) {
            ty = _lerp(_dragSnapshot, cardH + 120, _animP);
            sc = _kSC[1];
            op = 0.0;
          } else {
            ty = _drag;
            sc = _lerp(_kSC[0], _kSC[1], p);
            op = _lerp(1.0, 0.0, (p * 1.3).clamp(0, 1));
          }
        } else {
          ty = _lerp(_kTY[slot], _kTY[slot - 1], p);
          sc = _lerp(_kSC[slot], _kSC[slot - 1], p);
          op = _lerp(_kOP[slot], _kOP[slot - 1], p);
        }

        layers.add((
          z: 2 - slot,
          w: _card(ps[pi], ty, sc, op, cardH,
              interactive: slot == 0 && !_committing)
        ));
      }

      // New card rising from back during commit
      if (_committing && _idx + 3 < ps.length) {
        final ty = _lerp(_kTY[2] + 16, _kTY[2], _animP);
        final sc = _lerp(_kSC[2] - 0.06, _kSC[2], _animP);
        final op = _lerp(0.0, _kOP[2], _animP);
        layers.add((z: 0, w: _card(ps[_idx + 3], ty, sc, op, cardH)));
      }
    } else {
      // Going prev
      final p = _committing ? _animP : _dragP;

      // Prev card enters from above
      if (_idx > 0) {
        final ty = _lerp(-cardH * 0.32, _kTY[0], p);
        final sc = _lerp(_kSC[1], _kSC[0], p);
        final op = _lerp(0.0, _kOP[0], p);
        layers.add((
          z: 3,
          w: _card(ps[_idx - 1], ty, sc, op, cardH,
              interactive: _committing && _commitCtrl.value > 0.7)
        ));
      }

      // Existing cards push into stack
      for (int slot = 0; slot < 3; slot++) {
        final pi = _idx + slot;
        if (pi >= ps.length) break;

        double ty, sc, op;
        if (slot == 2) {
          ty = _lerp(_kTY[2], _kTY[2] + 12, p);
          sc = _lerp(_kSC[2], _kSC[2] - 0.05, p);
          op = _lerp(_kOP[2], 0.0, p);
        } else {
          ty = _lerp(_kTY[slot], _kTY[slot + 1], p);
          sc = _lerp(_kSC[slot], _kSC[slot + 1], p);
          op = _lerp(_kOP[slot], _kOP[slot + 1], p);
        }

        layers.add((z: 2 - slot, w: _card(ps[pi], ty, sc, op, cardH)));
      }
    }

    layers.sort((a, b) => a.z.compareTo(b.z));
    return layers.map((l) => l.w).toList();
  }

  Widget _card(ProductModel p, double ty, double sc, double op,
      double cardH,
      {bool interactive = false}) {
    return Transform.translate(
      offset: Offset(0, ty),
      child: Transform.scale(
        scale: sc,
        alignment: Alignment.topCenter,
        child: Opacity(
          opacity: op.clamp(0.0, 1.0),
          child: IgnorePointer(
            ignoring: !interactive,
            child: SizedBox(
              width: double.infinity,
              height: cardH,
              child: _productObjectFor(
                p,
                () => widget.onAdd(p),
                () => widget.onTap(p),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildCounter(List<ProductModel> ps) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        GestureDetector(
          onTap: (!_committing && !_snapping && _idx > 0)
              ? () => _commit(-1)
              : null,
          child: Icon(Icons.keyboard_arrow_up_rounded,
              size: 24,
              color: _idx > 0
                  ? AppColors.textSecondary
                  : AppColors.textDisabled),
        ),
        const Gap(16),
        // Dot indicators
        Row(
          mainAxisSize: MainAxisSize.min,
          children: List.generate(ps.length, (i) {
            final active = i == _idx;
            return AnimatedContainer(
              duration: const Duration(milliseconds: 280),
              width: active ? 22 : 7,
              height: 7,
              margin: const EdgeInsets.symmetric(horizontal: 2),
              decoration: BoxDecoration(
                color: active ? AppColors.dark : AppColors.border,
                borderRadius: BorderRadius.circular(4),
              ),
            );
          }),
        ),
        const Gap(16),
        GestureDetector(
          onTap: (!_committing &&
                  !_snapping &&
                  _idx < ps.length - 1)
              ? () => _commit(1)
              : null,
          child: Icon(Icons.keyboard_arrow_down_rounded,
              size: 24,
              color: _idx < ps.length - 1
                  ? AppColors.textSecondary
                  : AppColors.textDisabled),
        ),
      ],
    );
  }
}

// ── Supplement Object — tarro de proteína ────────────────────────────────────

class _SupplementObject extends StatelessWidget {
  final ProductModel product;
  final VoidCallback onAdd;
  final VoidCallback onTap;
  const _SupplementObject(
      {required this.product, required this.onAdd, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final p = product;
    return LayoutBuilder(builder: (_, box) {
      final w = box.maxWidth;
      final h = box.maxHeight;

      final tw = w * 0.74;
      final th = h * 0.93;
      final tx = (w - tw) / 2;
      final ty = (h - th) / 2;
      final lidH = th * 0.125;
      final lidW = tw + 10;
      final lidX = tx - 5;

      return GestureDetector(
        onTap: onTap,
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            // Ground shadow
            Positioned(
              bottom: ty + th * 0.015,
              left: tx + tw * 0.08,
              child: Container(
                width: tw * 0.84,
                height: 26,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(100),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.42),
                      blurRadius: 30,
                      spreadRadius: 4,
                    ),
                  ],
                ),
              ),
            ),

            // Tub body
            Positioned(
              left: tx,
              top: ty + lidH * 0.62,
              child: Container(
                width: tw,
                height: th - lidH * 0.62,
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    begin: Alignment.centerLeft,
                    end: Alignment.centerRight,
                    colors: [
                      Color(0xFF0A0A0A),
                      Color(0xFF1A1A1A),
                      Color(0xFF252525),
                      Color(0xFF1A1A1A),
                      Color(0xFF0A0A0A),
                    ],
                    stops: [0.0, 0.14, 0.50, 0.86, 1.0],
                  ),
                  borderRadius: BorderRadius.vertical(
                    bottom: Radius.circular(tw * 0.11),
                  ),
                ),
                child: Padding(
                  padding: EdgeInsets.fromLTRB(
                      16, lidH * 0.42 + 10, 16, 18),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('IRON BODY',
                          style: GoogleFonts.lexend(
                            fontSize: 8,
                            fontWeight: FontWeight.w900,
                            color: AppColors.primary,
                            letterSpacing: 3.0,
                          )),
                      const Gap(5),
                      Text(p.name,
                          style: GoogleFonts.lexend(
                            fontSize: 16,
                            fontWeight: FontWeight.w700,
                            color: Colors.white,
                            height: 1.2,
                          ),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis),
                      const Gap(7),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 9, vertical: 3),
                        decoration: BoxDecoration(
                          color:
                              Colors.white.withValues(alpha: 0.07),
                          borderRadius: BorderRadius.circular(99),
                          border: Border.all(
                              color: Colors.white
                                  .withValues(alpha: 0.12)),
                        ),
                        child: Text('SUPLEMENTO',
                            style: GoogleFonts.lexend(
                              fontSize: 7,
                              fontWeight: FontWeight.w700,
                              color: Colors.white54,
                              letterSpacing: 1.5,
                            )),
                      ),
                      const Spacer(),
                      if (p.isLowStock)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 7),
                          child: Text('⚠  Poco stock · ${p.stock} uds',
                              style: GoogleFonts.inter(
                                  fontSize: 9.5,
                                  color: const Color(0xFFFFAA33))),
                        ),
                      if (!p.isAvailable)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 7),
                          child: Text('✕  Agotado',
                              style: GoogleFonts.inter(
                                  fontSize: 9.5,
                                  color: const Color(0xFFFF5555))),
                        ),
                      Row(
                        mainAxisAlignment:
                            MainAxisAlignment.spaceBetween,
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Column(
                            crossAxisAlignment:
                                CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text('precio',
                                  style: GoogleFonts.inter(
                                    fontSize: 8,
                                    color: Colors.white38,
                                    letterSpacing: 0.5,
                                  )),
                              Text(CurrencyFormatter.format(p.price),
                                  style: GoogleFonts.lexend(
                                    fontSize: 19,
                                    fontWeight: FontWeight.w700,
                                    color: AppColors.primary,
                                  )),
                            ],
                          ),
                          _AddButton(
                              isAvailable: p.isAvailable,
                              onTap: onAdd),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),

            // Lid
            Positioned(
              left: lidX,
              top: ty,
              child: Container(
                width: lidW,
                height: lidH * 1.38,
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [
                      Color(0xFFFFEA68),
                      Color(0xFFFFD700),
                      Color(0xFFBB8C00),
                    ],
                  ),
                  borderRadius: BorderRadius.circular(11),
                  boxShadow: [
                    BoxShadow(
                      color: const Color(0xFFFFD700)
                          .withValues(alpha: 0.22),
                      blurRadius: 18,
                    ),
                  ],
                ),
                child: Center(
                  child: Text('PROTEÍNA',
                      style: GoogleFonts.lexend(
                        fontSize: 9,
                        fontWeight: FontWeight.w900,
                        color: const Color(0xFF1A1000),
                        letterSpacing: 2.5,
                      )),
                ),
              ),
            ),

            // Left edge highlight (3D cylinder feel)
            Positioned(
              left: tx + tw * 0.04,
              top: ty + lidH,
              child: Container(
                width: tw * 0.06,
                height: th - lidH * 1.2,
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.centerLeft,
                    end: Alignment.centerRight,
                    colors: [
                      Colors.white.withValues(alpha: 0.07),
                      Colors.transparent,
                    ],
                  ),
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
            ),
          ],
        ),
      );
    });
  }
}

// ── Drink Object — botella premium ───────────────────────────────────────────

class _DrinkObject extends StatelessWidget {
  final ProductModel product;
  final VoidCallback onAdd;
  final VoidCallback onTap;
  const _DrinkObject(
      {required this.product, required this.onAdd, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final p = product;
    return LayoutBuilder(builder: (_, box) {
      final w = box.maxWidth;
      final h = box.maxHeight;

      final bw = w * 0.58;
      final bx = (w - bw) / 2;

      return GestureDetector(
        onTap: onTap,
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            // Ground shadow
            Positioned(
              bottom: h * 0.015,
              left: bx + bw * 0.12,
              child: Container(
                width: bw * 0.76,
                height: 22,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(100),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.44),
                      blurRadius: 28,
                      spreadRadius: 3,
                    ),
                  ],
                ),
              ),
            ),

            // Bottle body (ClipPath)
            Positioned(
              left: bx,
              top: 0,
              child: ClipPath(
                clipper: _BottleClipper(),
                child: Container(
                  width: bw,
                  height: h,
                  decoration: const BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.centerLeft,
                      end: Alignment.centerRight,
                      colors: [
                        Color(0xFF0A0A0A),
                        Color(0xFF181818),
                        Color(0xFF222222),
                        Color(0xFF181818),
                        Color(0xFF0A0A0A),
                      ],
                      stops: [0.0, 0.14, 0.50, 0.86, 1.0],
                    ),
                  ),
                ),
              ),
            ),

            // Label sticker
            Positioned(
              left: bx + bw * 0.07,
              top: h * 0.31,
              child: Container(
                width: bw * 0.86,
                height: h * 0.46,
                decoration: BoxDecoration(
                  color: const Color(0xFF181818),
                  borderRadius: BorderRadius.circular(9),
                  border: Border.all(
                      color: Colors.white.withValues(alpha: 0.07)),
                ),
                padding:
                    const EdgeInsets.fromLTRB(11, 11, 11, 11),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('IRON BODY',
                        style: GoogleFonts.lexend(
                          fontSize: 7,
                          fontWeight: FontWeight.w900,
                          color: AppColors.primary,
                          letterSpacing: 2.5,
                        )),
                    const Gap(4),
                    Text(p.name,
                        style: GoogleFonts.lexend(
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          color: Colors.white,
                          height: 1.2,
                        ),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis),
                    const Gap(5),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 7, vertical: 2),
                      decoration: BoxDecoration(
                        color:
                            Colors.white.withValues(alpha: 0.06),
                        borderRadius: BorderRadius.circular(99),
                      ),
                      child: Text('BEBIDA',
                          style: GoogleFonts.lexend(
                            fontSize: 6.5,
                            fontWeight: FontWeight.w700,
                            color: Colors.white38,
                            letterSpacing: 1.5,
                          )),
                    ),
                    const Spacer(),
                    if (p.isLowStock)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 5),
                        child: Text('⚠  Poco stock',
                            style: GoogleFonts.inter(
                                fontSize: 8.5,
                                color: const Color(0xFFFFAA33))),
                      ),
                    if (!p.isAvailable)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 5),
                        child: Text('✕  Agotado',
                            style: GoogleFonts.inter(
                                fontSize: 8.5,
                                color: const Color(0xFFFF5555))),
                      ),
                    Row(
                      mainAxisAlignment:
                          MainAxisAlignment.spaceBetween,
                      crossAxisAlignment:
                          CrossAxisAlignment.end,
                      children: [
                        Column(
                          crossAxisAlignment:
                              CrossAxisAlignment.start,
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text('precio',
                                style: GoogleFonts.inter(
                                    fontSize: 7,
                                    color: Colors.white38)),
                            Text(
                                CurrencyFormatter.format(p.price),
                                style: GoogleFonts.lexend(
                                  fontSize: 15,
                                  fontWeight: FontWeight.w700,
                                  color: AppColors.primary,
                                )),
                          ],
                        ),
                        SizedBox(
                          width: 40,
                          height: 40,
                          child: _AddButton(
                              isAvailable: p.isAvailable,
                              onTap: onAdd),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),

            // Bottle cap (gold)
            Positioned(
              left: bx + bw * 0.32,
              top: 0,
              child: Container(
                width: bw * 0.36,
                height: h * 0.085,
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [
                      Color(0xFFFFEA68),
                      Color(0xFFFFD700),
                      Color(0xFFBB8C00),
                    ],
                  ),
                  borderRadius: BorderRadius.vertical(
                      top: Radius.circular(5)),
                ),
              ),
            ),

            // Left highlight
            Positioned(
              left: bx + bw * 0.07,
              top: h * 0.10,
              child: Container(
                width: bw * 0.07,
                height: h * 0.62,
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.centerLeft,
                    end: Alignment.centerRight,
                    colors: [
                      Colors.white.withValues(alpha: 0.06),
                      Colors.transparent,
                    ],
                  ),
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
            ),
          ],
        ),
      );
    });
  }
}

class _BottleClipper extends CustomClipper<Path> {
  @override
  Path getClip(Size size) {
    final w = size.width;
    final h = size.height;
    final path = Path();

    // Neck (32-68% width, top 26%)
    final nL = w * 0.32;
    final nR = w * 0.68;
    // Body (3-97% width)
    final bL = w * 0.03;
    final bR = w * 0.97;

    path.moveTo(nL, 0);
    path.lineTo(nR, 0);
    path.quadraticBezierTo(bR, h * 0.21, bR, h * 0.29);
    path.lineTo(bR, h * 0.90);
    path.quadraticBezierTo(bR, h * 0.97, bR - w * 0.06, h * 0.97);
    path.lineTo(bL + w * 0.06, h * 0.97);
    path.quadraticBezierTo(bL, h * 0.97, bL, h * 0.90);
    path.lineTo(bL, h * 0.29);
    path.quadraticBezierTo(bL, h * 0.21, nL, 0);
    path.close();
    return path;
  }

  @override
  bool shouldReclip(_BottleClipper old) => false;
}

// ── Snack Object — empaque premium ───────────────────────────────────────────

class _SnackObject extends StatelessWidget {
  final ProductModel product;
  final VoidCallback onAdd;
  final VoidCallback onTap;
  const _SnackObject(
      {required this.product, required this.onAdd, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final p = product;
    return LayoutBuilder(builder: (_, box) {
      final w = box.maxWidth;
      final h = box.maxHeight;

      final bw = w * 0.86;
      final bh = h * 0.91;
      final bx = (w - bw) / 2;
      final by = (h - bh) / 2;

      return GestureDetector(
        onTap: onTap,
        child: Stack(
          clipBehavior: Clip.none,
          children: [
            // Ground shadow
            Positioned(
              bottom: by - 2,
              left: bx + bw * 0.10,
              child: Container(
                width: bw * 0.80,
                height: 24,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(100),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withValues(alpha: 0.40),
                      blurRadius: 26,
                      spreadRadius: 3,
                    ),
                  ],
                ),
              ),
            ),

            // Bag shape
            Positioned(
              left: bx,
              top: by,
              child: ClipPath(
                clipper: _BagClipper(),
                child: Container(
                  width: bw,
                  height: bh,
                  decoration: const BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: [
                        Color(0xFF0E0E0E),
                        Color(0xFF1B1B1B),
                        Color(0xFF222222),
                        Color(0xFF141414),
                      ],
                    ),
                  ),
                ),
              ),
            ),

            // Gold top seal accent
            Positioned(
              left: bx + bw * 0.16,
              top: by + bh * 0.01,
              child: Container(
                width: bw * 0.68,
                height: bh * 0.07,
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [
                      Colors.transparent,
                      const Color(0xFFFFD700)
                          .withValues(alpha: 0.38),
                      Colors.transparent,
                    ],
                  ),
                  borderRadius: BorderRadius.circular(4),
                ),
              ),
            ),

            // Front panel (label)
            Positioned(
              left: bx + bw * 0.07,
              top: by + bh * 0.14,
              child: Container(
                width: bw * 0.86,
                height: bh * 0.68,
                decoration: BoxDecoration(
                  color: const Color(0xFF191919),
                  borderRadius: BorderRadius.circular(11),
                  border: Border.all(
                      color: Colors.white.withValues(alpha: 0.07)),
                ),
                padding:
                    const EdgeInsets.fromLTRB(14, 13, 14, 14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('IRON BODY',
                        style: GoogleFonts.lexend(
                          fontSize: 7.5,
                          fontWeight: FontWeight.w900,
                          color: AppColors.primary,
                          letterSpacing: 3.0,
                        )),
                    const Gap(5),
                    Text(p.name,
                        style: GoogleFonts.lexend(
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                          color: Colors.white,
                          height: 1.2,
                        ),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis),
                    const Gap(7),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 9, vertical: 3),
                      decoration: BoxDecoration(
                        color:
                            Colors.white.withValues(alpha: 0.07),
                        borderRadius: BorderRadius.circular(99),
                        border: Border.all(
                            color: Colors.white
                                .withValues(alpha: 0.10)),
                      ),
                      child: Text('SNACK',
                          style: GoogleFonts.lexend(
                            fontSize: 7,
                            fontWeight: FontWeight.w700,
                            color: Colors.white38,
                            letterSpacing: 1.5,
                          )),
                    ),
                    const Spacer(),
                    if (p.isLowStock)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 7),
                        child: Text('⚠  Poco stock · ${p.stock}',
                            style: GoogleFonts.inter(
                                fontSize: 9.5,
                                color: const Color(0xFFFFAA33))),
                      ),
                    if (!p.isAvailable)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 7),
                        child: Text('✕  Agotado',
                            style: GoogleFonts.inter(
                                fontSize: 9.5,
                                color: const Color(0xFFFF5555))),
                      ),
                    Row(
                      mainAxisAlignment:
                          MainAxisAlignment.spaceBetween,
                      crossAxisAlignment:
                          CrossAxisAlignment.end,
                      children: [
                        Column(
                          crossAxisAlignment:
                              CrossAxisAlignment.start,
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text('precio',
                                style: GoogleFonts.inter(
                                  fontSize: 8,
                                  color: Colors.white38,
                                  letterSpacing: 0.5,
                                )),
                            Text(
                                CurrencyFormatter.format(p.price),
                                style: GoogleFonts.lexend(
                                  fontSize: 19,
                                  fontWeight: FontWeight.w700,
                                  color: AppColors.primary,
                                )),
                          ],
                        ),
                        _AddButton(
                            isAvailable: p.isAvailable,
                            onTap: onAdd),
                      ],
                    ),
                  ],
                ),
              ),
            ),

            // Plastic sheen diagonal
            Positioned(
              left: bx + bw * 0.04,
              top: by + bh * 0.04,
              child: Container(
                width: bw * 0.20,
                height: bh * 0.22,
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [
                      Colors.white.withValues(alpha: 0.07),
                      Colors.transparent,
                    ],
                  ),
                  borderRadius: BorderRadius.circular(18),
                ),
              ),
            ),
          ],
        ),
      );
    });
  }
}

class _BagClipper extends CustomClipper<Path> {
  @override
  Path getClip(Size size) {
    final w = size.width;
    final h = size.height;
    final path = Path();

    // Top: slightly concave seal
    path.moveTo(0, h * 0.08);
    path.quadraticBezierTo(w * 0.5, h * 0.01, w, h * 0.08);
    path.lineTo(w, h * 0.91);
    // Bottom: slightly concave seal
    path.quadraticBezierTo(w * 0.5, h * 0.98, 0, h * 0.91);
    path.lineTo(0, h * 0.08);
    path.close();
    return path;
  }

  @override
  bool shouldReclip(_BagClipper old) => false;
}

// ── Add Button ────────────────────────────────────────────────────────────────

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
        vsync: this,
        duration: const Duration(milliseconds: 110));
    _scale = Tween<double>(begin: 1.0, end: 0.78).animate(
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
          width: 44,
          height: 44,
          decoration: BoxDecoration(
            color: widget.isAvailable
                ? AppColors.primary
                : Colors.white.withValues(alpha: 0.12),
            borderRadius: BorderRadius.circular(14),
            boxShadow: widget.isAvailable
                ? [
                    BoxShadow(
                      color: AppColors.primary.withValues(alpha: 0.35),
                      blurRadius: 14,
                      offset: const Offset(0, 4),
                    )
                  ]
                : [],
          ),
          child: Icon(
            Icons.add_rounded,
            size: 22,
            color: widget.isAvailable
                ? AppColors.dark
                : Colors.white38,
          ),
        ),
      ),
    );
  }
}
