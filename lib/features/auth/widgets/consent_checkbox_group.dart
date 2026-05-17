import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../../core/theme/app_colors.dart';

class ConsentItem {
  final String key;
  final Widget label;
  final bool value;
  final ValueChanged<bool> onChanged;
  const ConsentItem({
    required this.key,
    required this.label,
    required this.value,
    required this.onChanged,
  });
}

/// Grupo de casillas de aceptación legal, presentado como una card limpia.
class ConsentCheckboxGroup extends StatelessWidget {
  final List<ConsentItem> items;
  const ConsentCheckboxGroup({super.key, required this.items});

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
      child: Column(
        children: [
          for (var i = 0; i < items.length; i++) ...[
            _ConsentRow(item: items[i]),
            if (i < items.length - 1)
              const Divider(height: 1, color: AppColors.border),
          ],
        ],
      ),
    );
  }
}

class _ConsentRow extends StatelessWidget {
  final ConsentItem item;
  const _ConsentRow({required this.item});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: () => item.onChanged(!item.value),
      borderRadius: BorderRadius.circular(10),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Checkbox(
              value: item.value,
              onChanged: (v) => item.onChanged(v ?? false),
              activeColor: AppColors.primary,
              checkColor: AppColors.onPrimary,
              side: const BorderSide(color: AppColors.border),
              shape:
                  RoundedRectangleBorder(borderRadius: BorderRadius.circular(4)),
              materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
              visualDensity: VisualDensity.compact,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.only(top: 9),
                child: DefaultTextStyle.merge(
                  style: GoogleFonts.inter(
                    fontSize: 13,
                    height: 1.35,
                    color: AppColors.textSecondary,
                  ),
                  child: item.label,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
