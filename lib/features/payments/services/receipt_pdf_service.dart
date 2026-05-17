import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/services.dart' show rootBundle;
import 'package:intl/intl.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:share_plus/share_plus.dart';

import '../../../core/utils/currency_formatter.dart';
import '../models/payment_record.dart';

/// Genera el comprobante de pago en PDF (recibo/extracto premium Iron Body).
/// Nunca incluye PAN/CVV/tokens/llaves: solo datos públicos del registro.
class ReceiptPdfService {
  ReceiptPdfService._();
  static final ReceiptPdfService instance = ReceiptPdfService._();

  static const _logoAsset =
      'assets/images/logo_para__documento-removebg-preview.png';

  static const _yellow = PdfColor.fromInt(0xFFFFD700);
  static const _black = PdfColor.fromInt(0xFF121212);
  static const _grey = PdfColor.fromInt(0xFF6B7280);
  static const _line = PdfColor.fromInt(0xFFE5E7EB);

  String fileName(PaymentRecord r) =>
      'comprobante_iron_body_${r.reference}.pdf';

  Future<Uint8List> build(PaymentRecord r) async {
    final doc = pw.Document();

    pw.MemoryImage? logo;
    try {
      final bytes = await rootBundle.load(_logoAsset);
      logo = pw.MemoryImage(
          bytes.buffer.asUint8List(bytes.offsetInBytes, bytes.lengthInBytes));
    } catch (_) {
      logo = null; // sin logo → encabezado de texto
    }

    final dt = r.dateTime ?? DateTime.now();
    final now = DateTime.now();
    final fecha = DateFormat('dd/MM/yyyy').format(dt);
    final hora = DateFormat('HH:mm').format(dt);
    final emision = DateFormat('dd/MM/yyyy HH:mm').format(now);

    final statusColor = r.isApproved
        ? const PdfColor.fromInt(0xFF1FA463)
        : r.isFailed
            ? const PdfColor.fromInt(0xFFD64545)
            : const PdfColor.fromInt(0xFFB07A00);

    String v(String? s) =>
        (s != null && s.trim().isNotEmpty) ? s.trim() : 'No disponible';

    doc.addPage(
      pw.Page(
        pageFormat: PdfPageFormat.a4,
        margin: const pw.EdgeInsets.all(36),
        build: (context) => pw.Column(
          crossAxisAlignment: pw.CrossAxisAlignment.start,
          children: [
            // ── Franja negra superior: logo GRANDE directo sobre el negro ──
            pw.Container(
              width: double.infinity,
              height: 96,
              padding:
                  const pw.EdgeInsets.symmetric(horizontal: 24, vertical: 14),
              decoration: pw.BoxDecoration(
                color: _black,
                borderRadius: pw.BorderRadius.circular(14),
              ),
              child: pw.Row(
                crossAxisAlignment: pw.CrossAxisAlignment.center,
                children: [
                  // Logo grande, protagonista, SIN caja blanca, sobre el negro.
                  if (logo != null)
                    pw.SizedBox(
                      height: 68,
                      width: 110,
                      child: pw.Image(logo, fit: pw.BoxFit.contain),
                    )
                  else
                    pw.Text('IB',
                        style: pw.TextStyle(
                            color: _yellow,
                            fontSize: 40,
                            fontWeight: pw.FontWeight.bold)),
                  pw.SizedBox(width: 20),
                  pw.Expanded(
                    child: pw.Column(
                      mainAxisAlignment: pw.MainAxisAlignment.center,
                      crossAxisAlignment: pw.CrossAxisAlignment.start,
                      children: [
                        pw.RichText(
                          text: pw.TextSpan(children: [
                            pw.TextSpan(
                                text: 'IRON ',
                                style: pw.TextStyle(
                                    color: PdfColors.white,
                                    fontSize: 24,
                                    fontWeight: pw.FontWeight.bold,
                                    letterSpacing: 1.5)),
                            pw.TextSpan(
                                text: 'BODY',
                                style: pw.TextStyle(
                                    color: _yellow,
                                    fontSize: 24,
                                    fontWeight: pw.FontWeight.bold,
                                    letterSpacing: 1.5)),
                          ]),
                        ),
                        pw.SizedBox(height: 4),
                        pw.Text('Centro de Acondicionamiento Físico',
                            style: pw.TextStyle(
                                color: PdfColor.fromInt(0xFFBDBDBD),
                                fontSize: 9.5)),
                      ],
                    ),
                  ),
                  pw.Container(width: 5, height: 52, color: _yellow),
                ],
              ),
            ),
            pw.SizedBox(height: 20),

            // ── Título + estado + emisión ──────────────────────────────
            pw.Row(
              crossAxisAlignment: pw.CrossAxisAlignment.start,
              mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
              children: [
                pw.Column(
                  crossAxisAlignment: pw.CrossAxisAlignment.start,
                  children: [
                    pw.Text('COMPROBANTE DE PAGO',
                        style: pw.TextStyle(
                            fontSize: 21,
                            letterSpacing: 1.2,
                            fontWeight: pw.FontWeight.bold,
                            color: _black)),
                    pw.SizedBox(height: 5),
                    pw.Text('Iron Body Centro de Acondicionamiento Físico',
                        style: pw.TextStyle(fontSize: 10.5, color: _grey)),
                    pw.SizedBox(height: 8),
                    pw.Container(height: 3, width: 64, color: _yellow),
                  ],
                ),
                pw.Column(
                  crossAxisAlignment: pw.CrossAxisAlignment.end,
                  children: [
                    _statusBadge(r),
                    pw.SizedBox(height: 10),
                    pw.Text('Fecha de emisión',
                        style: pw.TextStyle(fontSize: 8, color: _grey)),
                    pw.Text(emision,
                        style: pw.TextStyle(
                            fontSize: 10,
                            color: _black,
                            fontWeight: pw.FontWeight.bold)),
                  ],
                ),
              ],
            ),
            pw.SizedBox(height: 22),

            // ── Bloque cliente + bloque transacción ────────────────────
            pw.Row(
              crossAxisAlignment: pw.CrossAxisAlignment.start,
              children: [
                pw.Expanded(
                  child: _block('CLIENTE', [
                    ('Nombre', v(r.userName)),
                    ('Documento', v(r.document)),
                    ('Correo', v(r.email)),
                    ('Teléfono', v(r.phone)),
                  ]),
                ),
                pw.SizedBox(width: 16),
                pw.Expanded(
                  child: _block('TRANSACCIÓN', [
                    ('Referencia Iron Body', v(r.reference)),
                    ('Ref ePayco', v(r.providerRef)),
                    ('Método de pago', r.methodLabel),
                    ('Estado', r.statusLabel),
                    ('Fecha y hora', '$fecha · $hora'),
                    if (r.membershipExpiry != null)
                      ('Vence membresía', v(r.membershipExpiry)),
                  ]),
                ),
              ],
            ),
            pw.SizedBox(height: 16),
            _block('DETALLE', [
              ('Plan / Producto', v(r.product ?? r.description)),
              ('Descripción', v(r.description)),
              ('Moneda', r.currency),
              if (!r.isApproved && (r.reason?.trim().isNotEmpty ?? false))
                ('Observación', r.reason!.trim()),
            ]),
            pw.SizedBox(height: 22),

            // ── Tabla resumen ──────────────────────────────────────────
            _summaryTable(r, fecha, statusColor),
            pw.SizedBox(height: 14),
            pw.Container(
              alignment: pw.Alignment.centerRight,
              child: pw.Column(
                crossAxisAlignment: pw.CrossAxisAlignment.end,
                children: [
                  pw.Text('TOTAL PAGADO',
                      style: pw.TextStyle(
                          fontSize: 9,
                          color: _grey,
                          fontWeight: pw.FontWeight.bold)),
                  pw.SizedBox(height: 2),
                  pw.Text(
                      '${CurrencyFormatter.format(r.amount)} ${r.currency}',
                      style: pw.TextStyle(
                          fontSize: 22,
                          fontWeight: pw.FontWeight.bold,
                          color: _black)),
                ],
              ),
            ),

            pw.Spacer(),

            // ── Referencia visual (código de barras) ───────────────────
            pw.Center(child: _barcode(r.reference)),
            pw.SizedBox(height: 4),
            pw.Center(
              child: pw.Text(r.reference,
                  style: pw.TextStyle(
                      fontSize: 9,
                      letterSpacing: 3,
                      color: _grey)),
            ),
            pw.SizedBox(height: 16),
            pw.Container(height: 1, color: _line),
            pw.SizedBox(height: 10),
            pw.Text(
              'Este comprobante corresponde al registro de una transacción '
              'procesada por ePayco para Iron Body.',
              style: pw.TextStyle(fontSize: 8.5, color: _grey),
            ),
            pw.SizedBox(height: 3),
            pw.Text(
              'Documento generado automáticamente por el sistema Iron Body.',
              style: pw.TextStyle(fontSize: 8.5, color: _grey),
            ),
          ],
        ),
      ),
    );

    return doc.save();
  }

