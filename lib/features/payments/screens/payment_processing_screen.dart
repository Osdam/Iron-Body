import 'dart:async';

import 'package:flutter/material.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../../core/network/api_client.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../shared/widgets/iron_button.dart';
import '../models/payment_transaction.dart';
import '../services/epayco_payment_service.dart';
import '../widgets/receipt_card.dart';
import 'payment_failed_screen.dart';
import 'payment_pending_screen.dart';
import 'pse_bank_authorization_screen.dart';

/// Procesa el pago 100% DENTRO de la app. Para PSE abre el portal del banco en
/// un WebView interno seguro (sin navegador externo). Diseño premium.
class PaymentProcessingScreen extends StatefulWidget {
  final PaymentRequest request;

  /// Pantalla de éxito (recibe la transacción para el comprobante).
  final Widget Function(PaymentTransaction tx) onApproved;

  /// Efecto al aprobarse (p. ej. limpiar carrito). Se ejecuta una sola vez.
  final VoidCallback? onApprovedSideEffect;

  const PaymentProcessingScreen({
    super.key,
    required this.request,
    required this.onApproved,
    this.onApprovedSideEffect,
  });

  @override
  State<PaymentProcessingScreen> createState() =>
      _PaymentProcessingScreenState();
}

enum _Phase { processing, waiting, error }

class _PaymentProcessingScreenState extends State<PaymentProcessingScreen> {
  _Phase _phase = _Phase.processing;
  String? _errorText;
  PaymentTransaction? _tx;
  Timer? _poll;
  Timer? _msgTimer;
  int _polls = 0;
  int _msgIndex = 0;
  bool _resolved = false;
  bool _busy = false;

  static const _steps = [
    'Conectando con ePayco',
    'Validando información',
    'Confirmando transacción',
    'Activando membresía',
  ];

  String get _methodLabel => widget.request.method.label;

  @override
  void initState() {
    super.initState();
    _msgTimer = Timer.periodic(const Duration(milliseconds: 1600), (_) {
      if (mounted) {
        setState(() => _msgIndex = (_msgIndex + 1) % _steps.length);
      }
    });
    _start();
  }

  @override
  void dispose() {
    _poll?.cancel();
    _msgTimer?.cancel();
    super.dispose();
  }

