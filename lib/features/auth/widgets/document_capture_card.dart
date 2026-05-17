import 'dart:io';

import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import '../../../core/theme/app_colors.dart';
import '../screens/document_capture_screen.dart';

/// Card de una cara del documento (frente o reverso) en el paso de validación
/// de identidad. Abre la captura guiada ([DocumentCaptureScreen]); cuando vuelve
/// con una ruta, la imagen ya pasó la validación de calidad.
class DocumentSideCapture extends StatelessWidget {
  final DocumentSide side;
  final String? imagePath;
  final bool busy; // procesando (persistiendo / OCR) tras la captura
  final ValueChanged<String> onPicked;
  final bool enabled;

  const DocumentSideCapture({
    super.key,
    required this.side,
    required this.imagePath,
    required this.onPicked,
    this.busy = false,
    this.enabled = true,
  });

  Future<void> _capture(BuildContext context) async {
    if (!enabled || busy) return;
    final path = await Navigator.of(context).push<String?>(
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => DocumentCaptureScreen(side: side),
      ),
    );
    if (path != null) onPicked(path);
  }

  @override
  Widget build(BuildContext context) {
    final hasImage = imagePath != null;
    return Opacity(
      opacity: enabled ? 1 : 0.5,
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppColors.surface0,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color: hasImage
                ? AppColors.primary.withValues(alpha: 0.55)
                : AppColors.border,
            width: hasImage ? 1.4 : 1,
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  hasImage ? Icons.check_circle_rounded : Icons.badge_outlined,
                  size: 18,
                  color: hasImage ? AppColors.primary : AppColors.textSecondary,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    hasImage ? side.validatedEs : side.titleEs,
                    style: GoogleFonts.lexend(
                      fontSize: 13.5,
                      fontWeight: FontWeight.w700,
                      color: AppColors.textPrimary,
                    ),
                  ),
                ),
                if (hasImage)
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: AppColors.primary.withValues(alpha: 0.16),
                      borderRadius: BorderRadius.circular(99),
                    ),
                    child: Text(
                      'Validado',
                      style: GoogleFonts.inter(
                        fontSize: 10,
                        fontWeight: FontWeight.w700,
                        color: AppColors.textPrimary,
                      ),
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 10),
            GestureDetector(
              onTap: () => _capture(context),
              child: AspectRatio(
                aspectRatio: 16 / 10,
                child: Container(
                  decoration: BoxDecoration(
                    color: AppColors.surface1,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: AppColors.border),
                  ),
                  clipBehavior: Clip.antiAlias,
                  child: hasImage
                      ? Stack(
                          fit: StackFit.expand,
                          children: [
                            Image.file(
                              File(imagePath!),
                              fit: BoxFit.cover,
                              gaplessPlayback: true,
                              errorBuilder: (_, _, _) => const Center(
                                child: Icon(Icons.broken_image_outlined,
                                    color: AppColors.textDisabled),
                              ),
                            ),
                            if (busy)
                              Container(
                                color: AppColors.dark.withValues(alpha: 0.4),
                                alignment: Alignment.center,
                                child: const SizedBox(
                                  width: 22,
                                  height: 22,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2.4,
                                    valueColor: AlwaysStoppedAnimation<Color>(
                                        AppColors.primary),
                                  ),
                                ),
                              ),
                            Positioned(
                              right: 8,
                              bottom: 8,
                              child: Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 10, vertical: 5),
                                decoration: BoxDecoration(
                                  color: AppColors.dark.withValues(alpha: 0.7),
                                  borderRadius: BorderRadius.circular(99),
                                ),
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    const Icon(Icons.replay_rounded,
                                        size: 13, color: AppColors.onDark),
                                    const SizedBox(width: 4),
                                    Text(
                                      'Repetir',
                                      style: GoogleFonts.inter(
                                        fontSize: 11,
                                        fontWeight: FontWeight.w700,
                                        color: AppColors.onDark,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ],
                        )
                      : Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const Icon(Icons.document_scanner_outlined,
                                size: 28, color: AppColors.textSecondary),
                            const SizedBox(height: 6),
                            Text(
                              'Toca para capturar el ${side.shortEs}',
                              textAlign: TextAlign.center,
                              style: GoogleFonts.inter(
                                fontSize: 11.5,
                                fontWeight: FontWeight.w600,
                                color: AppColors.textSecondary,
                              ),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              'Captura guiada con validación',
                              style: GoogleFonts.inter(
                                fontSize: 10.5,
                                color: AppColors.textDisabled,
                              ),
                            ),
                          ],
                        ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
