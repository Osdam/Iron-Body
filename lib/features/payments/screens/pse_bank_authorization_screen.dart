import 'package:flutter/material.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:webview_flutter/webview_flutter.dart';

import '../../../core/theme/app_colors.dart';
import '../../../shared/widgets/iron_button.dart';
import '../models/payment_transaction.dart';
import '../services/epayco_payment_service.dart';
import '../widgets/receipt_card.dart';
import 'payment_failed_screen.dart';
import 'payment_pending_screen.dart';

/// Autorización bancaria PSE DENTRO de la app (WebView interno seguro).
///
/// Seguridad: NO se inyecta JavaScript propio, NO hay JavaScriptChannel, NO se
/// leen formularios ni credenciales del banco; solo se muestra el portal
/// seguro requerido y se detecta el retorno de ePayco para consultar estado.
/// NO se abre Chrome, navegador externo ni Custom Tabs.
class PseBankAuthorizationScreen extends StatefulWidget {
  final String authorizationUrl;
  final String reference;
  final double amount;
  final Widget Function(PaymentTransaction tx) onApproved;
  final VoidCallback? onApprovedSideEffect;

  const PseBankAuthorizationScreen({
    super.key,
    required this.authorizationUrl,
    required this.reference,
    required this.amount,
    required this.onApproved,
    this.onApprovedSideEffect,
  });

  @override
  State<PseBankAuthorizationScreen> createState() =>
      _PseBankAuthorizationScreenState();
}

