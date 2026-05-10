import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/class_session_model.dart';
import '../../../shared/widgets/status_badge.dart';
import 'class_detail_screen.dart';

double _lerpC(double a, double b, double t) => a + (b - a) * t;

// ── ClassesScreen ─────────────────────────────────────────────────────────────

class ClassesScreen extends StatefulWidget {
  const ClassesScreen({super.key});

  @override
  State<ClassesScreen> createState() => _ClassesScreenState();
}

class _ClassesScreenState extends State<ClassesScreen> {
  String _filter = 'Todas';
  final _filters = [
    'Todas',
    'Cardio',
    'Fuerza',
    'CrossFit',
    'Core',
    'Flexibilidad'
  ];
  late List<ClassSessionModel> _classes;

  @override
  void initState() {
    super.initState();
    _classes = mockClasses;
  }

  List<ClassSessionModel> get _filtered => _filter == 'Todas'
      ? _classes
      : _classes.where((c) => c.type == _filter).toList();

  void _reserve(ClassSessionModel session) {
    setState(() {
      session.isReserved = !session.isReserved;
      if (session.isReserved) {
        session.bookedSpots++;
        session.status = ClassStatus.reserved;
      } else {
        session.bookedSpots--;
        session.status = ClassSessionModel.computeStatus(
            session.bookedSpots, session.totalSpots);
      }
    });
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content:
          Text(session.isReserved ? 'Clase reservada' : 'Reserva cancelada'),
      backgroundColor: session.isReserved
          ? const Color(0xFF155724)
          : AppColors.textSecondary,
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final sessions = _filtered;
    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: AppBar(
        backgroundColor: AppColors.surface0,
        elevation: 0,
        scrolledUnderElevation: 0,
        title: Text(
          'Clases',
          style: GoogleFonts.lexend(
              fontSize: 20,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary),
        ),
      ),
      body: Column(
        children: [
          // ── Filter chips ───────────────────────────────────────────────
          SizedBox(
            height: 36,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 20),
              itemCount: _filters.length,
              separatorBuilder: (_, _) => const Gap(8),
              itemBuilder: (_, i) {
                final f = _filters[i];
                final active = f == _filter;
                return GestureDetector(
                  onTap: () => setState(() => _filter = f),
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
                    child: Text(f,
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

          // ── Class deck ─────────────────────────────────────────────────
          Expanded(
            child: sessions.isEmpty
                ? _emptyState()
                : Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 24),
                    child: _ClassDeck(
                      key: ValueKey(_filter),
                      sessions: sessions,
                      onReserve: _reserve,
                      onTap: (s) => Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => ClassDetailScreen(
                            session: s,
                            onReserve: () => _reserve(s),
                          ),
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
            Icon(Icons.fitness_center_rounded,
                size: 52, color: AppColors.textDisabled),
            const Gap(12),
            Text('Sin clases',
                style: GoogleFonts.lexend(
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary)),
            const Gap(4),
            Text('Prueba con otra categoría',
                style: GoogleFonts.inter(
                    fontSize: 13, color: AppColors.textSecondary)),
          ],
        ),
      );
}

// ── Class Deck ────────────────────────────────────────────────────────────────

class _ClassDeck extends StatefulWidget {
  final List<ClassSessionModel> sessions;
  final void Function(ClassSessionModel) onReserve;
  final void Function(ClassSessionModel) onTap;

  const _ClassDeck(
      {super.key,
      required this.sessions,
      required this.onReserve,
      required this.onTap});

  @override
  State<_ClassDeck> createState() => _ClassDeckState();
}

class _ClassDeckState extends State<_ClassDeck> with TickerProviderStateMixin {
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
  static const _kTY = [0.0, 52.0, 90.0];
  static const _kSX = [1.00, 0.93, 0.86];
  static const _kOP = [1.00, 0.72, 0.44];

  @override
  void initState() {
    super.initState();

    _commitCtrl = AnimationController(
        vsync: this, duration: const Duration(milliseconds: 370))
      ..addListener(() => setState(() {}))
      ..addStatusListener((s) {
        if (s == AnimationStatus.completed) {
          setState(() {
            _idx =
                (_idx + _dir).clamp(0, widget.sessions.length - 1);
            _drag = 0;
            _dragSnapshot = 0;
            _committing = false;
            _dir = 0;
          });
          _commitCtrl.reset();
        }
      });

    _snapCtrl = AnimationController(
        vsync: this, duration: const Duration(milliseconds: 300))
      ..addListener(() {
        if (!_snapping) return;
        setState(() {
          _drag = _lerpC(
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
  void didUpdateWidget(_ClassDeck old) {
    super.didUpdateWidget(old);
    if (old.sessions != widget.sessions) {
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
    if (_drag > _thresh && _idx < widget.sessions.length - 1) {
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
  double get _dragP => (_drag.abs() / _thresh).clamp(0.0, 1.0);

  @override
  Widget build(BuildContext context) {
    final ss = widget.sessions;
    return LayoutBuilder(builder: (_, box) {
      final cardH = (box.maxHeight * 0.63).clamp(260.0, 450.0);
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
                children: _buildLayers(ss, cardH),
              ),
            ),
          ),
          Positioned(
            right: 0,
            top: 0,
            bottom: 0,
            child: Center(
              child: _ClassSwipeIndicator(
                canGoUp: _idx > 0,
                canGoDown: _idx < ss.length - 1,
              ),
            ),
          ),
        ],
      );
    });
  }

  List<Widget> _buildLayers(List<ClassSessionModel> ss, double cardH) {
    final layers = <({int z, Widget w})>[];
    final goingNext = _dir == 1 || (_dir == 0 && _drag >= 0);

    if (goingNext) {
      final p = _committing ? _animP : _dragP;

      for (int slot = 0; slot < 3; slot++) {
        final si = _idx + slot;
        if (si >= ss.length) break;

        double ty, sx, op;
        if (slot == 0) {
          if (_committing) {
            ty = _lerpC(_dragSnapshot, cardH + 120, _animP);
            sx = _kSX[1];
            op = 0.0;
          } else {
            ty = _drag;
            sx = _lerpC(_kSX[0], _kSX[1], p);
            op = _lerpC(1.0, 0.0, (p * 1.3).clamp(0, 1));
          }
        } else {
          ty = _lerpC(_kTY[slot], _kTY[slot - 1], p);
          sx = _lerpC(_kSX[slot], _kSX[slot - 1], p);
          op = _lerpC(_kOP[slot], _kOP[slot - 1], p);
        }

        layers.add((
          z: 2 - slot,
          w: _card(ss[si], ty, sx, op, cardH,
              interactive: slot == 0 && !_committing)
        ));
      }

      if (_committing && _idx + 3 < ss.length) {
        final ty = _lerpC(_kTY[2] + 50, _kTY[2], _animP);
        final sx = _lerpC(_kSX[2] - 0.08, _kSX[2], _animP);
        final op = _lerpC(0.0, _kOP[2], _animP);
        layers.add((z: 0, w: _card(ss[_idx + 3], ty, sx, op, cardH)));
      }
    } else {
      final p = _committing ? _animP : _dragP;

      if (_idx > 0) {
        final ty = _lerpC(-cardH * 0.32, _kTY[0], p);
        final sx = _lerpC(_kSX[1], _kSX[0], p);
        final op = _lerpC(0.0, _kOP[0], p);
        layers.add((
          z: 3,
          w: _card(ss[_idx - 1], ty, sx, op, cardH,
              interactive: _committing && _commitCtrl.value > 0.7)
        ));
      }

      for (int slot = 0; slot < 3; slot++) {
        final si = _idx + slot;
        if (si >= ss.length) break;

        double ty, sx, op;
        if (slot == 2) {
          ty = _lerpC(_kTY[2], _kTY[2] + 12, p);
          sx = _lerpC(_kSX[2], _kSX[2] - 0.05, p);
          op = _lerpC(_kOP[2], 0.0, p);
        } else {
          ty = _lerpC(_kTY[slot], _kTY[slot + 1], p);
          sx = _lerpC(_kSX[slot], _kSX[slot + 1], p);
          op = _lerpC(_kOP[slot], _kOP[slot + 1], p);
        }

        layers.add((z: 2 - slot, w: _card(ss[si], ty, sx, op, cardH)));
      }
    }

    layers.sort((a, b) => a.z.compareTo(b.z));
    return layers.map((l) => l.w).toList();
  }

  Widget _card(ClassSessionModel s, double ty, double sx, double op,
      double cardH,
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
              child: _StackClassCard(
                session: s,
                onReserve: () => widget.onReserve(s),
                onTap: () => widget.onTap(s),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

// ── Swipe Indicator ───────────────────────────────────────────────────────────

class _ClassSwipeIndicator extends StatefulWidget {
  final bool canGoUp;
  final bool canGoDown;
  const _ClassSwipeIndicator(
      {required this.canGoUp, required this.canGoDown});

  @override
  State<_ClassSwipeIndicator> createState() => _ClassSwipeIndicatorState();
}

class _ClassSwipeIndicatorState extends State<_ClassSwipeIndicator>
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
                  color: AppColors.dark.withValues(
                      alpha: widget.canGoUp ? 0.32 + t * 0.52 : 0.15),
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
                    color:
                        AppColors.dark.withValues(alpha: 0.10 + t * 0.14),
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

// ── Stack Class Card ──────────────────────────────────────────────────────────

class _StackClassCard extends StatelessWidget {
  final ClassSessionModel session;
  final VoidCallback onReserve;
  final VoidCallback onTap;

  const _StackClassCard(
      {required this.session, required this.onReserve, required this.onTap});

  (String, BadgeVariant) get _statusInfo => switch (session.status) {
        ClassStatus.available => ('Disponible', BadgeVariant.success),
        ClassStatus.fewSpots => ('Pocos cupos', BadgeVariant.warning),
        ClassStatus.waitlist => ('Lista de espera', BadgeVariant.info),
        ClassStatus.reserved => ('Reservada', BadgeVariant.success),
        ClassStatus.full => ('Lleno', BadgeVariant.error),
      };

  @override
  Widget build(BuildContext context) {
    final (statusLabel, variant) = _statusInfo;
    final h = session.dateTime.hour;
    final m = session.dateTime.minute.toString().padLeft(2, '0');
    final ampm = h >= 12 ? 'PM' : 'AM';
    final hour = h > 12 ? h - 12 : (h == 0 ? 12 : h);
    final timeStr = '$hour:$m $ampm';

    return GestureDetector(
      onTap: onTap,
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(24),
          image: DecorationImage(
            image: AssetImage(AppAssets.backgroundClases),
            fit: BoxFit.cover,
            opacity: 0.10,
          ),
          border: Border.all(color: const Color(0xFFE8E4DC)),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.07),
              blurRadius: 20,
              offset: const Offset(0, 8),
            ),
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.03),
              blurRadius: 5,
              offset: const Offset(0, 2),
            ),
          ],
        ),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // ── Type badge + status ──────────────────────────────────
              Row(
                children: [
                  _TypeBadge(type: session.type),
                  const Spacer(),
                  StatusBadge(label: statusLabel, variant: variant),
                ],
              ),

              const Gap(14),

              // ── Class name ───────────────────────────────────────────
              Text(
                session.name,
                style: GoogleFonts.lexend(
                  fontSize: 22,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary,
                  height: 1.2,
                ),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),

              const Spacer(),

              // ── Info row ─────────────────────────────────────────────
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AppColors.surfaceContainerLow,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Column(
                  children: [
                    _infoRow(
                        Icons.person_outline_rounded, session.instructor),
                    const Gap(8),
                    Row(
                      children: [
                        Expanded(
                          child: _infoRow(
                              Icons.access_time_rounded, timeStr),
                        ),
                        Expanded(
                          child: _infoRow(Icons.timer_outlined,
                              '${session.durationMinutes} min'),
                        ),
                      ],
                    ),
                  ],
                ),
              ),

              const Gap(14),

              // ── Spots + reserve button ────────────────────────────────
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        '${session.availableSpots}',
                        style: GoogleFonts.lexend(
                          fontSize: 22,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                        ),
                      ),
                      Text(
                        'cupos disponibles',
                        style: GoogleFonts.inter(
                          fontSize: 11,
                          color: AppColors.textDisabled,
                        ),
                      ),
                    ],
                  ),
                  GestureDetector(
                    onTap: session.status == ClassStatus.full &&
                            !session.isReserved
                        ? null
                        : onReserve,
                    child: AnimatedContainer(
                      duration: const Duration(milliseconds: 200),
                      padding: const EdgeInsets.symmetric(
                          horizontal: 20, vertical: 11),
                      decoration: BoxDecoration(
                        color: session.isReserved
                            ? AppColors.surfaceContainerLow
                            : AppColors.dark,
                        borderRadius: BorderRadius.circular(99),
                        boxShadow: session.isReserved
                            ? []
                            : [
                                BoxShadow(
                                  color: AppColors.dark
                                      .withValues(alpha: 0.22),
                                  blurRadius: 10,
                                  offset: const Offset(0, 4),
                                ),
                              ],
                      ),
                      child: Text(
                        session.isReserved ? 'Cancelar' : 'Reservar',
                        style: GoogleFonts.lexend(
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          color: session.isReserved
                              ? AppColors.textSecondary
                              : AppColors.onDark,
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _infoRow(IconData icon, String label) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 13, color: AppColors.textSecondary),
          const Gap(5),
          Flexible(
            child: Text(label,
                style: GoogleFonts.inter(
                    fontSize: 12, color: AppColors.textSecondary),
                overflow: TextOverflow.ellipsis),
          ),
        ],
      );
}

class _TypeBadge extends StatelessWidget {
  final String type;
  const _TypeBadge({required this.type});

  static const _colors = {
    'Cardio': (Color(0xFFFFEBEB), Color(0xFFCC2200)),
    'Fuerza': (Color(0xFFEBF0FF), Color(0xFF1A3ACC)),
    'CrossFit': (Color(0xFFEBFFF0), Color(0xFF005522)),
    'Core': (Color(0xFFFFF3EB), Color(0xFFBB5500)),
    'Flexibilidad': (Color(0xFFF5EBFF), Color(0xFF6600CC)),
  };

  @override
  Widget build(BuildContext context) {
    final colors = _colors[type] ?? (const Color(0xFFF0F0F0), const Color(0xFF555555));
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: colors.$1,
        borderRadius: BorderRadius.circular(99),
      ),
      child: Text(
        type.toUpperCase(),
        style: GoogleFonts.lexend(
          fontSize: 8,
          fontWeight: FontWeight.w700,
          color: colors.$2,
          letterSpacing: 1.5,
        ),
      ),
    );
  }
}
