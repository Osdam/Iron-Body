import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../../core/theme/app_colors.dart';

/// Card plegable que muestra un bloque legal (título + cuerpo). Marca cuando el
/// usuario lo ha abierto al menos una vez para incentivar la lectura.
class ContractSectionCard extends StatefulWidget {
  final String title;
  final String body;
  final IconData icon;
  final bool initiallyExpanded;

  const ContractSectionCard({
    super.key,
    required this.title,
    required this.body,
    this.icon = Icons.description_outlined,
    this.initiallyExpanded = false,
  });

  @override
  State<ContractSectionCard> createState() => _ContractSectionCardState();
}

class _ContractSectionCardState extends State<ContractSectionCard> {
  late bool _expanded = widget.initiallyExpanded;
  bool _opened = false;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          InkWell(
            onTap: () => setState(() {
              _expanded = !_expanded;
              if (_expanded) _opened = true;
            }),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 12, 12),
              child: Row(
                children: [
                  Container(
                    width: 34,
                    height: 34,
                    decoration: BoxDecoration(
                      color: AppColors.primary.withValues(alpha: 0.14),
                      borderRadius: BorderRadius.circular(9),
                    ),
                    child: Icon(widget.icon,
                        size: 18, color: AppColors.textPrimary),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      widget.title,
                      style: GoogleFonts.lexend(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                    ),
                  ),
                  if (_opened)
                    Padding(
                      padding: const EdgeInsets.only(right: 6),
                      child: Icon(Icons.check_circle_rounded,
                          size: 16, color: AppColors.primary),
                    ),
                  Icon(
                    _expanded
                        ? Icons.keyboard_arrow_up_rounded
                        : Icons.keyboard_arrow_down_rounded,
                    color: AppColors.textSecondary,
                  ),
                ],
              ),
            ),
          ),
          AnimatedCrossFade(
            firstChild: const SizedBox(width: double.infinity),
            secondChild: Container(
              width: double.infinity,
              color: AppColors.surface1,
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
              child: Text(
                widget.body.trim(),
                style: GoogleFonts.inter(
                  fontSize: 12.5,
                  height: 1.5,
                  color: AppColors.textSecondary,
                ),
              ),
            ),
            crossFadeState: _expanded
                ? CrossFadeState.showSecond
                : CrossFadeState.showFirst,
            duration: const Duration(milliseconds: 220),
          ),
        ],
      ),
    );
  }
}
