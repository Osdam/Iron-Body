import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../data/mock_trainers.dart';
import '../models/trainer_model.dart';
import '../services/trainer_rating_service.dart';

double _lerpT(double a, double b, double t) => a + (b - a) * t;

// ── TrainersScreen ────────────────────────────────────────────────────────────

class TrainersScreen extends StatefulWidget {
  const TrainersScreen({super.key});

  @override
  State<TrainersScreen> createState() => _TrainersScreenState();
}

class _TrainersScreenState extends State<TrainersScreen> {
  late List<TrainerModel> _trainers;

  @override
  void initState() {
    super.initState();
    _trainers = List.from(mockTrainers)
      ..sort((a, b) => b.averageRating.compareTo(a.averageRating));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: AppBar(
        backgroundColor: AppColors.surface0,
        elevation: 0,
        scrolledUnderElevation: 0,
        toolbarHeight: 72,
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Ranking de entrenadores',
              style: GoogleFonts.lexend(
                fontSize: 18,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
              ),
            ),
            const Gap(1),
            Text(
              'Toca una card para calificar',
              style: GoogleFonts.inter(
                fontSize: 11,
                color: AppColors.textSecondary,
              ),
            ),
          ],
        ),
      ),
      body: _HorizontalDeck(
        trainers: _trainers,
        onRated: () => setState(() {}),
      ),
    );
  }
}

// ── Horizontal Deck ───────────────────────────────────────────────────────────

class _HorizontalDeck extends StatefulWidget {
  final List<TrainerModel> trainers;
  final VoidCallback onRated;

  const _HorizontalDeck({required this.trainers, required this.onRated});

  @override
  State<_HorizontalDeck> createState() => _HorizontalDeckState();
}