  /// Badge de estado profesional: fondo suave, texto fuerte, ícono dibujado
  /// (check / x / punto) y bordes redondeados. Coherente para todos los estados.
  pw.Widget _statusBadge(PaymentRecord r) {
    final (PdfColor fg, PdfColor bg, String kind) = r.isApproved
        ? (
            const PdfColor.fromInt(0xFF1F9D5B),
            const PdfColor.fromInt(0xFFE6F6EE),
            'check'
          )
        : r.isFailed
            ? (
                const PdfColor.fromInt(0xFFD64545),
                const PdfColor.fromInt(0xFFFDEAEA),
                'x'
              )
            : (
                const PdfColor.fromInt(0xFFB07A00),
                const PdfColor.fromInt(0xFFFFF3DF),
                'dot'
              );

    return pw.Container(
      padding:
          const pw.EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: pw.BoxDecoration(
        color: bg,
        borderRadius: pw.BorderRadius.circular(20),
        border: pw.Border.all(color: fg, width: 0.8),
      ),
      child: pw.Row(
        mainAxisSize: pw.MainAxisSize.min,
        children: [
          pw.CustomPaint(
            size: const PdfPoint(11, 11),
            painter: (canvas, size) {
              canvas
                ..setLineWidth(1.4)
                ..setStrokeColor(fg)
                ..setLineCap(PdfLineCap.round);
              if (kind == 'check') {
                canvas
                  ..moveTo(1.5, 5.5)
                  ..lineTo(4.3, 2.6)
                  ..lineTo(9.5, 8.4)
                  ..strokePath();
              } else if (kind == 'x') {
                canvas
                  ..moveTo(2, 2)
                  ..lineTo(9, 9)
                  ..moveTo(9, 2)
                  ..lineTo(2, 9)
                  ..strokePath();
              } else {
                canvas
                  ..setFillColor(fg)
                  ..drawEllipse(5.5, 5.5, 2.6, 2.6)
                  ..fillPath();
              }
            },
          ),
          pw.SizedBox(width: 7),
          pw.Text(r.statusLabel.toUpperCase(),
              style: pw.TextStyle(
                  color: fg,
                  fontSize: 9.5,
                  letterSpacing: 0.5,
                  fontWeight: pw.FontWeight.bold)),
        ],
      ),
    );
  }

