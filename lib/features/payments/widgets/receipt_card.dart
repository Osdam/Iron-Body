import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../../core/theme/app_colors.dart';

/// Una fila etiqueta/valor del comprobante.
class ReceiptRow {
  final String label;
  final String value;
  const ReceiptRow(this.label, this.value);
}

/// Comprobante premium tipo ticket/boleto (adaptación nativa Flutter de la
/// idea visual "ticket confirmation card"): cortes laterales, separadores
/// punteados, código visual y detalles. Colores Iron Body (blanco/negro/amarillo).
class PaymentReceiptCard extends StatelessWidget {
  final IconData icon;
  final Color iconColor;
  final Color iconBg;
  final String title;
  final String subtitle;
  final List<ReceiptRow> rows;
  final String statusLabel;
  final Color statusColor;
  final String barcodeValue;

  const PaymentReceiptCard({
    super.key,
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.rows,
    required this.statusLabel,
    required this.statusColor,
    required this.barcodeValue,
    this.iconColor = AppColors.dark,
    this.iconBg = AppColors.primary,
  });

  @override
  Widget build(BuildContext context) {
    final bg = AppColors.surface0;
    return Stack(
      clipBehavior: Clip.none,
      children: [
        Container(
          width: double.infinity,
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(28),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.07),
                blurRadius: 28,
                offset: const Offset(0, 12),
              ),
            ],
          ),
          child: Column(
            children: [
              const Gap(28),
              Container(
                width: 76,
                height: 76,
                decoration: BoxDecoration(color: iconBg, shape: BoxShape.circle),
                child: Icon(icon, size: 40, color: iconColor),
              ),
              const Gap(16),
              Text(
                title,
                textAlign: TextAlign.center,
                style: GoogleFonts.lexend(
                    fontSize: 21,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary),
              ),
              const Gap(6),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 28),
                child: Text(
                  subtitle,
                  textAlign: TextAlign.center,
                  style: GoogleFonts.inter(
                      fontSize: 13.5, color: AppColors.textSecondary),
                ),
              ),
              const Gap(22),
              const _Dashed(),
              Padding(
                padding: const EdgeInsets.fromLTRB(24, 18, 24, 4),
                child: Column(
                  children: [
                    for (final r in rows) ...[
                      _row(r.label, r.value),
                      const Gap(14),
                    ],
                    _statusRow(),
                  ],
                ),
              ),
              const Gap(16),
              const _Dashed(),
              const Gap(14),
              _MiniBarcode(value: barcodeValue),
              const Gap(8),
              Text(
                barcodeValue,
                style: GoogleFonts.robotoMono(
                    fontSize: 12,
                    letterSpacing: 4,
                    color: AppColors.textSecondary),
              ),
              const Gap(22),
            ],
          ),
        ),
        // Cortes laterales tipo boleto.
        Positioned(
          left: -14,
          top: 0,
          bottom: 0,
          child: Center(
            child: Container(
              width: 28,
              height: 28,
              decoration: BoxDecoration(color: bg, shape: BoxShape.circle),
            ),
          ),
        ),
        Positioned(
          right: -14,
          top: 0,
          bottom: 0,
          child: Center(
            child: Container(
              width: 28,
              height: 28,
              decoration: BoxDecoration(color: bg, shape: BoxShape.circle),
            ),
          ),
        ),
      ],
    );
  }

  Widget _row(String label, String value) => Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            flex: 4,
            child: Text(
              label.toUpperCase(),
              style: GoogleFonts.inter(
                  fontSize: 10.5,
                  letterSpacing: 0.6,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textDisabled),
            ),
          ),
          const Gap(12),
          Expanded(
            flex: 6,
            child: Text(
              value.isEmpty ? 'No disponible' : value,
              textAlign: TextAlign.right,
              style: GoogleFonts.lexend(
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textPrimary),
            ),
          ),
        ],
      );

  Widget _statusRow() => Row(
        children: [
          Expanded(
            flex: 4,
            child: Text(
              'ESTADO',
              style: GoogleFonts.inter(
                  fontSize: 10.5,
                  letterSpacing: 0.6,
                  fontWeight: FontWeight.w600,
                  color: AppColors.textDisabled),
            ),
          ),
          const Gap(12),
          Expanded(
            flex: 6,
            child: Align(
              alignment: Alignment.centerRight,
              child: Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                decoration: BoxDecoration(
                  color: statusColor.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  statusLabel,
                  style: GoogleFonts.lexend(
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                      color: statusColor),
                ),
              ),
            ),
          ),
        ],
      );
}

