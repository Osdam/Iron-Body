namespace IronBody.ReceiptAgent.Printing.EscPos;

/// <summary>
/// Documento → bytes ESC/POS, exactamente los del contrato §2.1 y ningún otro comando. Sin raster,
/// sin imágenes, sin comandos de página: el papel mide lo que miden las líneas más el avance final,
/// y el trabajo entero ocupa unos pocos KB frente a los 32 KB de búfer de la DIG-58IIK.
/// </summary>
public static class EscPosRenderer
{
    private const byte Esc = 0x1B;
    private const byte Fs = 0x1C;
    private const byte Gs = 0x1D;
    private const byte Lf = 0x0A;

    public static byte[] Render(PrintDocument document, string codepage)
    {
        if (document.Cut)
        {
            // La DIG-58IIK no declara cortador y v1 nunca corta (contrato §1); el validador ya lo
            // rechaza, esto impide que otro llamador lo cuele.
            throw new ArgumentException("v1 no admite corte (cut=true).", nameof(document));
        }

        var fontSelector = document.Columns switch
        {
            32 => (byte)0, // Fuente A, 12×24
            42 => (byte)1, // Fuente B, 9×17
            _ => throw new ArgumentOutOfRangeException(nameof(document), document.Columns, "Solo 32 o 42 columnas."),
        };

        if (document.FeedLines is < 0 or > 8)
        {
            throw new ArgumentOutOfRangeException(nameof(document), document.FeedLines, "feed_lines debe estar entre 0 y 8.");
        }

        var output = new List<byte>(256 + document.Lines.Count * (document.Columns + 10));

        // Cabecera.
        output.AddRange(new byte[] { Esc, 0x40 });                                    // ESC @  inicializar
        output.AddRange(new byte[] { Fs, 0x2E });                                     // FS .   cancelar modo chino
        output.AddRange(new byte[] { Esc, 0x74, TextCodepages.EscPosTable(codepage) }); // ESC t n página de códigos
        output.AddRange(new byte[] { Esc, 0x4D, fontSelector });                      // ESC M f fuente
        output.AddRange(new byte[] { Esc, 0x32 });                                    // ESC 2  interlineado por defecto

        foreach (var line in document.Lines)
        {
            output.AddRange(LinePrefix(line.Style));
            ReceiptTextEncoder.EncodeTo(line.Text, codepage, output);
            output.Add(Lf);
        }

        // Final: vuelve a normal y avanza feed_lines líneas para que el recibo salga de la barra de corte.
        output.AddRange(new byte[] { Esc, 0x61, 0x00, Esc, 0x45, 0x00, Gs, 0x21, 0x00 });
        output.AddRange(new byte[] { Esc, 0x64, (byte)document.FeedLines });

        return output.ToArray();
    }

    /// <summary>
    /// Cada línea fija alineación, negrita y tamaño completos, así que una línea nunca hereda el
    /// estilo de la anterior (el título «vuelve a normal» con el prefijo de la siguiente o el final).
    /// </summary>
    internal static byte[] LinePrefix(string style) => style switch
    {
        LineStyles.Normal => new byte[] { Esc, 0x61, 0x00, Esc, 0x45, 0x00, Gs, 0x21, 0x00 },
        LineStyles.Bold => new byte[] { Esc, 0x61, 0x00, Esc, 0x45, 0x01, Gs, 0x21, 0x00 },
        // Centrado por la impresora, negrita y doble ALTO (GS ! 0x01): el ancho no cambia.
        LineStyles.Title => new byte[] { Esc, 0x61, 0x01, Esc, 0x45, 0x01, Gs, 0x21, 0x01 },
        _ => throw new ArgumentOutOfRangeException(nameof(style), style, "Estilo de línea desconocido."),
    };
}
