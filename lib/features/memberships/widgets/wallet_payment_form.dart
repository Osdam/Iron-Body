import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../models/payment_form_models.dart';

class WalletPaymentForm extends StatefulWidget {
  final PaymentMethodType type;
  final ValueChanged<WalletFormData> onChanged;

  const WalletPaymentForm({
    super.key,
    required this.type,
    required this.onChanged,
  });

  @override
  State<WalletPaymentForm> createState() => _WalletPaymentFormState();
}

class _WalletPaymentFormState extends State<WalletPaymentForm> {
  final _phoneCtrl = TextEditingController();
  final _docCtrl = TextEditingController();
  final _data = WalletFormData();

  static const _docTypes = ['CC', 'CE', 'TI', 'PP'];

  @override
  void initState() {
    super.initState();
    _phoneCtrl.addListener(_notify);
    _docCtrl.addListener(_notify);
  }

  void _notify() {
    _data.phone = _phoneCtrl.text;
    _data.docNumber = _docCtrl.text;
    widget.onChanged(_data);
  }

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _docCtrl.dispose();
    super.dispose();
  }

  bool get _isNequi => widget.type == PaymentMethodType.nequi;

  static const _bgColor = Color(0xFFFFF8E0);
  static const _borderColor = Color(0xFFD4AC0D);

  String get _brandName => _isNequi ? 'Nequi' : 'Daviplata';

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _walletHeader(),
        const Gap(16),
        _phoneField(),
        const Gap(14),
        Row(
          children: [
            SizedBox(width: 100, child: _docTypeDropdown()),
            const Gap(10),
            Expanded(child: _docNumberField()),
          ],
        ),
        const Gap(16),
        _confirmationMessage(),
      ],
    );
  }

  Widget _walletHeader() {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: _bgColor,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: _borderColor.withValues(alpha: 0.5)),
      ),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration:
                const BoxDecoration(color: AppColors.dark, shape: BoxShape.circle),
            child: const Icon(Icons.smartphone_rounded,
                color: AppColors.primary, size: 20),
          ),
          const Gap(12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Pago con $_brandName',
                  style: GoogleFonts.lexend(
                      fontSize: 14,
                      fontWeight: FontWeight.w700,
                      color: AppColors.textPrimary),
                ),
                Text(
                  'Recibirás la solicitud en tu app de $_brandName',
                  style: GoogleFonts.inter(
                      fontSize: 12, color: AppColors.textSecondary),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _phoneField() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Número de celular',
          style: GoogleFonts.lexend(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary),
        ),
        const Gap(6),
        TextFormField(
          controller: _phoneCtrl,
          keyboardType: TextInputType.phone,
          inputFormatters: [
            FilteringTextInputFormatter.digitsOnly,
            LengthLimitingTextInputFormatter(10),
          ],
          style: GoogleFonts.inter(fontSize: 14, color: AppColors.textPrimary),
          decoration: _deco('3XX XXX XXXX', Icons.phone_android_rounded),
        ),
      ],
    );
  }

  Widget _docTypeDropdown() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Tipo doc.',
          style: GoogleFonts.lexend(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary),
        ),
        const Gap(6),
        Container(
          decoration: BoxDecoration(
            color: AppColors.surfaceContainerLow,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: AppColors.border),
          ),
          padding: const EdgeInsets.symmetric(horizontal: 10),
          child: DropdownButtonHideUnderline(
            child: DropdownButton<String>(
              value: _data.docType,
              isExpanded: true,
              icon: const Icon(Icons.keyboard_arrow_down_rounded,
                  color: AppColors.textSecondary, size: 18),
              dropdownColor: AppColors.surface0,
              borderRadius: BorderRadius.circular(12),
              items: _docTypes
                  .map((d) => DropdownMenuItem(
                        value: d,
                        child: Text(d,
                            style: GoogleFonts.inter(
                                fontSize: 14, color: AppColors.textPrimary)),
                      ))
                  .toList(),
              onChanged: (val) {
                if (val == null) return;
                setState(() {
                  _data.docType = val;
                  _notify();
                });
              },
            ),
          ),
        ),
      ],
    );
  }

  Widget _docNumberField() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Número de documento',
          style: GoogleFonts.lexend(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary),
        ),
        const Gap(6),
        TextFormField(
          controller: _docCtrl,
          keyboardType: TextInputType.number,
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          style: GoogleFonts.inter(fontSize: 14, color: AppColors.textPrimary),
          decoration: _deco('Número de documento', Icons.badge_outlined),
        ),
      ],
    );
  }

  Widget _confirmationMessage() {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFF1B8000).withValues(alpha: 0.06),
        borderRadius: BorderRadius.circular(12),
        border:
            Border.all(color: const Color(0xFF1B8000).withValues(alpha: 0.18)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.check_circle_outline_rounded,
              size: 18, color: Color(0xFF1B8000)),
          const Gap(10),
          Expanded(
            child: Text(
              'Recibirás una notificación push en tu app de $_brandName para '
              'aprobar el pago. Ten la app actualizada y el celular a la mano.',
              style: GoogleFonts.inter(
                  fontSize: 12,
                  color: AppColors.textSecondary,
                  height: 1.5),
            ),
          ),
        ],
      ),
    );
  }

  InputDecoration _deco(String hint, IconData icon) => InputDecoration(
        hintText: hint,
        hintStyle:
            GoogleFonts.inter(fontSize: 14, color: AppColors.textDisabled),
        prefixIcon: Icon(icon, size: 18, color: AppColors.textSecondary),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
        filled: true,
        fillColor: AppColors.surfaceContainerLow,
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: AppColors.primary, width: 1.5),
        ),
      );
}
