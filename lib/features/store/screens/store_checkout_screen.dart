import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../data/mock/mock_data.dart';
import '../../../data/models/payment_model.dart';
import '../../../data/models/product_model.dart';
import '../../../shared/widgets/iron_app_bar.dart';
import '../../payments/screens/payment_approved_screen.dart';
import '../../payments/screens/payment_processing_screen.dart';
import '../../payments/services/epayco_payment_service.dart';
import '../../memberships/models/payment_form_models.dart';
import '../../memberships/widgets/credit_debit_card_form.dart';
import '../../memberships/widgets/payment_method_selector.dart';
import '../../memberships/widgets/pse_payment_form.dart';
import '../../memberships/widgets/wallet_payment_form.dart';

class StoreCheckoutScreen extends StatefulWidget {
  final List<CartItem> cart;
  final double total;
  const StoreCheckoutScreen({super.key, required this.cart, required this.total});

  @override
  State<StoreCheckoutScreen> createState() => _StoreCheckoutScreenState();
}

class _StoreCheckoutScreenState extends State<StoreCheckoutScreen> {
  PaymentMethodType _method = PaymentMethodType.credit;
  PaymentMethodType _cardType = PaymentMethodType.credit;

  CardFormData _cardData = CardFormData();
  PseFormData _pseData = PseFormData();
  WalletFormData _walletData = WalletFormData();

  bool _processing = false;
  PaymentStatus? _lastStatus;
  final String _reference = '';
  String _statusMessage = '';

  late final String _payRef;
  // Cambia tras cada intento para reconstruir el formulario y BORRAR los datos
  // sensibles (PAN/CVV) de la UI.
  int _formEpoch = 0;

  @override
  void initState() {
    super.initState();
    _payRef = 'IRON-${DateTime.now().millisecondsSinceEpoch}';
  }

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

  Future<void> _pay() async {
    if (!_isFormValid || _processing) return;
    FocusScope.of(context).unfocus();

    final missing = _missingBillingData();
    if (missing != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(missing, style: GoogleFonts.inter()),
          backgroundColor: AppColors.error,
          behavior: SnackBarBehavior.floating,
        ),
      );
      return;
    }

    setState(() => _processing = true); // anti doble-tap

    final cart = widget.cart;
    final total = widget.total;
    final itemsDesc = cart.isEmpty
        ? 'Pedido Iron Body'
        : 'Pedido Iron Body (${cart.length} ítem'
            '${cart.length == 1 ? '' : 's'})';

    final request = _buildRequest(
      amount: total,
      description: itemsDesc,
      // Clave nueva por intento → un reintento no choca con la anterior.
      idempotencyKey: newIdempotencyKey(),
    );

    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => PaymentProcessingScreen(
          request: request,
          onApprovedSideEffect: cart.clear,
          onApproved: (tx) => PaymentApprovedScreen(
            tx: tx,
            title: '¡Compra exitosa!',
            subtitle: 'Tu pedido será entregado en caja.',
            methodCode: request.method.name,
            userName: AppSession.currentUser?.fullName,
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

  /// Datos obligatorios para ePayco (email + documento), completados desde el
  /// perfil/mock. Devuelve un mensaje si falta algo.
  String? _missingBillingData() {
    final user = AppSession.currentUser;
    final email = (user?.email ?? _pseData.email).trim();
    final doc = (user?.document ??
            (_method == PaymentMethodType.pse
                ? _pseData.docNumber
                : _walletData.docNumber))
        .trim();
    if (email.isEmpty || !email.contains('@')) {
      return 'Falta el correo de facturación. Inicia sesión o complétalo.';
    }
    if (doc.isEmpty) {
      return 'Falta el número de documento para procesar el pago.';
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
      idempotencyKey: idempotencyKey,
      // Completados desde perfil/mock (no hay backend de clientes aún).
      customerName: (method == PaymentMethod.card &&
              _cardData.holder.isNotEmpty)
          ? _cardData.holder
          : user?.fullName,
      customerEmail: user?.email ??
          (method == PaymentMethod.pse ? _pseData.email : null),
      customerPhone: user?.phone ??
          (method == PaymentMethod.nequi ||
                  method == PaymentMethod.daviplata
              ? _walletData.phone
              : null),
      customerDoc: user?.document ??
          (method == PaymentMethod.pse
              ? _pseData.docNumber
              : _walletData.docNumber),
      customerDocType: method == PaymentMethod.pse ? _pseData.docType : 'CC',
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

  @override
  Widget build(BuildContext context) {
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
                // ── Cart summary ─────────────────────────────────────────
                _buildCartSummary()
                    .animate()
                    .fadeIn(duration: 400.ms)
                    .slideY(begin: 0.12),

                const Gap(24),

                // ── Payment method ───────────────────────────────────────
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

                // ── Payment form ─────────────────────────────────────────
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

                // ── Status banner ────────────────────────────────────────
                if (_lastStatus == PaymentStatus.rejected ||
                    _lastStatus == PaymentStatus.pending)
                  _buildStatusBanner().animate().fadeIn().slideY(begin: 0.08),
                if (_lastStatus == PaymentStatus.rejected ||
                    _lastStatus == PaymentStatus.pending)
                  const Gap(16),

                // ── Pay button ───────────────────────────────────────────
                _buildPayButton().animate().fadeIn(delay: 260.ms),

                const Gap(12),
                _buildSecurityBadge().animate().fadeIn(delay: 300.ms),
              ]),
            ),
          ),
        ],
      ),
    );
  }

  // ── Cart summary card ───────────────────────────────────────────────────────

  Widget _buildCartSummary() {
    return Container(
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFF121212), Color(0xFF1E1E2A)],
        ),
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.22),
            blurRadius: 22,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(18, 18, 18, 14),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  'RESUMEN DEL PEDIDO',
                  style: GoogleFonts.lexend(
                    fontSize: 9,
                    color: Colors.white38,
                    letterSpacing: 2,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                Text(
                  'Ref: ${_payRef.substring(_payRef.length - 8)}',
                  style: GoogleFonts.inter(fontSize: 10, color: Colors.white24),
                ),
              ],
            ),
          ),
          Container(height: 1, color: Colors.white.withValues(alpha: 0.08)),
          Padding(
            padding: const EdgeInsets.all(18),
            child: Column(
              children: [
                ...widget.cart.map((item) => Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Expanded(
                            child: Text(
                              '${item.product.name}  ×${item.quantity}',
                              style: GoogleFonts.inter(
                                  fontSize: 13, color: Colors.white70),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                          Text(
                            CurrencyFormatter.format(item.subtotal),
                            style: GoogleFonts.lexend(
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                              color: Colors.white70,
                            ),
                          ),
                        ],
                      ),
                    )),
                Container(
                  height: 1,
                  margin: const EdgeInsets.symmetric(vertical: 8),
                  color: Colors.white.withValues(alpha: 0.08),
                ),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      'Total a pagar',
                      style: GoogleFonts.lexend(
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                        color: Colors.white,
                      ),
                    ),
                    Text(
                      CurrencyFormatter.format(widget.total),
                      style: GoogleFonts.lexend(
                        fontSize: 22,
                        fontWeight: FontWeight.w700,
                        color: AppColors.primary,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // ── Payment form ────────────────────────────────────────────────────────────

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

  // ── Status banner ────────────────────────────────────────────────────────────

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

  // ── Pay button ───────────────────────────────────────────────────────────────

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
        : 'PAGAR ${CurrencyFormatter.format(widget.total)}';

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

  // ── Security badge ───────────────────────────────────────────────────────────

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