class _Dashed extends StatelessWidget {
  const _Dashed();
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 18),
        child: CustomPaint(
          size: const Size(double.infinity, 1),
          painter: _DashedPainter(),
        ),
      );
}

class _DashedPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = AppColors.border
      ..strokeWidth = 1.4;
    const dash = 6.0, gap = 5.0;
    double x = 0;
    while (x < size.width) {
      canvas.drawLine(Offset(x, 0), Offset(x + dash, 0), paint);
      x += dash + gap;
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// Código de barras visual (no funcional) derivado de forma determinista del
/// valor — solo decorativo, no codifica datos sensibles.
class _MiniBarcode extends StatelessWidget {
  final String value;
  const _MiniBarcode({required this.value});

  @override
  Widget build(BuildContext context) {
    var seed = 0;
    for (final c in value.codeUnits) {
      seed = (seed * 31 + c) & 0x7fffffff;
    }
    final bars = List.generate(46, (i) {
      final r = (math.sin(seed + i * 1.0) * 10000);
      final f = r - r.floorToDouble();
      return f > 0.66 ? 3.0 : 1.5;
    });
    return SizedBox(
      height: 52,
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          for (final w in bars) ...[
            Container(
                width: w, height: 52, color: AppColors.textPrimary),
            const SizedBox(width: 2),
          ],
        ],
      ),
    );
  }
}

/// Anillo de progreso premium animado (adaptación nativa de "progress circle").
class PremiumProgressRing extends StatelessWidget {
  final double size;
  final double strokeWidth;
  final Widget child;
  const PremiumProgressRing({
    super.key,
    this.size = 96,
    this.strokeWidth = 5,
    required this.child,
  });

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: Stack(
        alignment: Alignment.center,
        children: [
          SizedBox(
            width: size,
            height: size,
            child: CircularProgressIndicator(
              strokeWidth: strokeWidth,
              valueColor:
                  const AlwaysStoppedAnimation<Color>(AppColors.primary),
              backgroundColor: AppColors.border,
            ),
          ),
          child,
        ],
      ),
    );
  }
}

/// Confeti muy sutil y controlado (pocos elementos, corto, sin saturar).
class SubtleConfetti extends StatefulWidget {
  const SubtleConfetti({super.key});
  @override
  State<SubtleConfetti> createState() => _SubtleConfettiState();
}

class _SubtleConfettiState extends State<SubtleConfetti>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c;
  final _rng = math.Random();
  late final List<_Piece> _pieces;

  @override
  void initState() {
    super.initState();
    _pieces = List.generate(
      22,
      (_) => _Piece(
        x: _rng.nextDouble(),
        delay: _rng.nextDouble() * 0.4,
        speed: 0.6 + _rng.nextDouble() * 0.5,
        rot: _rng.nextDouble() * math.pi,
        color: [
          AppColors.primary,
          AppColors.dark,
          const Color(0xFF1FA463),
        ][_rng.nextInt(3)],
      ),
    );
    _c = AnimationController(
        vsync: this, duration: const Duration(milliseconds: 2600))
      ..forward();
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: AnimatedBuilder(
        animation: _c,
        builder: (context, _) => CustomPaint(
          size: Size.infinite,
          painter: _ConfettiPainter(_pieces, _c.value),
        ),
      ),
    );
  }
}

class _Piece {
  final double x, delay, speed, rot;
  final Color color;
  _Piece(
      {required this.x,
      required this.delay,
      required this.speed,
      required this.rot,
      required this.color});
}

class _ConfettiPainter extends CustomPainter {
  final List<_Piece> pieces;
  final double t;
  _ConfettiPainter(this.pieces, this.t);

  @override
  void paint(Canvas canvas, Size size) {
    for (final p in pieces) {
      final local = ((t - p.delay) / p.speed).clamp(0.0, 1.0);
      if (local <= 0) continue;
      final dy = local * (size.height + 40) - 20;
      final dx = p.x * size.width + math.sin(local * 6 + p.rot) * 14;
      final paint = Paint()..color = p.color.withValues(alpha: 1 - local);
      canvas.save();
      canvas.translate(dx, dy);
      canvas.rotate(p.rot + local * 6);
      canvas.drawRect(
          const Rect.fromLTWH(-3, -6, 6, 12), paint);
      canvas.restore();
    }
  }

  @override
  bool shouldRepaint(covariant _ConfettiPainter old) => old.t != t;
}