  /// Bloque con título de sección y filas etiqueta/valor.
  pw.Widget _block(String title, List<(String, String)> data) {
    return pw.Container(
      padding: const pw.EdgeInsets.fromLTRB(16, 14, 16, 14),
      decoration: pw.BoxDecoration(
        color: const PdfColor.fromInt(0xFFFBFBFB),
        borderRadius: pw.BorderRadius.circular(10),
        border: pw.Border.all(color: _line),
      ),
      child: pw.Column(
        crossAxisAlignment: pw.CrossAxisAlignment.start,
        children: [
          pw.Row(children: [
            pw.Container(width: 4, height: 12, color: _yellow),
            pw.SizedBox(width: 8),
            pw.Text(title,
                style: pw.TextStyle(
                    fontSize: 10,
                    letterSpacing: 1,
                    fontWeight: pw.FontWeight.bold,
                    color: _black)),
          ]),
          pw.SizedBox(height: 10),
          for (final (k, val) in data)
            pw.Padding(
              padding: const pw.EdgeInsets.symmetric(vertical: 4),
              child: pw.Row(
                crossAxisAlignment: pw.CrossAxisAlignment.start,
                children: [
                  pw.Expanded(
                    flex: 5,
                    child: pw.Text(k,
                        style: pw.TextStyle(fontSize: 9, color: _grey)),
                  ),
                  pw.SizedBox(width: 8),
                  pw.Expanded(
                    flex: 6,
                    child: pw.Text(val,
                        textAlign: pw.TextAlign.right,
                        style: pw.TextStyle(
                            fontSize: 10,
                            color: _black,
                            fontWeight: pw.FontWeight.bold)),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  /// Tabla resumen: Fecha | Descripción | Método | Estado | Valor.
  pw.Widget _summaryTable(
      PaymentRecord r, String fecha, PdfColor statusColor) {
    pw.Widget cell(String t,
        {bool header = false,
        PdfColor? color,
        pw.TextAlign align = pw.TextAlign.left}) {
      return pw.Padding(
        padding: const pw.EdgeInsets.symmetric(horizontal: 8, vertical: 8),
        child: pw.Text(t,
            textAlign: align,
            style: pw.TextStyle(
                fontSize: header ? 8.5 : 9.5,
                color: color ?? (header ? PdfColors.white : _black),
                fontWeight:
                    header ? pw.FontWeight.bold : pw.FontWeight.normal)),
      );
    }

    return pw.Table(
      border: pw.TableBorder.all(color: _line, width: 0.8),
      columnWidths: {
        0: const pw.FlexColumnWidth(2.2),
        1: const pw.FlexColumnWidth(3.4),
        2: const pw.FlexColumnWidth(2),
        3: const pw.FlexColumnWidth(2),
        4: const pw.FlexColumnWidth(2.4),
      },
      children: [
        pw.TableRow(
          decoration: const pw.BoxDecoration(color: _black),
          children: [
            cell('FECHA', header: true),
            cell('DESCRIPCIÓN', header: true),
            cell('MÉTODO', header: true),
            cell('ESTADO', header: true),
            cell('VALOR', header: true, align: pw.TextAlign.right),
          ],
        ),
        pw.TableRow(
          children: [
            cell(fecha),
            cell(r.product ?? r.description ?? 'No disponible'),
            cell(r.methodLabel),
            cell(r.statusLabel, color: statusColor),
            cell(CurrencyFormatter.format(r.amount),
                align: pw.TextAlign.right),
          ],
        ),
      ],
    );
  }

  /// Código de barras visual determinista (decorativo; no codifica secretos).
  pw.Widget _barcode(String value) {
    var seed = 0;
    for (final c in value.codeUnits) {
      seed = (seed * 31 + c) & 0x7fffffff;
    }
    final bars = List.generate(54, (i) {
      final x = (seed + i * 2654435761) & 0x7fffffff;
      return (x % 100) > 62 ? 2.6 : 1.3;
    });
    return pw.Row(
      mainAxisAlignment: pw.MainAxisAlignment.center,
      crossAxisAlignment: pw.CrossAxisAlignment.center,
      children: [
        for (final w in bars) ...[
          pw.Container(width: w, height: 44, color: _black),
          pw.SizedBox(width: 1.6),
        ],
      ],
    );
  }

  /// Genera y guarda el PDF. Intenta primero la carpeta pública de Descargas
  /// (Android); si el sistema no lo permite (scoped storage / sin permiso),
  /// usa el directorio de documentos de la app. Sin solicitar permisos.
  /// Devuelve la ruta real donde quedó guardado.
  Future<String> saveToFile(PaymentRecord r) async {
    final bytes = await build(r);
    final name = fileName(r);

    // 1) Mejor esfuerzo: carpeta pública Descargas (si el SO lo permite).
    if (Platform.isAndroid) {
      try {
        final dl = Directory('/storage/emulated/0/Download');
        if (await dl.exists()) {
          final path = '${dl.path}/$name';
          await File(path).writeAsBytes(bytes, flush: true);
          return path;
        }
      } catch (_) {
        // Scoped storage / sin permiso → se usa el almacenamiento de la app.
      }
    }

    // 2) Fallback fiable: documentos privados de la app (sin permisos).
    final dir = await getApplicationDocumentsDirectory();
    final receipts = Directory('${dir.path}/comprobantes');
    if (!await receipts.exists()) {
      await receipts.create(recursive: true);
    }
    final path = '${receipts.path}/$name';
    await File(path).writeAsBytes(bytes, flush: true);
    return path;
  }

  /// Abre el share sheet nativo con el comprobante PDF.
  Future<void> share(PaymentRecord r) async {
    final bytes = await build(r);
    final dir = await getTemporaryDirectory();
    final path = '${dir.path}/${fileName(r)}';
    await File(path).writeAsBytes(bytes, flush: true);
    await Share.shareXFiles(
      [XFile(path, mimeType: 'application/pdf', name: fileName(r))],
      subject: 'Comprobante Iron Body ${r.reference}',
      text: 'Comprobante de pago Iron Body',
    );
  }

  Future<void> open(String path) => OpenFilex.open(path);
}