class _HorizontalDeckState extends State<_HorizontalDeck>
    with TickerProviderStateMixin {
  int _idx = 0;
  double _drag = 0;
  double _dragSnapshot = 0;

  late final AnimationController _commitCtrl;
  late final AnimationController _snapCtrl;

  bool _committing = false;
  bool _snapping = false;
  double _snapStart = 0;
  int _dir = 0;

  static const _thresh = 64.0;
  // Front card centered: 28px each side. Ghosts peek to the right.
  static const _kTX = [28.0, 52.0, 72.0];
  static const _kSC = [1.00, 0.95, 0.90];
  static const _kOP = [1.00, 0.68, 0.38];

  @override
  void initState() {
    super.initState();

    _commitCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 460),
    )
      ..addListener(() => setState(() {}))
      ..addStatusListener((s) {
        if (s == AnimationStatus.completed) {
          setState(() {
            _idx = (_idx + _dir).clamp(0, widget.trainers.length - 1);
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
      duration: const Duration(milliseconds: 320),
    )
      ..addListener(() {
        if (!_snapping) return;
        setState(() {
          _drag = _lerpT(
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
  void didUpdateWidget(_HorizontalDeck old) {
    super.didUpdateWidget(old);
    if (old.trainers != widget.trainers) {
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
    setState(() => _drag += d.delta.dx * 0.88);
  }

  void _onDragEnd(DragEndDetails d) {
    if (_committing || _snapping) return;
    if (_drag < -_thresh && _idx < widget.trainers.length - 1) {
      _commit(1);
    } else if (_drag > _thresh && _idx > 0) {
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
    final ts = widget.trainers;
    if (ts.isEmpty) {
      return Center(
        child: Text(
          'Sin entrenadores',
          style: GoogleFonts.lexend(fontSize: 15, color: AppColors.textSecondary),
        ),
      );
    }
    return Column(
      children: [
        Expanded(
          child: LayoutBuilder(builder: (_, box) {
            final cardW = box.maxWidth - 56;
            final cardH = (box.maxHeight * 0.92).clamp(380.0, 520.0);
            final topOffset =
                ((box.maxHeight - cardH) / 2).clamp(0.0, double.infinity);
            return GestureDetector(
              behavior: HitTestBehavior.translucent,
              onHorizontalDragUpdate: _onDragUpdate,
              onHorizontalDragEnd: _onDragEnd,
              child: SizedBox.expand(
                child: Stack(
                  clipBehavior: Clip.none,
                  children: _buildLayers(ts, cardW, cardH, topOffset),
                ),
              ),
            );
          }),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 14),
          child: _buildDots(widget.trainers.length),
        ),
      ],
    );
  }

  Widget _buildDots(int count) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: List.generate(count, (i) {
        final active = i == _idx;
        return AnimatedContainer(
          duration: const Duration(milliseconds: 200),
          margin: const EdgeInsets.symmetric(horizontal: 3),
          width: active ? 20 : 7,
          height: 7,
          decoration: BoxDecoration(
            color: active ? AppColors.dark : AppColors.surfaceContainer,
            borderRadius: BorderRadius.circular(4),
          ),
        );
      }),
    );
  }

  List<Widget> _buildLayers(
      List<TrainerModel> ts, double cardW, double cardH, double topOffset) {
    final layers = <({int z, Widget w})>[];
    final goingNext = _dir == 1 || (_dir == 0 && _drag <= 0);

    if (goingNext) {
      final p = _committing ? _animP : _dragP;

      for (int slot = 0; slot < 3; slot++) {
        final ti = _idx + slot;
        if (ti >= ts.length) break;

        double tx, sc, op;
        if (slot == 0) {
          if (_committing) {
            tx = _lerpT(_kTX[0] + _dragSnapshot, -(cardW + 120), _animPExit);
            sc = _lerpT(_kSC[0], _kSC[1], _animPExit);
            op = (1.0 - _animPExit * 2.5).clamp(0.0, 1.0);
          } else {
            tx = _kTX[0] + _drag;
            sc = _lerpT(_kSC[0], _kSC[1], p);
            op = _lerpT(1.0, 0.0, (p * 1.3).clamp(0, 1));
          }
        } else {
          final ep = _committing ? _animPEnter : p;
          tx = _lerpT(_kTX[slot], _kTX[slot - 1], ep);
          sc = _lerpT(_kSC[slot], _kSC[slot - 1], ep);
          op = _lerpT(_kOP[slot], _kOP[slot - 1], ep);
        }

        layers.add((
          z: 2 - slot,
          w: _card(ts[ti], ti + 1, tx, sc, op, cardW, cardH, topOffset,
              interactive: slot == 0 && !_committing),
        ));
      }

      if (_committing && _idx + 3 < ts.length) {
        final tx = _lerpT(_kTX[2] + 24, _kTX[2], _animPEnter);
        final sc = _lerpT(_kSC[2] - 0.06, _kSC[2], _animPEnter);
        final op = _lerpT(0.0, _kOP[2], _animPEnter);
        layers.add((
          z: 0,
          w: _card(ts[_idx + 3], _idx + 4, tx, sc, op, cardW, cardH, topOffset),
        ));
      }
    } else {
      final p = _committing ? _animP : _dragP;

      if (_idx > 0) {
        final tx = _lerpT(-(cardW * 0.6), _kTX[0], p);
        final sc = _lerpT(_kSC[1], _kSC[0], p);
        final op = _lerpT(0.0, _kOP[0], p);
        layers.add((
          z: 3,
          w: _card(ts[_idx - 1], _idx, tx, sc, op, cardW, cardH, topOffset,
              interactive: _committing && _commitCtrl.value > 0.7),
        ));
      }

      for (int slot = 0; slot < 3; slot++) {
        final ti = _idx + slot;
        if (ti >= ts.length) break;

        double tx, sc, op;
        if (slot == 2) {
          tx = _lerpT(_kTX[2], _kTX[2] + 8, p);
          sc = _lerpT(_kSC[2], _kSC[2] - 0.04, p);
          op = _lerpT(_kOP[2], 0.0, p);
        } else {
          tx = _lerpT(_kTX[slot], _kTX[slot + 1], p);
          sc = _lerpT(_kSC[slot], _kSC[slot + 1], p);
          op = _lerpT(_kOP[slot], _kOP[slot + 1], p);
        }

        layers.add((
          z: 2 - slot,
          w: _card(ts[ti], ti + 1, tx, sc, op, cardW, cardH, topOffset),
        ));
      }
    }

    layers.sort((a, b) => a.z.compareTo(b.z));
    return layers.map((l) => l.w).toList();
  }

  Widget _card(
    TrainerModel t,
    int rank,
    double tx,
    double sc,
    double op,
    double cardW,
    double cardH,
    double topOffset, {
    bool interactive = false,
  }) {
    return Transform.translate(
      offset: Offset(tx, topOffset),
      child: Transform.scale(
        scale: sc,
        alignment: Alignment.topLeft,
        child: Opacity(
          opacity: op.clamp(0.0, 1.0),
          child: IgnorePointer(
            ignoring: !interactive,
            child: SizedBox(
              width: cardW,
              height: cardH,
              child: _TrainerCard(
                key: ValueKey(t.id),
                trainer: t,
                rank: rank,
                onRated: widget.onRated,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

// ── Trainer Card (with 3D flip) ───────────────────────────────────────────────

class _TrainerCard extends StatefulWidget {
  final TrainerModel trainer;
  final int rank;
  final VoidCallback onRated;

  const _TrainerCard({
    super.key,
    required this.trainer,
    required this.rank,
    required this.onRated,
  });

  @override
  State<_TrainerCard> createState() => _TrainerCardState();
}

class _TrainerCardState extends State<_TrainerCard>
    with SingleTickerProviderStateMixin {
  late final AnimationController _flipCtrl;

  // Rating form state (back face)
  double _rating = 0;
  final _commentCtrl = TextEditingController();
  bool _submitting = false;
  bool _submitted = false;

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
    _commentCtrl.dispose();
    super.dispose();
  }

  void _flip() {
    if (_flipCtrl.isAnimating) return;
    HapticFeedback.lightImpact();
    _flipCtrl.isDismissed ? _flipCtrl.forward() : _flipCtrl.reverse();
  }

  Future<void> _submit() async {
    if (_rating == 0 || _submitting) return;
    setState(() => _submitting = true);
    await TrainerRatingService.instance.submitRating(
      trainer: widget.trainer,
      rating: _rating,
      comment: _commentCtrl.text,
    );
    if (!mounted) return;
    setState(() {
      _submitting = false;
      _submitted = true;
    });
    widget.onRated();
    await Future.delayed(const Duration(milliseconds: 1400));
    if (!mounted) return;
    _flip();
    await Future.delayed(const Duration(milliseconds: 520));
    if (!mounted) return;
    setState(() {
      _submitted = false;
      _rating = 0;
      _commentCtrl.clear();
    });
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
    final t = widget.trainer;
    final rank = widget.rank;
    final isTop = rank == 1;

    return GestureDetector(
      onTap: _flip,
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(24),
          border: Border.all(color: const Color(0xFFD4CFC7), width: 1.2),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.13),
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
              // ── Banner ────────────────────────────────────────────────────
              SizedBox(
                height: 112,
                child: Stack(
                  fit: StackFit.expand,
                  clipBehavior: Clip.none,
                  children: [
                    Container(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                          colors: [t.bannerColor1, t.bannerColor2],
                        ),
                      ),
                    ),
                    Positioned.fill(
                      child: Opacity(
                        opacity: 0.06,
                        child: Container(
                          decoration: const BoxDecoration(
                            gradient: RadialGradient(
                              center: Alignment(0.6, -0.4),
                              radius: 1.2,
                              colors: [Colors.white, Colors.transparent],
                            ),
                          ),
                        ),
                      ),
                    ),
                    Positioned(
                      top: 12,
                      left: 14,
                      child: _RankBadge(rank: rank),
                    ),
                    Positioned(
                      top: 8,
                      right: 8,
                      child: GestureDetector(
                        onTap: () =>
                            setState(() => t.isFavorite = !t.isFavorite),
                        child: AnimatedContainer(
                          duration: const Duration(milliseconds: 180),
                          width: 36,
                          height: 36,
                          decoration: BoxDecoration(
                            color: t.isFavorite
                                ? AppColors.primary.withValues(alpha: 0.22)
                                : Colors.white.withValues(alpha: 0.14),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: Icon(
                            t.isFavorite
                                ? Icons.bookmark_rounded
                                : Icons.bookmark_border_rounded,
                            size: 18,
                            color:
                                t.isFavorite ? AppColors.primary : Colors.white70,
                          ),
                        ),
                      ),
                    ),
                    Positioned(
                      bottom: -32,
                      left: 0,
                      right: 0,
                      child: Center(
                        child: Container(
                          width: 68,
                          height: 68,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: Colors.white,
                            border: Border.all(color: Colors.white, width: 3),
                            boxShadow: [
                              BoxShadow(
                                color: Colors.black.withValues(alpha: 0.14),
                                blurRadius: 12,
                                offset: const Offset(0, 4),
                              ),
                            ],
                          ),
                          child: CircleAvatar(
                            backgroundColor: t.bannerColor2,
                            child: Text(
                              t.initials,
                              style: GoogleFonts.lexend(
                                fontSize: 20,
                                fontWeight: FontWeight.w700,
                                color: Colors.white,
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),

              // ── Content ───────────────────────────────────────────────────
              Expanded(
                child: Container(
                  color: Colors.white,
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      const Gap(42),
                      Text(
                        t.name,
                        style: GoogleFonts.lexend(
                          fontSize: 20,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textPrimary,
                        ),
                        textAlign: TextAlign.center,
                      ),
                      const Gap(5),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 4),
                        decoration: BoxDecoration(
                          color: isTop
                              ? AppColors.primary.withValues(alpha: 0.16)
                              : AppColors.surfaceContainerLow,
                          borderRadius: BorderRadius.circular(99),
                        ),
                        child: Text(
                          t.specialty,
                          style: GoogleFonts.inter(
                            fontSize: 11,
                            fontWeight: FontWeight.w600,
                            color: isTop
                                ? const Color(0xFF7A5800)
                                : AppColors.textSecondary,
                          ),
                        ),
                      ),
                      const Gap(16),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            vertical: 12, horizontal: 8),
                        decoration: BoxDecoration(
                          color: AppColors.surfaceContainerLow,
                          borderRadius: BorderRadius.circular(14),
                        ),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.spaceAround,
                          children: [
                            _StatItem(
                                value: '${t.experienceYears} años',
                                label: 'Experiencia'),
                            Container(
                                width: 1, height: 28, color: AppColors.border),
                            _StatItem(
                                value: '${t.studentCount}', label: 'Alumnos'),
                            Container(
                                width: 1, height: 28, color: AppColors.border),
                            _StatItem(
                                value: t.averageRating.toStringAsFixed(1),
                                label: 'Calificación'),
                          ],
                        ),
                      ),
                      const Gap(12),
                      _StarDisplay(rating: t.averageRating),
                      const Gap(4),
                      Text(
                        '${t.ratingCount} calificaciones',
                        style: GoogleFonts.inter(
                            fontSize: 11, color: AppColors.textDisabled),
                      ),
                      const Spacer(),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.touch_app_rounded,
                              size: 13, color: AppColors.textDisabled),
                          const Gap(5),
                          Text(
                            'Toca para calificar',
                            style: GoogleFonts.inter(
                              fontSize: 11,
                              color: AppColors.textDisabled,
                            ),
                          ),
                        ],
                      ),
                      const Gap(16),
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
    final t = widget.trainer;

    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: const Color(0xFFD4CFC7), width: 1.2),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.13),
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
              padding: const EdgeInsets.fromLTRB(18, 16, 12, 14),
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [t.bannerColor1, t.bannerColor2],
                ),
              ),
              child: Row(
                children: [
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        'Calificar a',
                        style: GoogleFonts.inter(
                          fontSize: 11,
                          color: Colors.white60,
                        ),
                      ),
                      Text(
                        t.name,
                        style: GoogleFonts.lexend(
                          fontSize: 16,
                          fontWeight: FontWeight.w700,
                          color: Colors.white,
                        ),
                      ),
                    ],
                  ),
                  const Spacer(),
                  GestureDetector(
                    onTap: _flip,
                    child: Container(
                      width: 34,
                      height: 34,
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: 0.14),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: const Icon(Icons.close_rounded,
                          size: 18, color: Colors.white70),
                    ),
                  ),
                ],
              ),
            ),

            // ── Back form ─────────────────────────────────────────────────
            Expanded(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(16, 20, 16, 16),
                child: _submitted ? _buildSuccess() : _buildForm(),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSuccess() {
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        const Gap(16),
        const Icon(Icons.check_circle_rounded,
            color: AppColors.primary, size: 52),
        const Gap(12),
        Text(
          '¡Calificación enviada!',
          style: GoogleFonts.lexend(
            fontSize: 16,
            fontWeight: FontWeight.w700,
            color: AppColors.textPrimary,
          ),
          textAlign: TextAlign.center,
        ),
        const Gap(6),
        Text(
          'Gracias por tu opinión.',
          style: GoogleFonts.inter(
              fontSize: 13, color: AppColors.textSecondary),
          textAlign: TextAlign.center,
        ),
        const Gap(16),
      ],
    );
  }

  Widget _buildForm() {
    final hintLabel = switch (_rating) {
      0 => 'Toca para calificar',
      5 => 'Excelente',
      >= 4 => 'Muy bueno',
      >= 3 => 'Bueno',
      >= 2 => 'Regular',
      _ => 'Malo',
    };

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Stars
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: List.generate(5, (i) {
            final filled = i < _rating;
            return GestureDetector(
              onTap: () => setState(() => _rating = (i + 1).toDouble()),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 5),
                child: Icon(
                  filled ? Icons.star_rounded : Icons.star_outline_rounded,
                  size: 38,
                  color: filled ? AppColors.primary : AppColors.textDisabled,
                ),
              ),
            );
          }),
        ),
        const Gap(4),
        Text(
          hintLabel,
          style: GoogleFonts.inter(
            fontSize: 12,
            color:
                _rating == 0 ? AppColors.textDisabled : AppColors.textSecondary,
          ),
          textAlign: TextAlign.center,
        ),
        const Gap(16),
        TextField(
          controller: _commentCtrl,
          maxLines: 3,
          maxLength: 200,
          onChanged: (_) => setState(() {}),
          style:
              GoogleFonts.inter(fontSize: 13, color: AppColors.textPrimary),
          decoration: InputDecoration(
            hintText: 'Escribe un comentario...',
            hintStyle:
                GoogleFonts.inter(color: AppColors.textDisabled, fontSize: 13),
            filled: true,
            fillColor: AppColors.surfaceContainerLow,
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(14),
              borderSide: BorderSide.none,
            ),
            contentPadding: const EdgeInsets.all(12),
            counterStyle:
                GoogleFonts.inter(fontSize: 11, color: AppColors.textDisabled),
          ),
        ),
        const Gap(10),
        GestureDetector(
          onTap: _rating > 0 && !_submitting ? _submit : null,
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 200),
            height: 48,
            decoration: BoxDecoration(
              color: _rating > 0 ? AppColors.dark : AppColors.surfaceContainer,
              borderRadius: BorderRadius.circular(14),
              boxShadow: _rating > 0
                  ? [
                      BoxShadow(
                        color: AppColors.dark.withValues(alpha: 0.18),
                        blurRadius: 12,
                        offset: const Offset(0, 4),
                      ),
                    ]
                  : [],
            ),
            alignment: Alignment.center,
            child: _submitting
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(
                      strokeWidth: 2.5,
                      color: AppColors.primary,
                    ),
                  )
                : Text(
                    'Enviar calificación',
                    style: GoogleFonts.lexend(
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: _rating > 0
                          ? AppColors.primary
                          : AppColors.textDisabled,
                    ),
                  ),
          ),
        ),
      ],
    );
  }
}

