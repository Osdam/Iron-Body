import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../shared/widgets/iron_button.dart';
import '../models/payment_record.dart';
import '../services/payment_history_service.dart';
import '../services/receipt_pdf_service.dart';
import '../widgets/receipt_card.dart';

/// Documento/extracto profesional de una compra. Permite compartir y descargar
/// el comprobante en PDF. Para pendientes permite actualizar el estado.
class ReceiptScreen extends StatefulWidget {
  final PaymentRecord record;
  const ReceiptScreen({super.key, required this.record});

  @override
  State<ReceiptScreen> createState() => _ReceiptScreenState();
}

class _ReceiptScreenState extends State<ReceiptScreen> {
  late PaymentRecord _r = widget.record;
  bool _busy = false;
  String _busyMsg = '';

  Color get _statusColor => _r.isApproved
      ? const Color(0xFF1FA463)
      : _r.isFailed
          ? AppColors.error
          : const Color(0xFFB07A00);

  Future<void> _run(
    String msg,
    Future<void> Function() action, {
    String errorMsg = 'No pudimos generar el comprobante. Intenta nuevamente.',
  }) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _busyMsg = msg;
    });
    try {
      await action();
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(errorMsg, style: GoogleFonts.inter()),
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _share() => _run('Preparando archivo para compartir…', () async {
        await ReceiptPdfService.instance.share(_r);
      });

  Future<void> _download() => _run(
        'Descargando comprobante…',
        () async {
          final path = await ReceiptPdfService.instance.saveToFile(_r);
          if (!mounted) return;
          final messenger = ScaffoldMessenger.of(context)
            ..hideCurrentSnackBar();
          messenger.showSnackBar(
            SnackBar(
              backgroundColor: AppColors.dark,
              behavior: SnackBarBehavior.floating,
              duration: const Duration(seconds: 10),
              content: Row(
                children: [
                  const Icon(Icons.check_circle_rounded,
                      color: Color(0xFF1FA463), size: 20),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text('Comprobante descargado correctamente',
                        style: GoogleFonts.inter(
                            color: Colors.white,
                            fontWeight: FontWeight.w600,
                            fontSize: 13)),
                  ),
                ],
              ),
              action: SnackBarAction(
                label: 'ABRIR',
                textColor: AppColors.primary,
                onPressed: () => ReceiptPdfService.instance.open(path),
              ),
            ),
          );
        },
        errorMsg:
            'No pudimos descargar el comprobante. Intenta nuevamente.',
      );

  Future<void> _refresh() => _run('Actualizando estado…', () async {
        final fresh =
            await PaymentHistoryService.instance.detail(_r.reference);
        if (mounted) setState(() => _r = fresh);
      });

  @override
  Widget build(BuildContext context) {
    final dt = _r.dateTime ?? DateTime.now();
    final fecha =
        '${dt.day.toString().padLeft(2, '0')}/${dt.month.toString().padLeft(2, '0')}/${dt.year}';
    final hora =
        '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';

    final rows = <ReceiptRow>[
      ReceiptRow('Referencia Iron Body', _r.reference),
      ReceiptRow('Ref ePayco', _r.providerRef ?? 'No disponible'),
      ReceiptRow('Fecha', fecha),
      ReceiptRow('Hora', hora),
      ReceiptRow('Usuario', _r.userName ?? 'No disponible'),
      ReceiptRow(
          'Plan / Producto', _r.product ?? _r.description ?? 'No disponible'),
      ReceiptRow('Método', _r.methodLabel),
      ReceiptRow('Monto', CurrencyFormatter.format(_r.amount)),
      ReceiptRow('Moneda', _r.currency),
      if (!_r.isApproved && (_r.reason?.isNotEmpty ?? false))
        ReceiptRow('Detalle', _r.reason!),
    ];

    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: AppBar(
        backgroundColor: AppColors.surface0,
        elevation: 0,
        foregroundColor: AppColors.textPrimary,
        title: Text('Comprobante',
            style: GoogleFonts.lexend(
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
                fontSize: 17)),
      ),
      body: Stack(
        children: [
          SafeArea(
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
              child: Column(
                children: [
                  PaymentReceiptCard(
                    icon: _r.isApproved
                        ? Icons.verified_rounded
                        : _r.isFailed
                            ? Icons.highlight_off_rounded
                            : Icons.schedule_rounded,
                    iconColor:
                        _r.isApproved ? AppColors.dark : Colors.white,
                    iconBg: _r.isApproved
                        ? AppColors.primary
                        : _statusColor,
                    title: _r.isApproved
                        ? 'Pago confirmado'
                        : _r.isFailed
                            ? 'Pago no realizado'
                            : 'Pago en verificación',
                    subtitle: _r.isApproved
                        ? 'Comprobante de transacción Iron Body'
                        : _r.isFailed
                            ? (_r.reason ?? 'El pago no se completó')
                            : 'Tu solicitud está siendo confirmada',
                    statusLabel: _r.statusLabel,
                    statusColor: _statusColor,
                    barcodeValue: _r.reference,
                    rows: rows,
                  ).animate().fadeIn().slideY(begin: 0.06),
                  const Gap(22),
                  if (_r.isPending) ...[
                    IronButton(
                        label: 'ACTUALIZAR ESTADO', onPressed: _refresh),
                    const Gap(12),
                  ],
                  IronButton(label: 'COMPARTIR PDF', onPressed: _share),
                  const Gap(12),
                  IronButton(
                    label: 'DESCARGAR PDF',
                    isPrimary: false,
                    onPressed: _download,
                  ),
                  const Gap(8),
                  TextButton(
                    onPressed: () => Navigator.of(context).maybePop(),
                    child: Text('Volver',
                        style: GoogleFonts.lexend(
                            color: AppColors.textSecondary,
                            fontWeight: FontWeight.w600)),
                  ),
                ],
              ),
            ),
          ),
          if (_busy)
            Positioned.fill(
              child: Container(
                color: Colors.black.withValues(alpha: 0.35),
                child: Center(
                  child: Container(
                    padding: const EdgeInsets.all(24),
                    decoration: BoxDecoration(
                      color: AppColors.surface0,
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const PremiumProgressRing(
                          size: 64,
                          child: Icon(Icons.picture_as_pdf_rounded,
                              color: AppColors.dark, size: 24),
                        ),
                        const Gap(16),
                        Text(_busyMsg,
                            style: GoogleFonts.lexend(
                                fontSize: 14,
                                fontWeight: FontWeight.w600,
                                color: AppColors.textPrimary)),
                      ],
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}
