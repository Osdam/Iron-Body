import 'dart:math' as math;
import 'package:flutter/material.dart';
import '../../../core/theme/app_colors.dart';

// ─────────────────────────────────────────────────────────────────────────────
// Fondo decorativo animado con elementos de gimnasio en capas múltiples.
// Opacidad 4-8%, movimiento flotante independiente por elemento.
// ─────────────────────────────────────────────────────────────────────────────

class AnimatedGymBackground extends StatefulWidget {
  final Widget child;
  const AnimatedGymBackground({super.key, required this.child});

  @override
  State<AnimatedGymBackground> createState() => _AnimatedGymBackgroundState();
}

class _AnimatedGymBackgroundState extends State<AnimatedGymBackground>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 20),
    )..repeat();
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        // Capa de fondo blanco puro
        Container(color: AppColors.surface0),

        // Capa decorativa animada
        Positioned.fill(
          child: AnimatedBuilder(
            animation: _ctrl,
            builder: (context, child) => CustomPaint(
              painter: _GymBackgroundPainter(_ctrl.value),
            ),
          ),
        ),

        // Contenido encima
        widget.child,
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Painter con 12 elementos distribuidos estratégicamente
// ─────────────────────────────────────────────────────────────────────────────

class _GymBackgroundPainter extends CustomPainter {
  final double t; // 0..1 looping
  _GymBackgroundPainter(this.t);

  // Configura cada elemento: [normX, normY, size, phase, opacity, type]
  // type: 0=dumbbell, 1=kettlebell, 2=disc, 3=hexPlate
  static const _elements = [
    // Top-left region
    [0.02, 0.04, 72.0, 0.0,  0.055, 0.0],
    [0.85, 0.02, 42.0, 0.4,  0.040, 2.0],
    // Top-right region
    [0.90, 0.12, 60.0, 0.8,  0.050, 1.0],
    [0.75, 0.08, 32.0, 0.2,  0.035, 3.0],
    // Mid-left
    [-0.05, 0.32, 56.0, 1.1,  0.045, 0.0],
    [0.02,  0.55, 38.0, 0.6,  0.038, 2.0],
    // Mid-right
    [0.88, 0.38, 48.0, 1.5,  0.042, 1.0],
    [0.92, 0.60, 36.0, 0.9,  0.036, 3.0],
    // Bottom-left
    [-0.04, 0.78, 64.0, 1.7, 0.048, 0.0],
    [0.08,  0.92, 30.0, 0.3,  0.033, 1.0],
    // Bottom-right
    [0.82, 0.82, 54.0, 1.2,  0.046, 2.0],
    [0.70, 0.96, 28.0, 2.0,  0.030, 3.0],
  ];

  @override
  void paint(Canvas canvas, Size size) {
    for (final el in _elements) {
      final normX   = el[0];
      final normY   = el[1];
      final elSize  = el[2];
      final phase   = el[3];
      final opacity = el[4];
      final type    = el[5];

      // Movimiento flotante independiente
      final angle  = t * 2 * math.pi + phase;
      final floatX = math.sin(angle * 0.7) * 5.0;
      final floatY = math.sin(angle + phase) * 7.0;
      final rot    = math.sin(angle * 0.3 + phase) * 0.06;

      final cx = size.width  * normX + floatX;
      final cy = size.height * normY + floatY;

      final fill = Paint()
        ..color = AppColors.gymDecoration.withValues(alpha: opacity)
        ..style = PaintingStyle.fill;
      final stroke = Paint()
        ..color = AppColors.gymDecoration.withValues(alpha: opacity + 0.01)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.8
        ..strokeCap = StrokeCap.round;

      canvas.save();
      canvas.translate(cx, cy);
      canvas.rotate(rot);

      switch (type.round()) {
        case 0:
          _drawDumbbell(canvas, fill, stroke, elSize);
        case 1:
          _drawKettlebell(canvas, fill, stroke, elSize);
        case 2:
          _drawDisc(canvas, stroke, elSize);
        case 3:
          _drawHexPlate(canvas, fill, stroke, elSize);
      }

      canvas.restore();
    }

    // Líneas de energía geométricas — capa adicional
    _drawEnergyLines(canvas, size);
  }

