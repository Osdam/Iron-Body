import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/constants/app_assets.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/class_session_model.dart';
import '../../../shared/widgets/status_badge.dart';

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
          ? AppColors.dark
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

  const _ClassDeck({
    super.key,
    required this.sessions,
    required this.onReserve,
  });

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
            _idx = (_idx + _dir).clamp(0, widget.sessions.length - 1);
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
    setState(() => _drag += d.delta.dy * 0.88);
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
  double get _animPExit => Curves.easeInCubic.transform(_commitCtrl.value);
  double get _animPEnter => Curves.easeOutCubic.transform(_commitCtrl.value);
  double get _dragP {
    final raw = (_drag.abs() / _thresh).clamp(0.0, 1.0);
    return Curves.easeOut.transform(raw);
  }

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
            ty = _lerpC(_kTY[0] + _dragSnapshot, cardH + 120, _animPExit);
            sx = _lerpC(_kSX[0], _kSX[1], _animPExit);
            op = (1.0 - _animPExit * 2.5).clamp(0.0, 1.0);
          } else {
            ty = _kTY[0] + _drag;
            sx = _lerpC(_kSX[0], _kSX[1], p);
            op = _lerpC(1.0, 0.0, (p * 1.3).clamp(0, 1));
          }
        } else {
          final ep = _committing ? _animPEnter : p;
          ty = _lerpC(_kTY[slot], _kTY[slot - 1], ep);
          sx = _lerpC(_kSX[slot], _kSX[slot - 1], ep);
          op = _lerpC(_kOP[slot], _kOP[slot - 1], ep);
        }

        layers.add((
          z: 2 - slot,
          w: _card(ss[si], ty, sx, op, cardH,
              interactive: slot == 0 && !_committing)
        ));
      }

      if (_committing && _idx + 3 < ss.length) {
        final ty = _lerpC(_kTY[2] - 30, _kTY[2], _animPEnter);
        final sx = _lerpC(_kSX[2] - 0.08, _kSX[2], _animPEnter);
        final op = _lerpC(0.0, _kOP[2], _animPEnter);
        layers.add((z: 0, w: _card(ss[_idx + 3], ty, sx, op, cardH)));
      }
    } else {
      final p = _committing ? _animP : _dragP;

      if (_idx > 0) {
        final ty = _lerpC(cardH * 0.6, _kTY[0], p);
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
          ty = _lerpC(_kTY[2], _kTY[2] - 8, p);
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
                key: ValueKey(s.id),
                session: s,
                onReserve: () => widget.onReserve(s),
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

// ── Stack Class Card (with 3D flip) ───────────────────────────────────────────

class _StackClassCard extends StatefulWidget {
  final ClassSessionModel session;
  final VoidCallback onReserve;

  const _StackClassCard({
    super.key,
    required this.session,
    required this.onReserve,
  });

  @override
  State<_StackClassCard> createState() => _StackClassCardState();
}

class _StackClassCardState extends State<_StackClassCard>
    with SingleTickerProviderStateMixin {
  late final AnimationController _flipCtrl;

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

  (String, BadgeVariant) get _statusInfo => switch (widget.session.status) {
        ClassStatus.available => ('Disponible', BadgeVariant.success),
        ClassStatus.fewSpots => ('Pocos cupos', BadgeVariant.warning),
        ClassStatus.waitlist => ('Lista de espera', BadgeVariant.info),
        ClassStatus.reserved => ('Reservada', BadgeVariant.success),
        ClassStatus.full => ('Lleno', BadgeVariant.error),
      };

  String _timeStr() {
    final h = widget.session.dateTime.hour;
    final m = widget.session.dateTime.minute.toString().padLeft(2, '0');
    final ampm = h >= 12 ? 'PM' : 'AM';
    final hour = h > 12 ? h - 12 : (h == 0 ? 12 : h);
    return '$hour:$m $ampm';
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
    final s = widget.session;
    final (statusLabel, variant) = _statusInfo;

    return GestureDetector(
      onTap: _flip,
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(24),
          image: DecorationImage(
            image: AssetImage(AppAssets.backgroundClases),
            fit: BoxFit.cover,
            opacity: 0.25,
          ),
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
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  _TypeBadge(type: s.type),
                  const Spacer(),
                  StatusBadge(label: statusLabel, variant: variant),
                ],
              ),
              const Gap(14),
              Text(
                s.name,
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
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AppColors.surfaceContainerLow,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Column(
                  children: [
                    _infoRow(Icons.person_outline_rounded, s.instructor),
                    const Gap(8),
                    Row(
                      children: [
                        Expanded(
                            child: _infoRow(
                                Icons.access_time_rounded, _timeStr())),
                        Expanded(
                            child: _infoRow(Icons.timer_outlined,
                                '${s.durationMinutes} min')),
                      ],
                    ),
                  ],
                ),
              ),
              const Gap(14),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        '${s.availableSpots}',
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
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.touch_app_rounded,
                          size: 12, color: AppColors.textDisabled),
                      const Gap(4),
                      Text(
                        'Ver detalle',
                        style: GoogleFonts.inter(
                          fontSize: 11,
                          color: AppColors.textDisabled,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ── Back ──────────────────────────────────────────────────────────────────

  Widget _buildBack() {
    final s = widget.session;
    final (statusLabel, variant) = _statusInfo;
    final canReserve =
        s.status != ClassStatus.full || s.isReserved;

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
            // ── Back header ───────────────────────────────────────────────
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
                        _TypeBadge(type: s.type),
                        const Gap(6),
                        Text(
                          s.name,
                          style: GoogleFonts.lexend(
                            fontSize: 17,
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

            // ── Back detail ───────────────────────────────────────────────
            Expanded(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(18, 16, 18, 0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Info grid
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: AppColors.surfaceContainerLow,
                        borderRadius: BorderRadius.circular(14),
                      ),
                      child: Column(
                        children: [
                          _backInfoRow(Icons.person_outline_rounded,
                              'Instructor', s.instructor),
                          const Gap(8),
                          Row(
                            children: [
                              Expanded(
                                child: _backInfoRow(
                                    Icons.access_time_rounded,
                                    'Hora',
                                    _timeStr()),
                              ),
                              Expanded(
                                child: _backInfoRow(
                                    Icons.timer_outlined,
                                    'Duración',
                                    '${s.durationMinutes} min'),
                              ),
                            ],
                          ),
                          const Gap(8),
                          Row(
                            children: [
                              Expanded(
                                child: _backInfoRow(
                                    Icons.group_outlined,
                                    'Cupos',
                                    '${s.availableSpots} / ${s.totalSpots}'),
                              ),
                              Expanded(
                                child: _backInfoRow(
                                    Icons.flag_outlined,
                                    'Estado',
                                    statusLabel),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),

                    const Gap(14),

                    // Description
                    if (s.description.isNotEmpty) ...[
                      Text(
                        'Sobre la clase',
                        style: GoogleFonts.lexend(
                          fontSize: 12,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textSecondary,
                          letterSpacing: 0.5,
                        ),
                      ),
                      const Gap(6),
                      Text(
                        s.description,
                        style: GoogleFonts.inter(
                          fontSize: 12,
                          color: AppColors.textSecondary,
                          height: 1.55,
                        ),
                        maxLines: 3,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],

                    const Spacer(),

                    // Status badge + reserve button
                    Row(
                      children: [
                        StatusBadge(label: statusLabel, variant: variant),
                        const Spacer(),
                      ],
                    ),
                    const Gap(10),
                    GestureDetector(
                      onTap: canReserve ? widget.onReserve : null,
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        height: 48,
                        decoration: BoxDecoration(
                          color: s.isReserved
                              ? AppColors.surfaceContainerLow
                              : canReserve
                                  ? AppColors.dark
                                  : AppColors.surfaceContainer,
                          borderRadius: BorderRadius.circular(14),
                          boxShadow: (!s.isReserved && canReserve)
                              ? [
                                  BoxShadow(
                                    color:
                                        AppColors.dark.withValues(alpha: 0.18),
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
                              s.isReserved
                                  ? Icons.cancel_outlined
                                  : Icons.check_circle_outline_rounded,
                              size: 16,
                              color: s.isReserved
                                  ? AppColors.textSecondary
                                  : canReserve
                                      ? AppColors.primary
                                      : AppColors.textDisabled,
                            ),
                            const Gap(7),
                            Text(
                              s.isReserved
                                  ? 'Cancelar reserva'
                                  : canReserve
                                      ? 'Reservar clase'
                                      : 'Sin cupos',
                              style: GoogleFonts.lexend(
                                fontSize: 13,
                                fontWeight: FontWeight.w700,
                                color: s.isReserved
                                    ? AppColors.textSecondary
                                    : canReserve
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

  Widget _backInfoRow(IconData icon, String label, String value) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 13, color: AppColors.textSecondary),
          const Gap(6),
          Flexible(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(label,
                    style: GoogleFonts.inter(
                        fontSize: 9,
                        color: AppColors.textDisabled,
                        fontWeight: FontWeight.w600)),
                Text(value,
                    style: GoogleFonts.inter(
                        fontSize: 12, color: AppColors.textPrimary),
                    overflow: TextOverflow.ellipsis),
              ],
            ),
          ),
        ],
      );
}

class _TypeBadge extends StatelessWidget {
  final String type;
  const _TypeBadge({required this.type});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(99),
        border: Border.all(
            color: Colors.white.withValues(alpha: 0.30), width: 1),
      ),
      child: Text(
        type.toUpperCase(),
        style: GoogleFonts.lexend(
          fontSize: 8,
          fontWeight: FontWeight.w700,
          color: Colors.white,
          letterSpacing: 1.5,
        ),
      ),
    );
  }
}
