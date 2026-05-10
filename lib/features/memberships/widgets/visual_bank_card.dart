import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../models/payment_form_models.dart';

class VisualBankCard extends StatefulWidget {
  final String cardNumber;
  final String cardHolder;
  final String expiry;
  final String cvv;
  final bool isFlipped;
  final PaymentMethodType type;

  const VisualBankCard({
    super.key,
    required this.cardNumber,
    required this.cardHolder,
    required this.expiry,
    required this.cvv,
    required this.isFlipped,
    required this.type,
  });

  @override
  State<VisualBankCard> createState() => _VisualBankCardState();
}

class _VisualBankCardState extends State<VisualBankCard>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  late final Animation<double> _anim;
  bool _showBack = false;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 500),
    );
    _anim = Tween<double>(begin: 0, end: math.pi).animate(
      CurvedAnimation(parent: _ctrl, curve: Curves.easeInOut),
    );
    _anim.addListener(_onTick);
  }

  void _onTick() {
    final back = _anim.value > (math.pi / 2);
    if (back != _showBack) setState(() => _showBack = back);
  }

  @override
  void didUpdateWidget(VisualBankCard old) {
    super.didUpdateWidget(old);
    if (widget.isFlipped != old.isFlipped) {
      widget.isFlipped ? _ctrl.forward() : _ctrl.reverse();
    }
  }

  @override
  void dispose() {
    _anim.removeListener(_onTick);
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _anim,
      builder: (context, child) {
        final angle = _anim.value;
        return Transform(
          transform: Matrix4.identity()
            ..setEntry(3, 2, 0.001)
            ..rotateY(angle),
          alignment: Alignment.center,
          child: _showBack
              ? Transform(
                  transform: Matrix4.identity()..rotateY(math.pi),
                  alignment: Alignment.center,
                  child: _buildBack(),
                )
              : _buildFront(),
        );
      },
    );
  }

  Widget _buildFront() {
    final isCredit = widget.type == PaymentMethodType.credit;
    final brand = detectCardBrand(widget.cardNumber);
    final num =
        widget.cardNumber.isEmpty ? '####  ####  ####  ####' : widget.cardNumber;
    final holder = widget.cardHolder.isEmpty
        ? 'NOMBRE APELLIDO'
        : widget.cardHolder.toUpperCase();
    final exp = widget.expiry.isEmpty ? 'MM/AA' : widget.expiry;

    return _CardShell(
      isCredit: isCredit,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                isCredit ? 'CRÉDITO' : 'DÉBITO',
                style: GoogleFonts.lexend(
                  fontSize: 9,
                  fontWeight: FontWeight.w700,
                  color: Colors.white54,
                  letterSpacing: 2.5,
                ),
              ),
              _BrandWidget(brand),
            ],
          ),
          const Spacer(),
          _GoldChip(),
          const SizedBox(height: 12),
          Text(
            num,
            style: GoogleFonts.lexend(
              fontSize: 15,
              fontWeight: FontWeight.w600,
              color: Colors.white,
              letterSpacing: 2.5,
            ),
          ),
          const SizedBox(height: 14),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'TITULAR',
                      style: GoogleFonts.lexend(
                          fontSize: 7, color: Colors.white38, letterSpacing: 1.5),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      holder,
                      style: GoogleFonts.lexend(
                          fontSize: 10,
                          fontWeight: FontWeight.w600,
                          color: Colors.white),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 16),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    'EXPIRA',
                    style: GoogleFonts.lexend(
                        fontSize: 7, color: Colors.white38, letterSpacing: 1.5),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    exp,
                    style: GoogleFonts.lexend(
                        fontSize: 10,
                        fontWeight: FontWeight.w600,
                        color: Colors.white),
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildBack() {
    final isCredit = widget.type == PaymentMethodType.credit;
    return _CardShell(
      isCredit: isCredit,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SizedBox(height: 22),
          Container(
            height: 42,
            color: Colors.black87,
            width: double.infinity,
          ),
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: Container(
                  height: 34,
                  decoration: BoxDecoration(
                    color: Colors.white12,
                    borderRadius: BorderRadius.circular(4),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Container(
                width: 60,
                height: 34,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(4),
                ),
                child: Text(
                  widget.cvv.isEmpty ? 'CVV' : widget.cvv,
                  style: GoogleFonts.lexend(
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                    color: Colors.black87,
                    letterSpacing: 3,
                  ),
                ),
              ),
            ],
          ),
          const Spacer(),
          Text(
            'Iron Body — Código de seguridad confidencial',
            style: GoogleFonts.inter(fontSize: 8, color: Colors.white24),
          ),
        ],
      ),
    );
  }
}

class _CardShell extends StatelessWidget {
  final bool isCredit;
  final Widget child;
  const _CardShell({required this.isCredit, required this.child});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      height: 185,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: isCredit
              ? const [Color(0xFF1A1000), Color(0xFF3D2800), Color(0xFF614000)]
              : const [Color(0xFF1A1A1A), Color(0xFF2C2C2C), Color(0xFF3D3D3D)],
        ),
        boxShadow: [
          BoxShadow(
            color: (isCredit ? const Color(0xFF614000) : Colors.black)
                .withValues(alpha: 0.45),
            blurRadius: 24,
            offset: const Offset(0, 12),
          ),
        ],
      ),
      child: child,
    );
  }
}

class _GoldChip extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Container(
      width: 38,
      height: 26,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(4),
        gradient: const LinearGradient(
          colors: [Color(0xFFCCA000), Color(0xFFFFD700), Color(0xFFB8860B)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
    );
  }
}

class _BrandWidget extends StatelessWidget {
  final CardBrand brand;
  const _BrandWidget(this.brand);

  @override
  Widget build(BuildContext context) {
    switch (brand) {
      case CardBrand.visa:
        return Text(
          'VISA',
          style: GoogleFonts.lexend(
            fontSize: 18,
            fontWeight: FontWeight.w900,
            color: Colors.white,
            fontStyle: FontStyle.italic,
            letterSpacing: 1,
          ),
        );
      case CardBrand.mastercard:
        return SizedBox(
          width: 40,
          height: 24,
          child: Stack(
            alignment: Alignment.center,
            children: [
              Positioned(
                left: 0,
                child: Container(
                  width: 24,
                  height: 24,
                  decoration: const BoxDecoration(
                    shape: BoxShape.circle,
                    color: Color(0xFFEB001B),
                  ),
                ),
              ),
              Positioned(
                right: 0,
                child: Opacity(
                  opacity: 0.85,
                  child: Container(
                    width: 24,
                    height: 24,
                    decoration: const BoxDecoration(
                      shape: BoxShape.circle,
                      color: Color(0xFFF79E1B),
                    ),
                  ),
                ),
              ),
            ],
          ),
        );
      case CardBrand.amex:
        return Text(
          'AMEX',
          style: GoogleFonts.lexend(
              fontSize: 11, fontWeight: FontWeight.w900, color: Colors.white),
        );
      case CardBrand.diners:
        return Text(
          'DINERS',
          style: GoogleFonts.lexend(
              fontSize: 10, fontWeight: FontWeight.w700, color: Colors.white70),
        );
      case CardBrand.unknown:
        return const Icon(Icons.credit_card_rounded,
            color: Colors.white38, size: 22);
    }
  }
}
