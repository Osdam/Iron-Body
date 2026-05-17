import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:signature/signature.dart';

import '../../../core/theme/app_colors.dart';
import '../models/legal_consent.dart';
import '../services/signature_service.dart';

/// Sección para anexar la firma: dibujarla en pantalla o subir un documento
/// firmado (imagen o PDF). Persiste el soporte en almacenamiento privado y lo
/// reporta al padre vía [onAttached].
class SignaturePadSection extends StatefulWidget {
  final SignatureSupport current;
  final ValueChanged<SignatureSupport> onAttached;
  final VoidCallback onCleared;

  const SignaturePadSection({
    super.key,
    required this.current,
    required this.onAttached,
    required this.onCleared,
  });

  @override
  State<SignaturePadSection> createState() => _SignaturePadSectionState();
}

class _SignaturePadSectionState extends State<SignaturePadSection> {
  int _mode = 0; // 0 = dibujar, 1 = subir
  bool _busy = false;
  String? _error;

  late final SignatureController _sigCtrl = SignatureController(
    penStrokeWidth: 3,
    penColor: AppColors.dark,
    exportBackgroundColor: Colors.white,
  );

  @override
  void dispose() {
    _sigCtrl.dispose();
    super.dispose();
  }

  Future<void> _useDrawnSignature() async {
    if (_sigCtrl.isEmpty) {
      setState(() => _error = 'Dibuja tu firma antes de continuar.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final bytes = await _sigCtrl.toPngBytes();
      if (bytes == null) throw const SignatureException('No se pudo exportar.');
      // Reemplaza cualquier firma previa.
      if (widget.current.isAttached) {
        await SignatureService.instance.disposeSignature(widget.current);
      }
      final support =
          await SignatureService.instance.saveDrawnSignature(bytes);
      if (!mounted) return;
      widget.onAttached(support);
      _sigCtrl.clear();
    } on SignatureException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'No se pudo guardar la firma.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickSignedDocument() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await FilePicker.platform.pickFiles(
        type: FileType.custom,
        allowedExtensions: const ['pdf', 'png', 'jpg', 'jpeg'],
      );
      final path = result?.files.single.path;
      if (path == null) {
        if (mounted) setState(() => _busy = false);
        return;
      }
      if (widget.current.isAttached) {
        await SignatureService.instance.disposeSignature(widget.current);
      }
      final support =
          await SignatureService.instance.saveUploadedFile(path);
      if (!mounted) return;
      widget.onAttached(support);
    } on SignatureException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'No se pudo adjuntar el documento.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _replace() async {
    await SignatureService.instance.disposeSignature(widget.current);
    if (!mounted) return;
    widget.onCleared();
    setState(() => _error = null);
  }

  @override
  Widget build(BuildContext context) {
    if (widget.current.isAttached) return _attachedView();

    return Container(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          _ModeSwitch(
            mode: _mode,
            onChanged: (m) => setState(() {
              _mode = m;
              _error = null;
            }),
          ),
          const SizedBox(height: 14),
          if (_mode == 0) ..._drawMode() else ..._uploadMode(),
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(
              _error!,
              style: GoogleFonts.inter(
                fontSize: 12,
                color: AppColors.error,
              ),
            ),
          ],
        ],
      ),
    );
  }

  List<Widget> _drawMode() => [
        Text(
          'Firma dentro del recuadro con tu dedo o un lápiz táctil.',
          style: GoogleFonts.inter(
              fontSize: 12, color: AppColors.textSecondary),
        ),
        const SizedBox(height: 10),
        ClipRRect(
          borderRadius: BorderRadius.circular(12),
          child: Container(
            decoration: BoxDecoration(
              border: Border.all(color: AppColors.border),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Signature(
              controller: _sigCtrl,
              height: 170,
              backgroundColor: AppColors.surface1,
            ),
          ),
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _busy ? null : () => _sigCtrl.clear(),
                icon: const Icon(Icons.refresh_rounded, size: 16),
                label: const Text('Limpiar'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AppColors.textPrimary,
                  side: const BorderSide(color: AppColors.border),
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12)),
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: FilledButton.icon(
                onPressed: _busy ? null : _useDrawnSignature,
                icon: _busy
                    ? const SizedBox(
                        width: 14,
                        height: 14,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: AppColors.onPrimary),
                      )
                    : const Icon(Icons.check_rounded, size: 16),
                label: const Text('Usar firma'),
                style: FilledButton.styleFrom(
                  backgroundColor: AppColors.dark,
                  foregroundColor: AppColors.onDark,
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12)),
                ),
              ),
            ),
          ],
        ),
      ];

  List<Widget> _uploadMode() => [
        Text(
          'Adjunta una foto o PDF del documento firmado.',
          style: GoogleFonts.inter(
              fontSize: 12, color: AppColors.textSecondary),
        ),
        const SizedBox(height: 12),
        DottedUploadButton(
          busy: _busy,
          onTap: _busy ? null : _pickSignedDocument,
        ),
      ];

  Widget _attachedView() {
    final isPdf = widget.current.kind == SignatureKind.uploadedPdf;
    final isImage = widget.current.kind == SignatureKind.uploadedImage;
    final path = widget.current.filePath!;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.surface0,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.primary.withValues(alpha: 0.5)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.verified_rounded,
                  color: AppColors.primary, size: 20),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  widget.current.label,
                  style: GoogleFonts.lexend(
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                    color: AppColors.textPrimary,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          if (isImage || widget.current.kind == SignatureKind.drawn)
            ClipRRect(
              borderRadius: BorderRadius.circular(12),
              child: Container(
                color: AppColors.surface1,
                width: double.infinity,
                constraints: const BoxConstraints(maxHeight: 160),
                child: Image.file(
                  File(path),
                  fit: BoxFit.contain,
                  errorBuilder: (_, _, _) => const Padding(
                    padding: EdgeInsets.all(24),
                    child: Icon(Icons.broken_image_outlined,
                        color: AppColors.textDisabled),
                  ),
                ),
              ),
            )
          else if (isPdf)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.symmetric(vertical: 20),
              decoration: BoxDecoration(
                color: AppColors.surface1,
                borderRadius: BorderRadius.circular(12),
              ),
              child: const Column(
                children: [
                  Icon(Icons.picture_as_pdf_rounded,
                      size: 34, color: AppColors.textSecondary),
                  SizedBox(height: 6),
                  Text('PDF adjuntado'),
                ],
              ),
            ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: _replace,
            icon: const Icon(Icons.swap_horiz_rounded, size: 16),
            label: const Text('Reemplazar firma'),
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.textPrimary,
              side: const BorderSide(color: AppColors.border),
              minimumSize: const Size.fromHeight(44),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12)),
            ),
          ),
        ],
      ),
    );
  }
}

