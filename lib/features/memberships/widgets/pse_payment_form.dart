import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gap/gap.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../../core/theme/app_colors.dart';
import '../models/payment_form_models.dart';

class PsePaymentForm extends StatefulWidget {
  final ValueChanged<PseFormData> onChanged;
  const PsePaymentForm({super.key, required this.onChanged});

  @override
  State<PsePaymentForm> createState() => _PsePaymentFormState();
}

class _PsePaymentFormState extends State<PsePaymentForm> {
  final _data = PseFormData();
  final _docCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();

  static const _banks = [
    ('001', 'Banco de Bogotá'),
    ('002', 'Banco Popular'),
    ('007', 'Bancolombia'),
    ('009', 'Citibank Colombia'),
    ('013', 'BBVA Colombia'),
    ('019', 'Scotiabank Colpatria'),
    ('023', 'Banco de Occidente'),
    ('032', 'Banco Caja Social'),
    ('040', 'Banco Agrario'),
    ('041', 'Davivienda'),
    ('042', 'Banco AV Villas'),
    ('060', 'Banco Pichincha'),
    ('061', 'Bancoomeva'),
    ('062', 'Banco Falabella'),
    ('070', 'Lulo Bank'),
    ('072', 'Nu Colombia'),
    ('507', 'Nequi'),
  ];

  static const _docTypes = ['CC', 'CE', 'NIT', 'TI', 'PP'];

  @override
  void initState() {
    super.initState();
    _docCtrl.addListener(_notify);
    _emailCtrl.addListener(_notify);
    _phoneCtrl.addListener(_notify);
  }

  void _notify() {
    _data.docNumber = _docCtrl.text;
    _data.email = _emailCtrl.text;
    _data.phone = _phoneCtrl.text;
    widget.onChanged(_data);
  }

  @override
  void dispose() {
    _docCtrl.dispose();
    _emailCtrl.dispose();
    _phoneCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _infoBox(),
        const Gap(16),
        _personTypeToggle(),
        const Gap(14),
        _bankDropdown(),
        const Gap(14),
        Row(
          children: [
            SizedBox(width: 100, child: _docTypeDropdown()),
            const Gap(10),
            Expanded(
              child: _labelField(
                'Número de documento',
                _docCtrl,
                Icons.badge_outlined,
                keyboard: TextInputType.number,
                formatters: [FilteringTextInputFormatter.digitsOnly],
              ),
            ),
          ],
        ),
        const Gap(14),
        _labelField('Correo electrónico', _emailCtrl, Icons.email_outlined,
            keyboard: TextInputType.emailAddress),
        const Gap(14),
        _labelField(
          'Teléfono (opcional)',
          _phoneCtrl,
          Icons.phone_outlined,
          keyboard: TextInputType.phone,
          formatters: [
            FilteringTextInputFormatter.digitsOnly,
            LengthLimitingTextInputFormatter(10),
          ],
          hint: '10 dígitos',
        ),
      ],
    );
  }

  Widget _infoBox() {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: const Color(0xFF1A3A5C).withValues(alpha: 0.07),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFF1A3A5C).withValues(alpha: 0.18)),
      ),
      child: Row(
        children: [
          const Icon(Icons.info_outline_rounded,
              size: 18, color: Color(0xFF1A3A5C)),
          const Gap(10),
          Expanded(
            child: Text(
              'Serás redirigido a tu banco para autorizar el pago de forma segura.',
              style: GoogleFonts.inter(
                  fontSize: 12, color: AppColors.textSecondary),
            ),
          ),
        ],
      ),
    );
  }

  Widget _personTypeToggle() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Tipo de persona',
          style: GoogleFonts.lexend(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: AppColors.textPrimary),
        ),
        const Gap(8),
        Row(
          children: [
            _PersonChip(
              label: 'Natural',
              isSelected: _data.personType == PersonType.natural,
              onTap: () => setState(() {
                _data.personType = PersonType.natural;
                _notify();
              }),
            ),
            const Gap(10),
            _PersonChip(
              label: 'Jurídica',
              isSelected: _data.personType == PersonType.juridica,
              onTap: () => setState(() {
                _data.personType = PersonType.juridica;
                _notify();
              }),
            ),
          ],
        ),
      ],
    );
  }

  Widget _bankDropdown() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Banco',
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
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: DropdownButtonHideUnderline(
            child: DropdownButton<String>(
              value: _data.bankCode.isEmpty ? null : _data.bankCode,
              hint: Text(
                'Selecciona tu banco',
                style: GoogleFonts.inter(
                    fontSize: 14, color: AppColors.textDisabled),
              ),
              isExpanded: true,
              icon: const Icon(Icons.keyboard_arrow_down_rounded,
                  color: AppColors.textSecondary),
              dropdownColor: AppColors.surface0,
              borderRadius: BorderRadius.circular(12),
              items: _banks
                  .map((b) => DropdownMenuItem(
                        value: b.$1,
                        child: Text(
                          b.$2,
                          style: GoogleFonts.inter(
                              fontSize: 14, color: AppColors.textPrimary),
                        ),
                      ))
                  .toList(),
              onChanged: (val) {
                if (val == null) return;
                final bank = _banks.firstWhere((b) => b.$1 == val);
                setState(() {
                  _data.bankCode = bank.$1;
                  _data.bankName = bank.$2;
                  _notify();
                });
              },
            ),
          ),
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

  Widget _labelField(
    String label,
    TextEditingController ctrl,
    IconData icon, {
    TextInputType? keyboard,
    List<TextInputFormatter>? formatters,
    String? hint,
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
          controller: ctrl,
          keyboardType: keyboard,
          inputFormatters: formatters,
          style: GoogleFonts.inter(fontSize: 14, color: AppColors.textPrimary),
          decoration: InputDecoration(
            hintText: hint ?? '',
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
              borderSide:
                  const BorderSide(color: AppColors.primary, width: 1.5),
            ),
          ),
        ),
      ],
    );
  }
}

class _PersonChip extends StatelessWidget {
  final String label;
  final bool isSelected;
  final VoidCallback onTap;
  const _PersonChip(
      {required this.label, required this.isSelected, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Expanded(
      child: GestureDetector(
        onTap: onTap,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 200),
          padding: const EdgeInsets.symmetric(vertical: 10),
          decoration: BoxDecoration(
            color: isSelected ? AppColors.dark : AppColors.surfaceContainerLow,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(
                color: isSelected ? AppColors.dark : AppColors.border),
          ),
          alignment: Alignment.center,
          child: Text(
            label,
            style: GoogleFonts.lexend(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: isSelected ? Colors.white : AppColors.textSecondary,
            ),
          ),
        ),
      ),
    );
  }
}
