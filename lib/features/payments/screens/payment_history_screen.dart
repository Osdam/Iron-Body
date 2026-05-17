import 'package:flutter/material.dart';
import 'package:flutter_animate/flutter_animate.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../../core/theme/app_colors.dart';
import '../../../core/utils/currency_formatter.dart';
import '../../../shared/widgets/iron_button.dart';
import '../../memberships/screens/memberships_screen.dart';
import '../models/payment_record.dart';
import '../services/payment_history_service.dart';
import '../services/receipt_pdf_service.dart';
import 'receipt_screen.dart';

/// Historial de compras/pagos — lista de cards profesionales.
class PaymentHistoryScreen extends StatefulWidget {
  const PaymentHistoryScreen({super.key});

  @override
  State<PaymentHistoryScreen> createState() => _PaymentHistoryScreenState();
}

class _PaymentHistoryScreenState extends State<PaymentHistoryScreen> {
  bool _loading = true;
  bool _error = false;
  List<PaymentRecord> _items = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = false;
    });
    try {
      final items = await PaymentHistoryService.instance.history();
      if (!mounted) return;
      setState(() {
        _items = items;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = true;
      });
    }
  }

  Color _statusColor(PaymentRecord r) => r.isApproved
      ? const Color(0xFF1FA463)
      : r.isFailed
          ? AppColors.error
          : const Color(0xFFB07A00);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface0,
      appBar: AppBar(
        backgroundColor: AppColors.surface0,
        elevation: 0,
        foregroundColor: AppColors.textPrimary,
        title: Text('Historial de pagos',
            style: GoogleFonts.lexend(
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary,
                fontSize: 17)),
      ),
      body: SafeArea(
        child: RefreshIndicator(
          color: AppColors.dark,
          onRefresh: _load,
          child: _loading
              ? const Center(
                  child: CircularProgressIndicator(color: AppColors.dark))
              : _error
                  ? _errorState()
                  : _items.isEmpty
                      ? _emptyState()
                      : ListView.separated(
                          padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
                          itemCount: _items.length,
                          separatorBuilder: (context, index) =>
                              const Gap(12),
                          itemBuilder: (_, i) => _card(_items[i])
                              .animate()
                              .fadeIn(delay: (i * 40).ms)
                              .slideY(begin: 0.06),
                        ),
        ),
      ),
    );
  }

  Widget _card(PaymentRecord r) {
    final dt = r.dateTime;
    final fecha = dt == null
        ? 'No disponible'
        : '${dt.day.toString().padLeft(2, '0')}/${dt.month.toString().padLeft(2, '0')}/${dt.year}';
    final shortRef = r.reference.length > 14
        ? '…${r.reference.substring(r.reference.length - 12)}'
        : r.reference;
    final c = _statusColor(r);

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(24),
        border: Border.all(color: AppColors.border),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.05),
            blurRadius: 18,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  r.product ?? r.description ?? 'Pago Iron Body',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: GoogleFonts.lexend(
                      fontSize: 15,
                      fontWeight: FontWeight.w700,
                      color: AppColors.textPrimary),
                ),
              ),
              const Gap(8),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: c.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(r.statusLabel,
                    style: GoogleFonts.lexend(
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                        color: c)),
              ),
            ],
          ),
          const Gap(10),
          Text(
            CurrencyFormatter.format(r.amount),
            style: GoogleFonts.lexend(
                fontSize: 22,
                fontWeight: FontWeight.w800,
                color: AppColors.textPrimary),
          ),
          const Gap(8),
          Row(
            children: [
              Icon(Icons.credit_card_rounded,
                  size: 14, color: AppColors.textSecondary),
              const Gap(6),
              Text(r.methodLabel,
                  style: GoogleFonts.inter(
                      fontSize: 12.5, color: AppColors.textSecondary)),
              const Gap(14),
              Icon(Icons.calendar_today_rounded,
                  size: 13, color: AppColors.textSecondary),
              const Gap(6),
              Text(fecha,
                  style: GoogleFonts.inter(
                      fontSize: 12.5, color: AppColors.textSecondary)),
            ],
          ),
          const Gap(4),
          Text('Ref: $shortRef',
              style: GoogleFonts.inter(
                  fontSize: 11, color: AppColors.textDisabled)),
          const Gap(14),
          Row(
            children: [
              Expanded(
                child: GestureDetector(
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(
                        builder: (_) => ReceiptScreen(record: r)),
                  ),
                  child: Container(
                    height: 44,
                    decoration: BoxDecoration(
                      color: AppColors.dark,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    alignment: Alignment.center,
                    child: Text('Ver',
                        style: GoogleFonts.lexend(
                            fontSize: 13,
                            fontWeight: FontWeight.w700,
                            color: AppColors.primary)),
                  ),
                ),
              ),
              if (r.isApproved) ...[
                const Gap(10),
                Expanded(
                  child: GestureDetector(
                    onTap: () => ReceiptPdfService.instance.share(r),
                    child: Container(
                      height: 44,
                      decoration: BoxDecoration(
                        color: AppColors.primary,
                        borderRadius: BorderRadius.circular(12),
                      ),
                      alignment: Alignment.center,
                      child: Text('Compartir',
                          style: GoogleFonts.lexend(
                              fontSize: 13,
                              fontWeight: FontWeight.w700,
                              color: AppColors.dark)),
                    ),
                  ),
                ),
              ],
            ],
          ),
        ],
      ),
    );
  }

  Widget _emptyState() {
    return ListView(
      padding: const EdgeInsets.fromLTRB(28, 80, 28, 28),
      children: [
        Center(
          child: Container(
            width: 96,
            height: 96,
            decoration: BoxDecoration(
              color: AppColors.surfaceContainerLow,
              shape: BoxShape.circle,
            ),
            child: const Icon(Icons.receipt_long_rounded,
                size: 44, color: AppColors.textDisabled),
          ),
        ),
        const Gap(22),
        Text('Aún no tienes compras registradas',
            textAlign: TextAlign.center,
            style: GoogleFonts.lexend(
                fontSize: 18,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary)),
        const Gap(8),
        Text(
          'Cuando realices un pago, tu comprobante aparecerá aquí.',
          textAlign: TextAlign.center,
          style: GoogleFonts.inter(
              fontSize: 13.5, color: AppColors.textSecondary),
        ),
        const Gap(28),
        IronButton(
          label: 'EXPLORAR PLANES',
          onPressed: () => Navigator.push(
            context,
            MaterialPageRoute(builder: (_) => const MembershipsScreen()),
          ),
        ),
      ],
    );
  }

  Widget _errorState() {
    return ListView(
      padding: const EdgeInsets.fromLTRB(28, 90, 28, 28),
      children: [
        const Icon(Icons.cloud_off_rounded,
            size: 52, color: AppColors.textDisabled),
        const Gap(16),
        Text('No pudimos cargar tu historial',
            textAlign: TextAlign.center,
            style: GoogleFonts.lexend(
                fontSize: 17,
                fontWeight: FontWeight.w700,
                color: AppColors.textPrimary)),
        const Gap(8),
        Text('Revisa tu conexión e intenta nuevamente.',
            textAlign: TextAlign.center,
            style: GoogleFonts.inter(
                fontSize: 13.5, color: AppColors.textSecondary)),
        const Gap(24),
        IronButton(label: 'REINTENTAR', onPressed: _load),
      ],
    );
  }
}