  void _drawDumbbell(Canvas c, Paint fill, Paint stroke, double s) {
    final barLen   = s * 0.54;
    final barThick = s * 0.11;
    final plateW   = s * 0.19;
    final plateH   = s * 0.38;

    // Barra central
    c.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromCenter(center: Offset.zero, width: barLen * 2, height: barThick),
        const Radius.circular(4),
      ),
      fill,
    );
    // Platos
    for (final sx in [-1.0, 1.0]) {
      c.drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromCenter(
            center: Offset(sx * (barLen - plateW * 0.32), 0),
            width: plateW, height: plateH,
          ),
          const Radius.circular(5),
        ),
        fill,
      );
    }
  }

  void _drawKettlebell(Canvas c, Paint fill, Paint stroke, double r) {
    // Cuerpo
    c.drawCircle(Offset(0, r * 0.28), r, fill);
    // Asa
    final path = Path()
      ..moveTo(-r * 0.52, r * 0.28)
      ..cubicTo(-r * 0.72, -r * 0.75, r * 0.72, -r * 0.75, r * 0.52, r * 0.28);
    c.drawPath(path, stroke);
  }

  void _drawDisc(Canvas c, Paint stroke, double r) {
    c.drawCircle(Offset.zero, r, stroke);
    c.drawCircle(Offset.zero, r * 0.6, stroke);
    c.drawCircle(Offset.zero, r * 0.18, stroke);
    // Agujeros
    for (int i = 0; i < 4; i++) {
      final a = i * math.pi / 2;
      c.drawCircle(Offset(math.cos(a) * r * 0.38, math.sin(a) * r * 0.38), r * 0.08, stroke);
    }
  }

  void _drawHexPlate(Canvas c, Paint fill, Paint stroke, double s) {
    // Hexágono (plato hexagonal de gym)
    final path = Path();
    for (int i = 0; i < 6; i++) {
      final a = i * math.pi / 3 - math.pi / 6;
      final x = math.cos(a) * s;
      final y = math.sin(a) * s;
      if (i == 0) path.moveTo(x, y);
      else path.lineTo(x, y);
    }
    path.close();
    c.drawPath(path, stroke);
    // Interior
    final inner = Path();
    for (int i = 0; i < 6; i++) {
      final a = i * math.pi / 3 - math.pi / 6;
      final x = math.cos(a) * s * 0.55;
      final y = math.sin(a) * s * 0.55;
      if (i == 0) inner.moveTo(x, y);
      else inner.lineTo(x, y);
    }
    inner.close();
    c.drawPath(inner, stroke);
    c.drawCircle(Offset.zero, s * 0.15, fill);
  }

  void _drawEnergyLines(Canvas canvas, Size size) {
    final linePaint = Paint()
      ..color = AppColors.gymDecoration.withValues(alpha: 0.025)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.2;

    // Líneas diagonales sutiles — izquierda
    for (int i = 0; i < 5; i++) {
      final y = size.height * (0.1 + i * 0.18);
      final offset = math.sin(t * 2 * math.pi + i * 0.6) * 6;
      canvas.drawLine(
        Offset(-10, y + offset),
        Offset(size.width * 0.18, y + 22 + offset),
        linePaint,
      );
    }
    // Líneas diagonales — derecha
    for (int i = 0; i < 4; i++) {
      final y = size.height * (0.15 + i * 0.2);
      final offset = math.sin(t * 2 * math.pi + i * 0.8 + 1) * 5;
      canvas.drawLine(
        Offset(size.width * 0.82, y + offset),
        Offset(size.width + 10, y - 18 + offset),
        linePaint,
      );
    }
  }

  @override
  bool shouldRepaint(_GymBackgroundPainter old) => old.t != t;
}
