using System.Text;
using IronBody.ReceiptAgent.Printing.EscPos;

namespace IronBody.ReceiptAgent.Tests;

/// <summary>Codificación del contrato §2.2 con los bytes esperados de cada página.</summary>
public sealed class ReceiptTextEncoderTests
{
    public static TheoryData<string, string, byte> SpanishCharacters()
    {
        var data = new TheoryData<string, string, byte>();
        void Add(string ch, byte cp850, byte cp437, char ascii)
        {
            data.Add(ch, "cp850", cp850);
            data.Add(ch, "cp437", cp437);
            data.Add(ch, "ascii", (byte)ascii);
        }

        Add("á", 0xA0, 0xA0, 'a');
        Add("é", 0x82, 0x82, 'e');
        Add("í", 0xA1, 0xA1, 'i');
        Add("ó", 0xA2, 0xA2, 'o');
        Add("ú", 0xA3, 0xA3, 'u');
        Add("ñ", 0xA4, 0xA4, 'n');
        Add("Ñ", 0xA5, 0xA5, 'N');
        Add("¿", 0xA8, 0xA8, '?');
        Add("¡", 0xAD, 0xAD, '!');
        // CP437 no tiene Á Í Ó Ú: letra sin tilde. É sí existe (0x90).
        Add("Á", 0xB5, (byte)'A', 'A');
        Add("É", 0x90, 0x90, 'E');
        Add("Í", 0xD6, (byte)'I', 'I');
        Add("Ó", 0xE0, (byte)'O', 'O');
        Add("Ú", 0xE9, (byte)'U', 'U');
        Add("ü", 0x81, 0x81, 'u');
        Add("Ü", 0x9A, 0x9A, 'U');
        Add("$", 0x24, 0x24, '$');
        Add("·", 0xFA, 0xFA, '.');
        Add("°", 0xF8, 0xF8, '?');
        return data;
    }

    [Theory]
    [MemberData(nameof(SpanishCharacters))]
    public void Spanish_characters_map_to_the_expected_byte(string text, string codepage, byte expected)
    {
        Assert.Equal(new[] { expected }, ReceiptTextEncoder.Encode(text, codepage));
    }

    [Theory]
    [InlineData("cp850")]
    [InlineData("cp437")]
    [InlineData("ascii")]
    public void Typographic_characters_become_plain_ascii(string codepage)
    {
        Assert.Equal("'' \"\" -- ... x y"u8.ToArray(), ReceiptTextEncoder.Encode("‘’ “” –— … x\u00A0y", codepage));
    }

    [Theory]
    [InlineData("cp850")]
    [InlineData("cp437")]
    [InlineData("ascii")]
    public void Printable_ascii_passes_through_unchanged(string codepage)
    {
        var ascii = new string(Enumerable.Range(0x20, 0x7F - 0x20).Select(c => (char)c).ToArray());
        Assert.Equal(Encoding.ASCII.GetBytes(ascii), ReceiptTextEncoder.Encode(ascii, codepage));
    }

    [Fact]
    public void Decomposed_input_is_normalized_to_nfc_first()
    {
        Assert.Equal(new byte[] { 0x82 }, ReceiptTextEncoder.Encode("é", "cp850"));
    }

    [Theory]
    [InlineData("cp850")]
    [InlineData("cp437")]
    [InlineData("ascii")]
    public void Unmappable_characters_become_one_question_mark_each(string codepage)
    {
        Assert.Equal("a?b?c?"u8.ToArray(), ReceiptTextEncoder.Encode("a😀b€c中", codepage));
    }

    [Fact]
    public void Real_receipt_line_in_cp850()
    {
        var bytes = ReceiptTextEncoder.Encode("Suscripción Año ¡OK!", "cp850");
        var expected = Encoding.ASCII.GetBytes("Suscripci_n A_o _OK!");
        expected[9] = 0xA2;  // ó
        expected[13] = 0xA4; // ñ
        expected[16] = 0xAD; // ¡
        Assert.Equal(expected, bytes);
    }

    [Theory]
    [InlineData("cp850", 850)]
    [InlineData("cp437", 437)]
    public void Own_tables_match_the_reference_code_pages_byte_by_byte(string codepage, int codePageNumber)
    {
        // Solo en la prueba se usa el proveedor de .NET, como referencia independiente.
        Encoding.RegisterProvider(CodePagesEncodingProvider.Instance);
        var reference = Encoding.GetEncoding(codePageNumber);

        for (var b = 0x80; b <= 0xFF; b++)
        {
            var ch = reference.GetString(new[] { (byte)b });
            Assert.Equal(1, ch.Length);
            if (ch[0] == '\u00A0')
            {
                continue; // NBSP se imprime como espacio por regla tipográfica (§2.2).
            }

            Assert.True(ReceiptTextEncoder.Encode(ch, codepage).SequenceEqual(new[] { (byte)b }),
                $"{codepage}: U+{(int)ch[0]:X4} debería ser 0x{b:X2}");
        }
    }

    [Fact]
    public void Unknown_codepage_is_refused()
    {
        Assert.Throws<ArgumentOutOfRangeException>(() => ReceiptTextEncoder.Encode("x", "utf8"));
    }
}
