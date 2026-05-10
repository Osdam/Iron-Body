import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../models/payment_form_models.dart';
import 'visual_bank_card.dart';

class CreditDebitCardForm extends StatefulWidget {
  final PaymentMethodType type;
  final ValueChanged<PaymentMethodType> onTypeChanged;
  final ValueChanged<CardFormData> onChanged;

  const CreditDebitCardForm({
    super.key,
    required this.type,
    required this.onTypeChanged,
    required this.onChanged,
  });

  @override
  State<CreditDebitCardForm> createState() => _CreditDebitCardFormState();
}

class _CreditDebitCardFormState extends State<CreditDebitCardForm> {
  final _numberCtrl = TextEditingController();
  final _holderCtrl = TextEditingController();
  final _expiryCtrl = TextEditingController();
  final _cvvCtrl = TextEditingController();
  final _cvvFocus = FocusNode();

  bool _cvvFocused = false;
  final _data = CardFormData();

  @override
  void initState() {
    super.initState();
    _cvvFocus.addListener(() {
      if (mounted) setState(() => _cvvFocused = _cvvFocus.hasFocus);
    });
    _numberCtrl.addListener(_notify);
    _holderCtrl.addListener(_notify);
    _expiryCtrl.addListener(_notify);
    _cvvCtrl.addListener(_notify);
  }

  void _notify() {
    _data.number = _numberCtrl.text;
    _data.holder = _holderCtrl.text;
    _data.expiry = _expiryCtrl.text;
    _data.cvv = _cvvCtrl.text;
    widget.onChanged(_data);
  }

  @override
  void dispose() {
    _numberCtrl.dispose();
    _holderCtrl.dispose();
    _expiryCtrl.dispose();
    _cvvCtrl.dispose();
    _cvvFocus.dispose();
    super.dispose();
  }

