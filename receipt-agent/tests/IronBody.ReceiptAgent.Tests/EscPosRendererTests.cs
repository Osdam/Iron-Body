using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Printing.EscPos;

namespace IronBody.ReceiptAgent.Tests;

/// <summary>Bytes exactos del contrato §2.1: cabecera, estilos por línea y final.</summary>
public sealed class EscPosRendererTests
{
    private static readonly byte[] NormalPrefix = { 0x1B, 0x61, 0x00, 0x1B, 0x45, 0x00, 0x1D, 0x21, 0x00 };
    private static readonly byte[] BoldPrefix = { 0x1B, 0x61, 0x00, 0x1B, 0x45, 0x01, 0x1D, 0x21, 0x00 };
    private static readonly byte[] TitlePrefix = { 0x1B, 0x61, 0x01, 0x1B, 0x45, 0x01, 0x1D, 0x21, 0x01 };

    internal static PrintDocument Doc(int columns = 32, int feedLines = 4, params PrintLine[] lines) =>
        new("RG-000123", null, "original", columns, feedLines, false, lines.Length == 0 ? new[] { new PrintLine("OK", "normal") } : lines);

    [Theory]
    [InlineData("cp850", 32, 0x02, 0x00)]
    [InlineData("cp437", 32, 0x00, 0x00)]
    [InlineData("ascii", 32, 0x00, 0x00)]
    [InlineData("cp850", 42, 0x02, 0x01)]
    [InlineData("cp437", 42, 0x00, 0x01)]
    public void Header_is_exactly_init_cancel_chinese_codepage_font_and_spacing(string codepage, int columns, byte table, byte font)
    {
        var bytes = EscPosRenderer.Render(Doc(columns), codepage);

        byte[] expected = { 0x1B, 0x40, 0x1C, 0x2E, 0x1B, 0x74, table, 0x1B, 0x4D, font, 0x1B, 0x32 };
        Assert.Equal(expected, bytes.Take(expected.Length).ToArray());
    }

    [Fact]
    public void Whole_document_is_header_plus_styled_lines_plus_footer_and_nothing_else()
    {
        var doc = Doc(32, 4, new PrintLine("IRON BODY", "title"), new PrintLine("Recibo", "bold"), new PrintLine("", "normal"), new PrintLine("Fin", "normal"));

        var bytes = EscPosRenderer.Render(doc, "cp850");

        var expected = new List<byte> { 0x1B, 0x40, 0x1C, 0x2E, 0x1B, 0x74, 0x02, 0x1B, 0x4D, 0x00, 0x1B, 0x32 };
        expected.AddRange(TitlePrefix);
        expected.AddRange("IRON BODY"u8.ToArray());
        expected.Add(0x0A);
        expected.AddRange(BoldPrefix);
        expected.AddRange("Recibo"u8.ToArray());
        expected.Add(0x0A);
        expected.AddRange(NormalPrefix);
        expected.Add(0x0A);
        expected.AddRange(NormalPrefix);
        expected.AddRange("Fin"u8.ToArray());
        expected.Add(0x0A);
        expected.AddRange(new byte[] { 0x1B, 0x61, 0x00, 0x1B, 0x45, 0x00, 0x1D, 0x21, 0x00, 0x1B, 0x64, 0x04 });

        Assert.Equal(expected.ToArray(), bytes);
    }

    [Theory]
    [InlineData(0)]
    [InlineData(1)]
    [InlineData(4)]
    [InlineData(8)]
    public void Footer_resets_style_and_feeds_exactly_feed_lines(int feedLines)
    {
        var bytes = EscPosRenderer.Render(Doc(32, feedLines), "cp850");

        byte[] footer = { 0x1B, 0x61, 0x00, 0x1B, 0x45, 0x00, 0x1D, 0x21, 0x00, 0x1B, 0x64, (byte)feedLines };
        Assert.Equal(footer, bytes.TakeLast(footer.Length).ToArray());
    }

    [Theory]
    [InlineData(-1)]
    [InlineData(9)]
    public void Feed_lines_out_of_range_is_refused(int feedLines)
    {
        Assert.Throws<ArgumentOutOfRangeException>(() => EscPosRenderer.Render(Doc(32, feedLines), "cp850"));
    }

