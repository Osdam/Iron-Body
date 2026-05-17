import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/membership_plan_model.dart';
import '../../../data/models/payment_model.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import '../../payments/screens/payment_processing_screen.dart';
import '../../payments/services/epayco_payment_service.dart';
import '../models/payment_form_models.dart';
import '../widgets/credit_debit_card_form.dart';
import '../widgets/payment_method_selector.dart';
import '../widgets/payment_summary_card.dart';
import '../widgets/pse_payment_form.dart';
import '../widgets/wallet_payment_form.dart';
import 'payment_success_screen.dart';

class PaymentCheckoutScreen extends StatefulWidget {
  final MembershipPlanModel plan;
  const PaymentCheckoutScreen({super.key, required this.plan});

  @override
  State<PaymentCheckoutScreen> createState() =>
      _PaymentCheckoutScreenState();
}

class _PaymentCheckoutScreenState extends State<PaymentCheckoutScreen> {
  // ── Payment method state ─────────────────────────────────────────────────
  PaymentMethodType _method = PaymentMethodType.credit;
  PaymentMethodType _cardType = PaymentMethodType.credit;

  // ── Form data ─────────────────────────────────────────────────────────────
  CardFormData _cardData = CardFormData();
  PseFormData _pseData = PseFormData();
  WalletFormData _walletData = WalletFormData();

  // ── Payment state ─────────────────────────────────────────────────────────
  bool _processing = false;
  PaymentStatus? _lastStatus;
  final String _reference = '';
  String _statusMessage = '';

  // ── Coupon ────────────────────────────────────────────────────────────────
  final _couponCtrl = TextEditingController();
  bool _couponApplied = false;
  double _discount = 0;

  late final String _payRef;
  // Cambia tras cada intento para reconstruir los formularios y BORRAR los
  // datos sensibles (PAN/CVV) de la UI.
  int _formEpoch = 0;

  @override
  void initState() {
    super.initState();
    _payRef = 'IRON-${DateTime.now().millisecondsSinceEpoch}';
  }

