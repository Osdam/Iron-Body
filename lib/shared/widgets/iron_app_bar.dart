import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import '../../core/theme/app_colors.dart';

class IronAppBar extends StatelessWidget implements PreferredSizeWidget {
  final String title;
  final List<Widget>? actions;
  final bool showBack;
  final Widget? leading;
  final PreferredSizeWidget? bottom;

  const IronAppBar({
    super.key,
    required this.title,
    this.actions,
    this.showBack = true,
    this.leading,
    this.bottom,
  });

  @override
  Widget build(BuildContext context) {
    return AppBar(
      backgroundColor: AppColors.surface0,
      elevation: 0,
      scrolledUnderElevation: 0,
      automaticallyImplyLeading: showBack,
      leading: leading ??
          (showBack && Navigator.canPop(context)
              ? IconButton(
                  icon: const Icon(Icons.arrow_back_ios_new_rounded, size: 20),
                  color: AppColors.textPrimary,
                  onPressed: () => Navigator.pop(context),
                )
              : null),
      title: Text(
        title,
        style: GoogleFonts.lexend(
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: AppColors.textPrimary,
        ),
      ),
      actions: actions,
      bottom: bottom,
    );
  }

  @override
  Size get preferredSize =>
      Size.fromHeight(kToolbarHeight + (bottom?.preferredSize.height ?? 0));
}