class _ModeSwitch extends StatelessWidget {
  final int mode;
  final ValueChanged<int> onChanged;
  const _ModeSwitch({required this.mode, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: AppColors.surface1,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: [
          _seg('Firmar en pantalla', 0),
          _seg('Subir documento', 1),
        ],
      ),
    );
  }

  Widget _seg(String label, int idx) {
    final active = mode == idx;
    return Expanded(
      child: GestureDetector(
        onTap: () => onChanged(idx),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 180),
          padding: const EdgeInsets.symmetric(vertical: 9),
          decoration: BoxDecoration(
            color: active ? AppColors.dark : Colors.transparent,
            borderRadius: BorderRadius.circular(9),
          ),
          alignment: Alignment.center,
          child: Text(
            label,
            style: GoogleFonts.inter(
              fontSize: 12,
              fontWeight: FontWeight.w700,
              color: active ? AppColors.onDark : AppColors.textSecondary,
            ),
          ),
        ),
      ),
    );
  }
}

class DottedUploadButton extends StatelessWidget {
  final bool busy;
  final VoidCallback? onTap;
  const DottedUploadButton({super.key, required this.busy, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(vertical: 26),
        decoration: BoxDecoration(
          color: AppColors.surface1,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: AppColors.primary.withValues(alpha: 0.5),
          ),
        ),
        child: Column(
          children: [
            if (busy)
              const SizedBox(
                width: 22,
                height: 22,
                child: CircularProgressIndicator(
                    strokeWidth: 2.4,
                    valueColor:
                        AlwaysStoppedAnimation<Color>(AppColors.primary)),
              )
            else
              const Icon(Icons.upload_file_rounded,
                  size: 28, color: AppColors.textPrimary),
            const SizedBox(height: 8),
            Text(
              busy ? 'Adjuntando…' : 'Seleccionar archivo (PDF, PNG, JPG)',
              style: GoogleFonts.inter(
                fontSize: 12.5,
                fontWeight: FontWeight.w600,
                color: AppColors.textSecondary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
