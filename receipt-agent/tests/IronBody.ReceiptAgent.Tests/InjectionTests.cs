using System.Text;
using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Printing.EscPos;

namespace IronBody.ReceiptAgent.Tests;

/// <summary>
/// Garantía del contrato §2.2: el texto de un recibo nunca produce un comando. Se comprueba sobre el
/// codificador y el renderizador reales, sin pasar por la validación (que es una segunda barrera).
/// </summary>
public sealed class InjectionTests
{
    private static readonly string[] Hostile =
    {
        "\u001B@\u001Bd\u0009",          // ESC @, ESC d 9
        "\u001DV\u0000",                  // GS V 0 (corte)
        "\u0010\u0004\u0001",             // DLE EOT (consulta de estado)
        "\u001Cp\u0001\u0000",            // FS p (imagen NV)
        "a\0b\rc\nd\te\u000Cf",           // NUL, CR, LF, TAB, FF
        "\u007F\u0080\u0085\u009B\u009F", // DEL y C1 (CSI incluido)
        "😀👨\u200D👩\u200D👧 ‘hola’ “x” – — … \u00A0",
        "\uD800 suelta \uDC00",           // sustitutas sueltas (UTF-16 inválido)
        "\u202E\u200B\uFEFF\u2028\u2029", // formato invisible y separadores
    };

    public static TheoryData<string, string> HostileCases()
    {
        var data = new TheoryData<string, string>();
        foreach (var text in Hostile)
        {
            foreach (var codepage in new[] { "cp850", "cp437", "ascii" })
            {
                data.Add(text, codepage);
            }
        }

        return data;
    }

    [Theory]
    [MemberData(nameof(HostileCases))]
    public void Encoded_text_never_contains_a_control_byte(string text, string codepage)
    {
        var bytes = ReceiptTextEncoder.Encode(text, codepage);

        Assert.All(bytes, b => Assert.True(b >= 0x20 && b != 0x7F, $"byte 0x{b:X2} desde el texto"));
    }

    [Theory]
    [InlineData("cp850")]
    [InlineData("cp437")]
    [InlineData("ascii")]
    public void No_unicode_code_point_can_produce_a_control_byte(string codepage)
    {
        // Barrido completo de Unicode (sin sustitutas) en trozos: el codificador no tiene salida
        // posible por debajo de 0x20 ni 0x7F.
        var builder = new StringBuilder();
        for (var cp = 0; cp <= 0x10FFFF; cp++)
        {
            if (cp is >= 0xD800 and <= 0xDFFF)
            {
                continue;
            }

            builder.Append(char.ConvertFromUtf32(cp));
            if (builder.Length >= 8192 || cp == 0x10FFFF)
            {
                var bytes = ReceiptTextEncoder.Encode(builder.ToString(), codepage);
                var bad = Array.FindIndex(bytes, b => b < 0x20 || b == 0x7F);
                Assert.True(bad < 0, $"byte de control 0x{(bad < 0 ? 0 : bytes[bad]):X2} cerca de U+{cp:X4}");
                builder.Clear();
            }
        }
    }

    [Fact]
    public void Rendered_document_with_hostile_text_contains_only_the_renderer_commands()
    {
        var lines = Hostile.Select((t, i) => new PrintLine(t, (i % 3) switch { 0 => "normal", 1 => "bold", _ => "title" })).ToArray();
        var doc = new PrintDocument("RG-1", null, "original", 32, 3, false, lines);

        var bytes = EscPosRenderer.Render(doc, "cp850");

        // Se recorre la salida con la gramática exacta del §2.1: cabecera, (prefijo, texto, LF)*, final.
        var position = 0;
        ExpectSequence(bytes, ref position, new byte[] { 0x1B, 0x40, 0x1C, 0x2E, 0x1B, 0x74, 0x02, 0x1B, 0x4D, 0x00, 0x1B, 0x32 });
        foreach (var line in lines)
        {
            ExpectSequence(bytes, ref position, EscPosRenderer.LinePrefix(line.Style));
            while (bytes[position] != 0x0A)
            {
                Assert.True(bytes[position] >= 0x20 && bytes[position] != 0x7F, $"byte de control 0x{bytes[position]:X2} en el texto (posición {position})");
                position++;
            }

            position++; // LF del renderizador
        }

        ExpectSequence(bytes, ref position, new byte[] { 0x1B, 0x61, 0x00, 0x1B, 0x45, 0x00, 0x1D, 0x21, 0x00, 0x1B, 0x64, 0x03 });
        Assert.Equal(bytes.Length, position);
        EscPosRendererTests.AssertNoCutNorPageCommands(bytes);
    }

    [Fact]
    public void Validator_rejects_control_characters_before_they_reach_the_renderer()
    {
        foreach (var text in Hostile.Take(6))
        {
            var doc = new PrintDocument("RG-1", null, "original", 32, 4, false, new[] { new PrintLine(text, "normal") });
            Assert.NotNull(PrintDocumentValidator.FindProblem(doc, 32));
        }
    }

    private static void ExpectSequence(byte[] bytes, ref int position, byte[] expected)
    {
        Assert.True(position + expected.Length <= bytes.Length, "salida truncada");
        Assert.Equal(expected, bytes.Skip(position).Take(expected.Length).ToArray());
        position += expected.Length;
    }
}