  @override
  void dispose() {
    _couponCtrl.dispose();
    super.dispose();
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  bool get _isFormValid {
    switch (_method) {
      case PaymentMethodType.credit:
      case PaymentMethodType.debit:
        final digits = _cardData.number.replaceAll(' ', '');
        return digits.length >= 13 &&
            _cardData.holder.trim().length >= 2 &&
            _cardData.expiry.length == 5 &&
            _cardData.cvv.length >= 3;
      case PaymentMethodType.pse:
        return _pseData.bankCode.isNotEmpty &&
            _pseData.docNumber.isNotEmpty &&
            _pseData.email.contains('@');
      case PaymentMethodType.nequi:
      case PaymentMethodType.daviplata:
        return _walletData.phone.length == 10 &&
            _walletData.docNumber.isNotEmpty;
    }
  }

  void _applyCoupon() {
    final code = _couponCtrl.text.trim().toUpperCase();
    if (code == 'IRON15') {
      setState(() {
        _discount = widget.plan.price * 0.15;
        _couponApplied = true;
      });
      _showSnack('¡Cupón IRON15 aplicado! 15% de descuento.', success: true);
    } else if (code.isEmpty) {
      _showSnack('Ingresa un código de descuento.', success: false);
    } else {
      _showSnack('Cupón "$code" no válido.', success: false);
    }
  }

  void _showSnack(String msg, {required bool success}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg, style: GoogleFonts.inter()),
        backgroundColor:
            success ? const Color(0xFF2E7D32) : AppColors.error,
        behavior: SnackBarBehavior.floating,
        shape:
            RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        margin:
            const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      ),
    );
  }

  Future<void> _pay() async {
    if (!_isFormValid || _processing) return;
    FocusScope.of(context).unfocus();

    // Datos obligatorios para ePayco (se completan desde perfil/mock). Si
    // faltan, avisar ANTES de llamar a ePayco.
    final missing = _missingBillingData();
    if (missing != null) {
      _showSnack(missing, success: false);
      return;
    }

    setState(() => _processing = true); // anti doble-tap

    final request = _buildRequest(
      amount: widget.plan.price - _discount,
      description: 'Membresía ${widget.plan.name} · Iron Body',
      // Clave nueva por intento → un reintento no choca con la anterior.
      idempotencyKey: newIdempotencyKey(),
    );

    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => PaymentProcessingScreen(
          request: request,
          onApproved: (tx) => PaymentSuccessScreen(
            plan: widget.plan,
            tx: tx,
            userName: AppSession.currentUser?.fullName,
            methodCode: request.method.name,
          ),
        ),
      ),
    );

    // Limpiar datos sensibles de la UI tras el intento + reactivar el botón.
    if (mounted) {
      setState(() {
        _cardData = CardFormData();
        _processing = false;
        _formEpoch++;
      });
    }
  }

  /// Verifica los datos obligatorios para ePayco (email + documento), que se
  /// completan desde el perfil/mock. Devuelve un mensaje si falta algo.
  String? _missingBillingData() {
    final user = AppSession.currentUser;
    final email = (user?.email ?? _pseData.email).trim();
    final doc = (user?.document ??
            (_method == PaymentMethodType.pse
                ? _pseData.docNumber
                : _walletData.docNumber))
        .trim();
    if (email.isEmpty || !email.contains('@')) {
      return 'Falta el correo de facturación. Complétalo en tu perfil.';
    }
    if (doc.isEmpty) {
      return 'Falta el número de documento. Complétalo en tu perfil.';
    }
    return null;
  }

  PaymentRequest _buildRequest({
    required double amount,
    required String description,
    required String idempotencyKey,
  }) {
    final user = AppSession.currentUser;
    final method = switch (_method) {
      PaymentMethodType.credit || PaymentMethodType.debit =>
        PaymentMethod.card,
      PaymentMethodType.pse => PaymentMethod.pse,
      PaymentMethodType.nequi => PaymentMethod.nequi,
      PaymentMethodType.daviplata => PaymentMethod.daviplata,
    };
    final exp = _cardData.expiry.split('/'); // "MM/YY"
    return PaymentRequest(
      method: method,
      amount: amount,
      description: description,
      // Estable por intento: el backend lo usa para no duplicar el pago.
      idempotencyKey: idempotencyKey,
      // plan_id/user_id quedan nulos: el usuario de la app es mock y los ids
      // de plan son locales. El backend acepta ambos como opcionales.
      customerName: user?.fullName,
      customerEmail: user?.email ?? _pseData.email,
      customerPhone: user?.phone,
      customerDoc: user?.document ??
          (method == PaymentMethod.pse
              ? _pseData.docNumber
              : _walletData.docNumber),
      customerDocType: method == PaymentMethod.pse
          ? _pseData.docType
          : 'CC',
      // Completados desde perfil/mock; no hay backend de direcciones aún.
      customerCity: 'Neiva',
      customerAddress: 'Neiva, Huila',
      customerCountry: 'CO',
      cardNumber: method == PaymentMethod.card ? _cardData.number : null,
      cardExpMonth:
          method == PaymentMethod.card && exp.isNotEmpty ? exp[0] : null,
      cardExpYear: method == PaymentMethod.card && exp.length > 1
          ? '20${exp[1]}'
          : null,
      cardCvc: method == PaymentMethod.card ? _cardData.cvv : null,
      dues: _cardData.dues,
      walletPhone: (method == PaymentMethod.nequi ||
              method == PaymentMethod.daviplata)
          ? _walletData.phone
          : null,
      pseBank: method == PaymentMethod.pse ? _pseData.bankCode : null,
      psePersonType: method == PaymentMethod.pse
          ? (_pseData.personType == PersonType.juridica
              ? 'juridica'
              : 'natural')
          : null,
    );
  }

  // ── Build ─────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final user = AppSession.currentUser;

    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: const IronAppBar(title: 'Checkout'),
      body: CustomScrollView(
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        slivers: [
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
            sliver: SliverList(
              delegate: SliverChildListDelegate([
                // ── Summary ──────────────────────────────────────────────
                PaymentSummaryCard(
                  plan: widget.plan,
                  userName: user?.fullName,
                  reference: _payRef,
                  selectedMethod: _method.fullLabel,
                  discount: _discount,
                ).animate().fadeIn(duration: 400.ms).slideY(begin: 0.12),

                const Gap(24),

                // ── Method selector ──────────────────────────────────────
                Text(
                  'Método de pago',
                  style: GoogleFonts.lexend(
                    fontSize: 16,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ).animate().fadeIn(delay: 80.ms),
                const Gap(12),
                PaymentMethodSelector(
                  selected: _method,
                  onChanged: (m) => setState(() {
                    _method = m;
                    if (m == PaymentMethodType.credit ||
                        m == PaymentMethodType.debit) {
                      _cardType = m;
                    }
                    _lastStatus = null;
                    _statusMessage = '';
                  }),
                ).animate().fadeIn(delay: 120.ms),

                const Gap(20),

                // ── Form (animated switcher) ─────────────────────────────
                AnimatedSwitcher(
                  duration: const Duration(milliseconds: 280),
                  transitionBuilder: (child, anim) => FadeTransition(
                    opacity: anim,
                    child: SlideTransition(
                      position: Tween<Offset>(
                        begin: const Offset(0, 0.06),
                        end: Offset.zero,
                      ).animate(anim),
                      child: child,
                    ),
                  ),
                  child: _buildForm(),
                ),

                const Gap(20),

                // ── Coupon ────────────────────────────────────────────────
                _buildCouponSection()
                    .animate()
                    .fadeIn(delay: 200.ms),

                const Gap(20),

                // ── Status banner (rejected / pending) ────────────────────
                if (_lastStatus == PaymentStatus.rejected ||
                    _lastStatus == PaymentStatus.pending)
                  _buildStatusBanner()
                      .animate()
                      .fadeIn()
                      .slideY(begin: 0.08),
                if (_lastStatus == PaymentStatus.rejected ||
                    _lastStatus == PaymentStatus.pending)
                  const Gap(16),

                // ── Pay button ────────────────────────────────────────────
                _buildPayButton()
                    .animate()
                    .fadeIn(delay: 260.ms),

                const Gap(12),
                _buildSecurityBadge()
                    .animate()
                    .fadeIn(delay: 300.ms),
              ]),
            ),
          ),
        ],
      ),
    );
  }

  // ── Form section ───────────────────────────────────────────────────────────

  Widget _buildForm() {
    switch (_method) {
      case PaymentMethodType.credit:
      case PaymentMethodType.debit:
        return CreditDebitCardForm(
          key: ValueKey('card-$_formEpoch'),
          type: _cardType,
          onTypeChanged: (t) => setState(() {
            _cardType = t;
            _method = t;
          }),
          onChanged: (d) => setState(() => _cardData = d),
        );
      case PaymentMethodType.pse:
        return PsePaymentForm(
          key: const ValueKey('pse'),
          onChanged: (d) => setState(() => _pseData = d),
        );
      case PaymentMethodType.nequi:
        return WalletPaymentForm(
          key: const ValueKey('nequi'),
          type: PaymentMethodType.nequi,
          onChanged: (d) => setState(() => _walletData = d),
        );
      case PaymentMethodType.daviplata:
        return WalletPaymentForm(
          key: const ValueKey('daviplata'),
          type: PaymentMethodType.daviplata,
          onChanged: (d) => setState(() => _walletData = d),
        );
    }
  }

  // ── Coupon ─────────────────────────────────────────────────────────────────

  Widget _buildCouponSection() {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLow,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'Código de descuento',
            style: GoogleFonts.lexend(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary,
            ),
          ),
          const Gap(10),
          Row(
            children: [
              Expanded(
                child: TextFormField(
                  controller: _couponCtrl,
                  enabled: !_couponApplied,
                  textCapitalization: TextCapitalization.characters,
                  style: GoogleFonts.inter(
                      fontSize: 14, color: AppColors.textPrimary),
                  decoration: InputDecoration(
                    hintText: 'Ej: IRON15',
                    hintStyle: GoogleFonts.inter(
                        fontSize: 14, color: AppColors.textDisabled),
                    prefixIcon: Icon(
                      _couponApplied
                          ? Icons.check_circle_rounded
                          : Icons.discount_outlined,
                      size: 18,
                      color: _couponApplied
                          ? const Color(0xFF2E7D32)
                          : AppColors.textSecondary,
                    ),
                    contentPadding: const EdgeInsets.symmetric(
                        horizontal: 14, vertical: 12),
                    filled: true,
                    fillColor: _couponApplied
                        ? const Color(0xFFE8F5E9)
                        : AppColors.surface0,
                    enabledBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10),
                      borderSide: BorderSide(
                        color: _couponApplied
                            ? const Color(0xFF2E7D32)
                            : AppColors.border,
                      ),
                    ),
                    disabledBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10),
                      borderSide:
                          const BorderSide(color: Color(0xFF2E7D32)),
                    ),
                    focusedBorder: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10),
                      borderSide: const BorderSide(
                          color: AppColors.primary, width: 1.5),
                    ),
                  ),
                ),
              ),
              const Gap(10),
              GestureDetector(
                onTap: _couponApplied ? null : _applyCoupon,
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 220),
                  padding: const EdgeInsets.symmetric(
                      horizontal: 16, vertical: 12),
                  decoration: BoxDecoration(
                    color: _couponApplied
                        ? const Color(0xFF2E7D32)
                        : AppColors.dark,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Text(
                    _couponApplied ? 'Aplicado ✓' : 'Aplicar',
                    style: GoogleFonts.lexend(
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: Colors.white,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  // ── Status banner ──────────────────────────────────────────────────────────

  Widget _buildStatusBanner() {
    final isRejected = _lastStatus == PaymentStatus.rejected;
    final color = isRejected ? AppColors.error : const Color(0xFFE65100);
    final bgColor =
        isRejected ? const Color(0xFFFFF0F0) : const Color(0xFFFFF3E0);

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: color.withValues(alpha: 0.3)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            isRejected
                ? Icons.error_outline_rounded
                : Icons.access_time_rounded,
            color: color,
            size: 20,
          ),
          const Gap(10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  isRejected ? 'Pago rechazado' : 'Pago pendiente',
                  style: GoogleFonts.lexend(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: color,
                  ),
                ),
                const Gap(4),
                Text(
                  _statusMessage,
                  style: GoogleFonts.inter(
                      fontSize: 12, color: AppColors.textSecondary),
                ),
                if (_reference.isNotEmpty) ...[
                  const Gap(4),
                  Text(
                    'Ref: $_reference',
                    style: GoogleFonts.inter(
                        fontSize: 11, color: AppColors.textDisabled),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ── Pay button ─────────────────────────────────────────────────────────────

  Widget _buildPayButton() {
    if (_processing) {
      return Container(
        height: 58,
        decoration: BoxDecoration(
          color: AppColors.dark,
          borderRadius: BorderRadius.circular(16),
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(
                  color: AppColors.primary, strokeWidth: 2.5),
            ),
            const Gap(12),
            Text(
              'Procesando pago...',
              style: GoogleFonts.lexend(
                fontSize: 14,
                fontWeight: FontWeight.w700,
                color: Colors.white,
              ),
            ),
          ],
        ),
      );
    }

    final label = _lastStatus == PaymentStatus.rejected
        ? 'INTENTAR DE NUEVO'
        : 'PAGAR AHORA';

    final isEnabled = _isFormValid;

    return GestureDetector(
      onTap: isEnabled ? _pay : null,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        height: 58,
        decoration: BoxDecoration(
          color: isEnabled ? AppColors.primary : AppColors.surfaceContainer,
          borderRadius: BorderRadius.circular(16),
          boxShadow: isEnabled
              ? [
                  BoxShadow(
                    color: AppColors.primary.withValues(alpha: 0.32),
                    blurRadius: 18,
                    offset: const Offset(0, 6),
                  ),
                ]
              : [],
        ),
        alignment: Alignment.center,
        child: Text(
          label,
          style: GoogleFonts.lexend(
            fontSize: 14,
            fontWeight: FontWeight.w700,
            letterSpacing: 0.7,
            color: isEnabled ? AppColors.dark : AppColors.textDisabled,
          ),
        ),
      ),
    );
  }

  // ── Security badge ─────────────────────────────────────────────────────────

  Widget _buildSecurityBadge() {
    return Center(
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.lock_rounded, size: 13, color: AppColors.textDisabled),
          const Gap(5),
          Text(
            'Pago seguro procesado por ePayco',
            style: GoogleFonts.inter(fontSize: 12, color: AppColors.textDisabled),
          ),
        ],
      ),
    );
  }
}