class _PseBankAuthorizationScreenState
    extends State<PseBankAuthorizationScreen> {
  late final WebViewController _controller;
  bool _loading = true;
  bool _error = false;
  bool _finalizing = false;

  // Señales de que el flujo PSE volvió de ePayco/banco.
  static const _returnSignals = [
    'payments/epayco/response',
    'transaction/response',
    'ref_payco=',
    'epayco.co/restpagos/valida',
  ];

  @override
  void initState() {
    super.initState();
    _controller = WebViewController()
      ..setJavaScriptMode(JavaScriptMode.unrestricted) // requerido por el banco
      ..setNavigationDelegate(
        NavigationDelegate(
          onPageStarted: (_) {
            if (mounted) setState(() => _loading = true);
          },
          onPageFinished: (url) {
            if (mounted) setState(() => _loading = false);
            if (_isReturn(url)) _finalize();
          },
          onWebResourceError: (e) {
            if (e.isForMainFrame ?? true) {
              if (mounted) {
                setState(() {
                  _loading = false;
                  _error = true;
                });
              }
            }
          },
          onNavigationRequest: (req) {
            if (_isReturn(req.url)) {
              _finalize();
              return NavigationDecision.prevent;
            }
            return NavigationDecision.navigate;
          },
        ),
      )
      ..loadRequest(Uri.parse(widget.authorizationUrl));
  }

  bool _isReturn(String url) {
    final u = url.toLowerCase();
    return _returnSignals.any(u.contains);
  }

  /// Detectado el retorno: consultar estado real y enrutar (sin navegador).
  Future<void> _finalize() async {
    if (_finalizing) return;
    _finalizing = true;
    if (mounted) setState(() => _loading = true);
    PaymentTransaction? tx;
    try {
      tx = await EpaycoPaymentService.instance.getStatus(widget.reference);
    } catch (_) {/* sin estado → tratar como pendiente */}
    if (!mounted) return;

    if (tx != null && tx.isApproved) {
      widget.onApprovedSideEffect?.call();
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => widget.onApproved(tx!)),
      );
    } else if (tx != null && tx.isFailed) {
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(
          builder: (_) => PaymentFailedScreen(
            reason: tx!.reason,
            reference: tx.reference,
            methodLabel: 'PSE',
            amount: widget.amount,
          ),
        ),
      );
    } else {
      _toPending();
    }
  }

  void _toPending() {
    Navigator.pushReplacement(
      context,
      MaterialPageRoute(
        builder: (_) => PaymentPendingScreen(
          reference: widget.reference,
          methodLabel: 'PSE',
          amount: widget.amount,
          isPse: true,
          onApproved: widget.onApproved,
          onApprovedSideEffect: widget.onApprovedSideEffect,
        ),
      ),
    );
  }

  Future<bool> _confirmExit() async {
    final leave = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape:
            RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
        title: Text('¿Salir de la autorización?',
            style: GoogleFonts.lexend(
                fontWeight: FontWeight.w700, fontSize: 17)),
        content: Text(
          'Tu pago quedará pendiente. Podrás consultar su estado más tarde.',
          style: GoogleFonts.inter(fontSize: 14),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text('Seguir aquí',
                style: GoogleFonts.lexend(color: AppColors.textSecondary)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text('Salir',
                style: GoogleFonts.lexend(
                    color: AppColors.error, fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
    if (leave == true && mounted) {
      _toPending();
    }
    return false; // la navegación la maneja _toPending
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _confirmExit();
      },
      child: Scaffold(
        backgroundColor: AppColors.surface0,
        body: SafeArea(
          child: Column(
            children: [
              _header(),
              Expanded(
                child: Stack(
                  children: [
                    if (!_error) WebViewWidget(controller: _controller),
                    if (_loading && !_error) _loadingOverlay(),
                    if (_error) _errorView(),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _header() {
    return Container(
      color: AppColors.dark,
      padding: const EdgeInsets.fromLTRB(16, 14, 8, 14),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text('IRON ',
                        style: GoogleFonts.lexend(
                            color: Colors.white,
                            fontWeight: FontWeight.w800,
                            fontSize: 13,
                            letterSpacing: 2)),
                    Text('BODY',
                        style: GoogleFonts.lexend(
                            color: AppColors.primary,
                            fontWeight: FontWeight.w800,
                            fontSize: 13,
                            letterSpacing: 2)),
                  ],
                ),
                const Gap(6),
                Text('Autorización bancaria PSE',
                    style: GoogleFonts.lexend(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                        fontSize: 16)),
                const Gap(2),
                Text(
                  'Completa la autorización en el portal seguro de tu banco',
                  style: GoogleFonts.inter(
                      color: Colors.white70, fontSize: 11.5),
                ),
              ],
            ),
          ),
          IconButton(
            onPressed: _confirmExit,
            icon: const Icon(Icons.close_rounded, color: Colors.white),
            tooltip: 'Cerrar',
          ),
        ],
      ),
    );
  }

  Widget _loadingOverlay() {
    return Container(
      color: AppColors.surface0,
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const PremiumProgressRing(
              child: Icon(Icons.account_balance_rounded,
                  color: AppColors.dark, size: 30),
            ),
            const Gap(20),
            Text('Cargando el portal de tu banco…',
                style: GoogleFonts.lexend(
                    fontSize: 15,
                    fontWeight: FontWeight.w600,
                    color: AppColors.textPrimary)),
            const Gap(6),
            Text('Conexión segura PSE',
                style: GoogleFonts.inter(
                    fontSize: 12, color: AppColors.textSecondary)),
          ],
        ),
      ),
    );
  }

  Widget _errorView() {
    return Container(
      color: AppColors.surface0,
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Container(
            width: 84,
            height: 84,
            decoration: BoxDecoration(
              color: AppColors.error.withValues(alpha: 0.12),
              shape: BoxShape.circle,
            ),
            child: const Icon(Icons.cloud_off_rounded,
                size: 40, color: AppColors.error),
          ),
          const Gap(20),
          Text('No pudimos cargar el portal del banco',
              textAlign: TextAlign.center,
              style: GoogleFonts.lexend(
                  fontSize: 19,
                  fontWeight: FontWeight.w700,
                  color: AppColors.textPrimary)),
          const Gap(8),
          Text(
            'Revisa tu conexión e intenta de nuevo. No se realizó ningún cobro.',
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(
                fontSize: 13.5, color: AppColors.textSecondary),
          ),
          const Gap(28),
          IronButton(
            label: 'REINTENTAR',
            onPressed: () {
              setState(() {
                _error = false;
                _loading = true;
              });
              _controller.loadRequest(Uri.parse(widget.authorizationUrl));
            },
          ),
          const Gap(12),
          IronButton(
            label: 'VOLVER AL CHECKOUT',
            isPrimary: false,
            onPressed: () => Navigator.of(context).pop(),
          ),
        ],
      ),
    );
  }
}
