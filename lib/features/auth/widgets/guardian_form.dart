import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../../core/theme/app_colors.dart';
import '../../../shared/widgets/iron_input.dart';

/// Formulario del responsable legal / acudiente. Solo se muestra cuando el
/// usuario que se registra es menor de edad.
class GuardianForm extends StatelessWidget {
  final TextEditingController nameCtrl;
  final TextEditingController documentCtrl;
  final TextEditingController phoneCtrl;
  final TextEditingController emailCtrl;
  final String relationship;
  final ValueChanged<String> onRelationshipChanged;
  final bool acceptsResponsibility;
  final ValueChanged<bool> onAcceptsChanged;
  final VoidCallback onAnyChanged;

  static const relationships = <String>[
    'Madre',
    'Padre',
    'Abuelo/a',
    'Tío/a',
    'Hermano/a mayor',
    'Tutor/a legal',
    'Otro',
  ];

  const GuardianForm({
    super.key,
    required this.nameCtrl,
    required this.documentCtrl,
    required this.phoneCtrl,
    required this.emailCtrl,
    required this.relationship,
    required this.onRelationshipChanged,
    required this.acceptsResponsibility,
    required this.onAcceptsChanged,
    required this.onAnyChanged,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.primary.withValues(alpha: 0.4)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(
                  color: AppColors.primary.withValues(alpha: 0.16),
                  borderRadius: BorderRadius.circular(9),
                ),
                child: const Icon(Icons.family_restroom_rounded,
                    size: 18, color: AppColors.textPrimary),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Responsable legal / acudiente',
                  style: GoogleFonts.lexend(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            'Detectamos que eres menor de edad. Un adulto responsable debe '
            'completar estos datos y autorizar tu registro.',
            style: GoogleFonts.inter(
              fontSize: 11.5,
              height: 1.4,
              color: AppColors.textSecondary,
            ),
          ),
          const SizedBox(height: 14),
          IronInput(
            label: 'Nombre completo del responsable',
            hint: 'Ej: María García López',
            controller: nameCtrl,
            onChanged: (_) => onAnyChanged(),
          ),
          const SizedBox(height: 12),
          IronInput(
            label: 'Número de documento del responsable',
            hint: 'Cédula',
            controller: documentCtrl,
            keyboardType: TextInputType.number,
            onChanged: (_) => onAnyChanged(),
          ),
          const SizedBox(height: 12),
          IronInput(
            label: 'Teléfono del responsable',
            hint: '300 000 0000',
            controller: phoneCtrl,
            keyboardType: TextInputType.phone,
            onChanged: (_) => onAnyChanged(),
          ),
          const SizedBox(height: 12),
          IronInput(
            label: 'Correo del responsable',
            hint: 'correo@ejemplo.com',
            controller: emailCtrl,
            keyboardType: TextInputType.emailAddress,
            onChanged: (_) => onAnyChanged(),
          ),
          const SizedBox(height: 12),
          Text(
            'Parentesco',
            style: GoogleFonts.inter(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: AppColors.textSecondary,
            ),
          ),
          const SizedBox(height: 6),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12),
            decoration: BoxDecoration(
              color: AppColors.surface1,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppColors.border),
            ),
            child: DropdownButton<String>(
              value: relationship.isEmpty ? null : relationship,
              hint: Text('Selecciona…',
                  style: GoogleFonts.inter(
                      fontSize: 15, color: AppColors.textDisabled)),
              isExpanded: true,
              underline: const SizedBox(),
              icon: const Icon(Icons.keyboard_arrow_down_rounded,
                  color: AppColors.textSecondary),
              style: GoogleFonts.inter(
                fontSize: 15,
                fontWeight: FontWeight.w500,
                color: AppColors.textPrimary,
              ),
              onChanged: (v) {
                if (v != null) onRelationshipChanged(v);
              },
              items: relationships
                  .map((e) => DropdownMenuItem(value: e, child: Text(e)))
                  .toList(),
            ),
          ),
          const SizedBox(height: 8),
          InkWell(
            onTap: () => onAcceptsChanged(!acceptsResponsibility),
            borderRadius: BorderRadius.circular(10),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Checkbox(
                    value: acceptsResponsibility,
                    onChanged: (v) => onAcceptsChanged(v ?? false),
                    activeColor: AppColors.primary,
                    checkColor: AppColors.onPrimary,
                    side: const BorderSide(color: AppColors.border),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(4)),
                    materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    visualDensity: VisualDensity.compact,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.only(top: 9),
                      child: Text(
                        'Como responsable legal, autorizo el registro, el '
                        'ingreso y uso del gimnasio, el tratamiento de los '
                        'datos personales del menor y la adquisición de la '
                        'membresía, y declaro que la información es verídica.',
                        style: GoogleFonts.inter(
                          fontSize: 12.5,
                          height: 1.35,
                          color: AppColors.textSecondary,
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