    [Fact]
    public void Cut_is_refused_in_protocol_1()
    {
        var doc = Doc() with { Cut = true };
        Assert.Throws<ArgumentException>(() => EscPosRenderer.Render(doc, "cp850"));
    }

    [Fact]
    public void Unknown_style_is_refused_by_the_renderer_too()
    {
        Assert.Throws<ArgumentOutOfRangeException>(() => EscPosRenderer.Render(Doc(32, 4, new PrintLine("x", "italic")), "cp850"));
    }

    [Theory]
    [InlineData(1)]
    [InlineData(5)]
    [InlineData(20)]
    public void Receipt_length_is_proportional_to_its_lines_without_padding_or_page_commands(int products)
    {
        var doc = Receipt(products);

        var bytes = EscPosRenderer.Render(doc, "cp850");

        // 12 de cabecera + (9 de estilo + texto + LF) por línea + 12 de final. Ni un byte más.
        var expectedLength = 12 + doc.Lines.Sum(l => 9 + l.Text.Length + 1) + 12;
        Assert.Equal(expectedLength, bytes.Length);
        Assert.Equal(doc.Lines.Count, bytes.Count(b => b == 0x0A));
        AssertNoCutNorPageCommands(bytes);
    }

    [Fact]
    public void Each_extra_product_adds_exactly_one_line_of_bytes()
    {
        var five = EscPosRenderer.Render(Receipt(5), "cp850").Length;
        var twenty = EscPosRenderer.Render(Receipt(20), "cp850").Length;

        Assert.Equal(15 * (9 + 32 + 1), twenty - five);
    }

    [Fact]
    public void Largest_valid_document_fits_in_the_32_KB_printer_buffer()
    {
        var lines = Enumerable.Range(0, 400).Select(_ => new PrintLine(new string('W', 42), "title")).ToArray();

        var bytes = EscPosRenderer.Render(Doc(42, 8, lines), "cp850");

        Assert.True(bytes.Length < 32 * 1024, $"{bytes.Length} bytes");
    }

    [Fact]
    public void Title_line_uses_double_height_only_so_width_stays_the_same()
    {
        Assert.Equal(TitlePrefix, EscPosRenderer.LinePrefix("title"));
        // GS ! 0x01 = doble ALTO; doble ANCHO sería 0x10 y partiría las líneas de 32 columnas.
        Assert.Equal(0x01, EscPosRenderer.LinePrefix("title")[8]);
    }

    internal static PrintDocument Receipt(int products)
    {
        var lines = new List<PrintLine>
        {
            new("IRON BODY WORKOUT", "title"),
            new("Recibo              RG-000123", "bold"),
            new("Fecha       17/10/2026 08:15", "normal"),
            new(new string('-', 32), "normal"),
        };
        for (var i = 1; i <= products; i++)
        {
            lines.Add(new PrintLine($"Producto {i,3}".PadRight(22) + "$ 12.345".PadLeft(10), "normal"));
        }

        lines.Add(new PrintLine(new string('-', 32), "normal"));
        lines.Add(new PrintLine("TOTAL".PadRight(20) + "$ 123.456".PadLeft(12), "bold"));
        return new PrintDocument("RG-000123", null, "original", 32, 4, false, lines);
    }

    internal static void AssertNoCutNorPageCommands(byte[] bytes)
    {
        for (var i = 0; i < bytes.Length - 1; i++)
        {
            Assert.False(bytes[i] == 0x1D && bytes[i + 1] == 0x56, $"GS V (corte) en la posición {i}");
            Assert.False(bytes[i] == 0x1B && bytes[i + 1] == 0x4C, $"ESC L (modo página) en la posición {i}");
            Assert.False(bytes[i] == 0x1D && bytes[i + 1] == 0x76, $"GS v (raster) en la posición {i}");
            Assert.False(bytes[i] == 0x1B && bytes[i + 1] == 0x2A, $"ESC * (imagen) en la posición {i}");
        }

        Assert.DoesNotContain((byte)0x0C, bytes); // FF: avance de página
    }
}