  Future<void> _start() async {
    if (_busy) return;
    _busy = true;
    setState(() {
      _phase = _Phase.processing;
      _errorText = null;
    });
    try {
      final tx = await EpaycoPaymentService.instance.pay(widget.request);
      if (!mounted) return;
      _tx = tx;
      _route(tx);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _phase = _Phase.error;
        _errorText = e.message;
      });
    } finally {
      _busy = false;
    }
  }

  void _route(PaymentTransaction tx) {
    if (tx.isApproved) {
      _goApproved();
      return;
    }
    if (tx.isFailed) {
      _goFailed(tx.reason);
      return;
    }
    // PSE con portal del banco → autorización DENTRO de la app (WebView).
    if (widget.request.method == PaymentMethod.pse &&
        (tx.authorizationUrl?.isNotEmpty ?? false)) {
      _resolved = true;
      _poll?.cancel();
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(
          builder: (_) => PseBankAuthorizationScreen(
            authorizationUrl: tx.authorizationUrl!,
            reference: tx.reference,
            amount: widget.request.amount,
            onApproved: widget.onApproved,
            onApprovedSideEffect: widget.onApprovedSideEffect,
          ),
        ),
      );
      return;
    }
    // Otro pendiente/processing → consultar estado real (sin navegador).
    setState(() => _phase = _Phase.waiting);
    _poll?.cancel();
    _poll =
        Timer.periodic(const Duration(seconds: 4), (_) => _checkStatus());
  }

  Future<void> _checkStatus() async {
    if (_resolved || _tx == null || _busy) return;
    _busy = true;
    _polls++;
    try {
      final tx =
          await EpaycoPaymentService.instance.getStatus(_tx!.reference);
      if (!mounted) return;
      _tx = tx;
      if (tx.isApproved) {
        _goApproved();
      } else if (tx.isFailed) {
        _goFailed(tx.reason);
      } else if (_polls >= 8) {
        // ~32s sin resolverse → pantalla de pendiente (no dejar cargando).
        _goPending();
      }
    } catch (_) {
      // se reintenta en el siguiente tick
    } finally {
      _busy = false;
    }
  }

  void _goApproved() {
    if (_resolved) return;
    _resolved = true;
    _poll?.cancel();
    widget.onApprovedSideEffect?.call();
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(builder: (_) => widget.onApproved(_tx!)),
    );
  }

  void _goFailed(String? reason) {
    if (_resolved) return;
    _resolved = true;
    _poll?.cancel();
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(
        builder: (_) => PaymentFailedScreen(
          reason: reason,
          reference: _tx?.reference,
          methodLabel: _methodLabel,
          amount: widget.request.amount,
        ),
      ),
    );
  }

  void _goPending() {
    if (_resolved) return;
    _resolved = true;
    _poll?.cancel();
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(
        builder: (_) => PaymentPendingScreen(
          reference: _tx!.reference,
          onApproved: widget.onApproved,
          onApprovedSideEffect: widget.onApprovedSideEffect,
          methodLabel: _methodLabel,
          amount: widget.request.amount,
          providerRef: _tx?.providerRef,
          isPse: widget.request.method == PaymentMethod.pse,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: _phase == _Phase.error,
      child: Scaffold(
        backgroundColor: AppColors.surface0,
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child:
                _phase == _Phase.error ? _buildError() : _buildProcessing(),
          ),
        ),
      ),
    );
  }

  Widget _buildProcessing() {
    return Column(
      children: [
        const Spacer(),
        PremiumProgressRing(
          size: 104,
          child: const Icon(Icons.lock_rounded,
              size: 34, color: AppColors.dark),
        ),
        const Gap(28),
        Text(
          'Procesando pago seguro',
          textAlign: TextAlign.center,
          style: GoogleFonts.lexend(
              fontSize: 22,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary),
        ),
        const Gap(8),
        Text(
          'Estamos verificando la transacción con ePayco',
          textAlign: TextAlign.center,
          style: GoogleFonts.inter(
              fontSize: 13.5, color: AppColors.textSecondary),
        ),
        const Gap(18),
        AnimatedSwitcher(
          duration: const Duration(milliseconds: 350),
          child: Text(
            _phase == _Phase.waiting && widget.request.method == PaymentMethod.pse
                ? 'Esperando autorización del banco…'
                : '${_steps[_msgIndex]}…',
            key: ValueKey(_msgIndex.toString() + _phase.name),
            style: GoogleFonts.lexend(
                fontSize: 14,
                fontWeight: FontWeight.w600,
                color: const Color(0xFFB07A00)),
          ),
        ),
        const Gap(26),
        _infoCard(),
        const Spacer(),
        if (_phase == _Phase.waiting) ...[
          IronButton(
            label: 'VER ESTADO MÁS TARDE',
            isPrimary: false,
            onPressed: _goPending,
          ),
          const Gap(10),
        ],
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.shield_rounded,
                size: 14, color: AppColors.textDisabled),
            const Gap(6),
            Text('Pago protegido por ePayco',
                style: GoogleFonts.inter(
                    fontSize: 12, color: AppColors.textDisabled)),
          ],
        ),
        const Gap(8),
      ],
    );
  }

  Widget _infoCard() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLow,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        children: [
          _kv('Método', _methodLabel),
          const Gap(12),
          _kv('Monto', CurrencyFormatter.format(widget.request.amount)),
          if (_tx?.reference.isNotEmpty ?? false) ...[
            const Gap(12),
            _kv('Referencia', _tx!.reference),
          ],
        ],
      ),
    );
  }

  Widget _kv(String k, String v) => Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(k,
              style: GoogleFonts.inter(
                  fontSize: 12.5, color: AppColors.textSecondary)),
          Flexible(
            child: Text(v,
                textAlign: TextAlign.right,
                overflow: TextOverflow.ellipsis,
                style: GoogleFonts.lexend(
                    fontSize: 13,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary)),
          ),
        ],
      );

  Widget _buildError() {
    return Column(
      children: [
        const Spacer(),
        Container(
          width: 90,
          height: 90,
          decoration: BoxDecoration(
            color: AppColors.error.withValues(alpha: 0.12),
            shape: BoxShape.circle,
          ),
          child: const Icon(Icons.wifi_off_rounded,
              size: 44, color: AppColors.error),
        ),
        const Gap(22),
        Text(
          'No pudimos procesar el pago',
          textAlign: TextAlign.center,
          style: GoogleFonts.lexend(
              fontSize: 22,
              fontWeight: FontWeight.w700,
              color: AppColors.textPrimary),
        ),
        const Gap(10),
        Text(
          _errorText ?? 'Revisa tu conexión e intenta nuevamente.',
          textAlign: TextAlign.center,
          style: GoogleFonts.inter(
              fontSize: 14, color: AppColors.textSecondary),
        ),
        const Gap(8),
        Text(
          'No se realizó ningún cobro duplicado.',
          textAlign: TextAlign.center,
          style: GoogleFonts.inter(
              fontSize: 12, color: AppColors.textDisabled),
        ),
        const Spacer(),
        IronButton(
          label: _busy ? 'INTENTANDO…' : 'INTENTAR NUEVAMENTE',
          onPressed: _start,
        ),
        const Gap(12),
        IronButton(
          label: 'CANCELAR',
          isPrimary: false,
          onPressed: () => Navigator.of(context).pop(),
        ),
        const Gap(16),
      ],
    );
  }
}