  bool get _isCreditFront => widget.type == PaymentMethodType.credit;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _buildCardStack(),
        const Gap(20),
        _buildTypeToggle(),
        const Gap(20),
        _buildFormFields(),
      ],
    );
  }

  Widget _buildCardStack() {
    return SizedBox(
      height: 215,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          // Back card — dimmed, rotated, offset
          AnimatedPositioned(
            duration: const Duration(milliseconds: 420),
            curve: Curves.easeInOut,
            top: _isCreditFront ? 20 : 0,
            left: _isCreditFront ? 18 : 0,
            right: _isCreditFront ? -18 : 0,
            bottom: _isCreditFront ? 0 : 20,
            child: AnimatedOpacity(
              duration: const Duration(milliseconds: 400),
              opacity: 0.55,
              child: Transform.rotate(
                angle: _isCreditFront ? 0.04 : -0.04,
                child: VisualBankCard(
                  cardNumber: _data.number,
                  cardHolder: _data.holder,
                  expiry: _data.expiry,
                  cvv: _data.cvv,
                  isFlipped: false,
                  type: _isCreditFront
                      ? PaymentMethodType.debit
                      : PaymentMethodType.credit,
                ),
              ),
            ),
          ),
          // Front card — full, active, can flip for CVV
          AnimatedPositioned(
            duration: const Duration(milliseconds: 420),
            curve: Curves.easeInOut,
            top: _isCreditFront ? 0 : 20,
            left: _isCreditFront ? 0 : 18,
            right: _isCreditFront ? 0 : -18,
            bottom: _isCreditFront ? 20 : 0,
            child: VisualBankCard(
              cardNumber: _data.number,
              cardHolder: _data.holder,
              expiry: _data.expiry,
              cvv: _data.cvv,
              isFlipped: _cvvFocused,
              type: widget.type,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTypeToggle() {
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLow,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        children: [
          _TypeTab(
            label: 'Crédito',
            isSelected: widget.type == PaymentMethodType.credit,
            onTap: () => widget.onTypeChanged(PaymentMethodType.credit),
          ),
          _TypeTab(
            label: 'Débito',
            isSelected: widget.type == PaymentMethodType.debit,
            onTap: () => widget.onTypeChanged(PaymentMethodType.debit),
          ),
        ],
      ),
    );
  }

  Widget _buildFormFields() {
    return Column(
      children: [
        _field(
          label: 'Número de tarjeta',
          controller: _numberCtrl,
          hint: '#### #### #### ####',
          icon: Icons.credit_card_rounded,
          keyboard: TextInputType.number,
          formatters: [_CardNumberFormatter()],
        ),
        const Gap(14),
        _field(
          label: 'Nombre del titular',
          controller: _holderCtrl,
          hint: 'Como aparece en la tarjeta',
          icon: Icons.person_outline_rounded,
          capitalization: TextCapitalization.characters,
        ),
        const Gap(14),
        Row(
          children: [
            Expanded(
              child: _field(
                label: 'Expiración',
                controller: _expiryCtrl,
                hint: 'MM/AA',
                icon: Icons.date_range_rounded,
                keyboard: TextInputType.number,
                formatters: [_ExpiryFormatter()],
              ),
            ),
            const Gap(12),
            Expanded(
              child: _field(
                label: 'CVV',
                controller: _cvvCtrl,
                hint: '•••',
                icon: Icons.lock_outline_rounded,
                keyboard: TextInputType.number,
                focusNode: _cvvFocus,
                obscure: true,
                maxLen: 4,
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _field({
    required String label,
    required TextEditingController controller,
    required String hint,
    required IconData icon,
    TextInputType keyboard = TextInputType.text,
    List<TextInputFormatter>? formatters,
    TextCapitalization capitalization = TextCapitalization.none,
    FocusNode? focusNode,
    bool obscure = false,
    int? maxLen,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: GoogleFonts.lexend(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary),
        ),
        const Gap(6),
        TextFormField(
          controller: controller,
          focusNode: focusNode,
          keyboardType: keyboard,
          inputFormatters: formatters,
          textCapitalization: capitalization,
          obscureText: obscure,
          maxLength: maxLen,
          style: GoogleFonts.inter(fontSize: 14, color: AppColors.textPrimary),
          decoration: _deco(hint, icon),
        ),
      ],
    );
  }

  InputDecoration _deco(String hint, IconData icon) => InputDecoration(
        hintText: hint,
        hintStyle:
            GoogleFonts.inter(fontSize: 14, color: AppColors.textDisabled),
        counterText: '',
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

class _TypeTab extends StatelessWidget {
  final String label;
  final bool isSelected;
  final VoidCallback onTap;
  const _TypeTab(
      {required this.label, required this.isSelected, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: GestureDetector(
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 220),
          padding: const EdgeInsets.symmetric(vertical: 10),
          decoration: BoxDecoration(
            color: isSelected ? AppColors.dark : Colors.transparent,
            borderRadius: BorderRadius.circular(10),
          ),
          alignment: Alignment.center,
          child: Text(
            label,
            style: GoogleFonts.lexend(
              fontSize: 13,
              fontWeight: FontWeight.w700,
              color: isSelected ? Colors.white : AppColors.textSecondary,
            ),
          ),
        ),
      ),
    );
  }
}

class _CardNumberFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(
      TextEditingValue old, TextEditingValue newer) {
    final digits = newer.text.replaceAll(RegExp(r'\D'), '');
    final buf = StringBuffer();
    for (int i = 0; i < digits.length && i < 16; i++) {
      if (i > 0 && i % 4 == 0) buf.write(' ');
      buf.write(digits[i]);
    }
    final str = buf.toString();
    return TextEditingValue(
        text: str, selection: TextSelection.collapsed(offset: str.length));
  }
}

class _ExpiryFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(
      TextEditingValue old, TextEditingValue newer) {
    final digits = newer.text.replaceAll(RegExp(r'\D'), '');
    final buf = StringBuffer();
    for (int i = 0; i < digits.length && i < 4; i++) {
      if (i == 2) buf.write('/');
      buf.write(digits[i]);
    }
    final str = buf.toString();
    return TextEditingValue(
        text: str, selection: TextSelection.collapsed(offset: str.length));
  }
}