// ── Rank Badge ────────────────────────────────────────────────────────────────

class _RankBadge extends StatelessWidget {
  final int rank;
  const _RankBadge({required this.rank});

  @override
  Widget build(BuildContext context) {
    final isTop = rank == 1;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(
        color: isTop
            ? AppColors.primary
            : Colors.white.withValues(alpha: 0.18),
        borderRadius: BorderRadius.circular(20),
        border: isTop
            ? null
            : Border.all(color: Colors.white.withValues(alpha: 0.3)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (isTop) ...[
            const Icon(Icons.emoji_events_rounded,
                size: 11, color: AppColors.dark),
            const Gap(4),
            Text(
              'TOP RATED',
              style: GoogleFonts.lexend(
                fontSize: 8.5,
                fontWeight: FontWeight.w800,
                color: AppColors.dark,
                letterSpacing: 1.2,
              ),
            ),
          ] else ...[
            Text(
              '#$rank',
              style: GoogleFonts.lexend(
                fontSize: 12,
                fontWeight: FontWeight.w800,
                color: Colors.white,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

// ── Star Display ──────────────────────────────────────────────────────────────

class _StarDisplay extends StatelessWidget {
  final double rating;
  const _StarDisplay({required this.rating});

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: List.generate(5, (i) {
        final filled = i < rating.floor();
        final half = !filled && (i < rating);
        return Icon(
          filled
              ? Icons.star_rounded
              : half
                  ? Icons.star_half_rounded
                  : Icons.star_outline_rounded,
          size: 22,
          color: AppColors.primary,
        );
      }),
    );
  }
}

// ── Stat Item ─────────────────────────────────────────────────────────────────

class _StatItem extends StatelessWidget {
  final String value;
  final String label;
  const _StatItem({required this.value, required this.label});

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          value,
          style: GoogleFonts.lexend(
            fontSize: 13,
            fontWeight: FontWeight.w700,
            color: AppColors.textPrimary,
          ),
        ),
        const Gap(2),
        Text(
          label,
          style: GoogleFonts.inter(
            fontSize: 10,
            color: AppColors.textSecondary,
          ),
        ),
      ],
    );
  }
}
