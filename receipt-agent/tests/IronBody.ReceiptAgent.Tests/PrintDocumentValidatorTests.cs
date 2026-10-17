using IronBody.ReceiptAgent.Printing;

namespace IronBody.ReceiptAgent.Tests;

/// <summary>Reglas del §2 que el servicio verifica: nunca recorta, rechaza.</summary>
public sealed class PrintDocumentValidatorTests
{
    private static PrintDocument Doc(params PrintLine[] lines) => new("RG-000123", "REIMPRESIÓN", "reprint", 32, 4, false, lines);

    private static PrintLine Line(string text, string style = "normal") => new(text, style);

    [Fact]
    public void Valid_document_passes()
    {
        Assert.Null(PrintDocumentValidator.FindProblem(Doc(Line("IRON BODY", "title"), Line(""), Line(new string('x', 32), "bold")), 32));
    }

    [Fact]
    public void Line_longer_than_columns_is_rejected_without_quoting_its_text()
    {
        var secret = "CLIENTE ANA MARIA PEREZ GOMEZ 123"; // 33 caracteres
        var problem = PrintDocumentValidator.FindProblem(Doc(Line("ok"), Line(secret)), 32);

        Assert.Equal("línea 2: 33 caracteres, el máximo es 32", problem);
        Assert.DoesNotContain("ANA", problem);
    }

    [Fact]
    public void Length_is_counted_in_code_points_not_utf16_units()
    {
        // 31 + un emoji (2 unidades UTF-16) = 32 puntos de código: cabe.
        Assert.Null(PrintDocumentValidator.FindProblem(Doc(Line(new string('x', 31) + "😀")), 32));
        Assert.NotNull(PrintDocumentValidator.FindProblem(Doc(Line(new string('x', 32) + "😀")), 32));
    }

    [Fact]
    public void Forty_two_columns_allow_forty_two_characters()
    {
        var doc = Doc(Line(new string('x', 42))) with { Columns = 42 };
        Assert.Null(PrintDocumentValidator.FindProblem(doc, 42));
        Assert.NotNull(PrintDocumentValidator.FindProblem(doc with { Lines = new[] { Line(new string('x', 43)) } }, 42));
    }

    [Theory]
    [InlineData("italic")]
    [InlineData("Bold")]
    [InlineData("")]
    public void Unknown_style_is_rejected(string style)
    {
        Assert.Equal("línea 1: estilo desconocido", PrintDocumentValidator.FindProblem(Doc(Line("x", style)), 32));
    }

    [Theory]
    [InlineData(0)]
    [InlineData(401)]
    public void Line_count_outside_1_to_400_is_rejected(int count)
    {
        var problem = PrintDocumentValidator.FindProblem(Doc(Enumerable.Repeat(Line("x"), count).ToArray()), 32);
        Assert.Equal($"el recibo debe tener entre 1 y 400 líneas (recibidas {count})", problem);
    }

    [Fact]
    public void Four_hundred_lines_are_accepted()
    {
        Assert.Null(PrintDocumentValidator.FindProblem(Doc(Enumerable.Repeat(Line("x"), 400).ToArray()), 32));
    }

    [Fact]
    public void Job_columns_must_match_the_station_columns()
    {
        var doc = Doc(Line("x")) with { Columns = 42 };
        Assert.Equal("el trabajo viene a 42 columnas y la estación está configurada a 32", PrintDocumentValidator.FindProblem(doc, 32));
    }

    [Theory]
    [InlineData(40)]
    [InlineData(48)]
    [InlineData(0)]
    public void Columns_other_than_32_or_42_are_rejected(int columns)
    {
        Assert.NotNull(PrintDocumentValidator.FindProblem(Doc(Line("x")) with { Columns = columns }, columns));
    }

    [Theory]
    [InlineData("\u0000")]
    [InlineData("\u001B")]
    [InlineData("\u001F")]
    [InlineData("\u007F")]
    [InlineData("\u0085")]
    [InlineData("\u009F")]
    [InlineData("\t")]
    [InlineData("\r")]
    [InlineData("\n")]
    public void Control_characters_are_rejected(string control)
    {
        Assert.Equal("línea 1: contiene caracteres de control", PrintDocumentValidator.FindProblem(Doc(Line("a" + control + "b")), 32));
    }

    [Fact]
    public void Nbsp_and_typographic_characters_are_not_controls()
    {
        Assert.Null(PrintDocumentValidator.FindProblem(Doc(Line("a\u00A0b ‘c’ “d” – — …")), 32));
    }

    [Fact]
    public void Non_nfc_text_is_rejected()
    {
        Assert.Equal("línea 1: el texto no está en NFC", PrintDocumentValidator.FindProblem(Doc(Line("Pago é")), 32));
    }

    [Theory]
    [InlineData(0xFFFE)]
    [InlineData(0xFFFF)]
    [InlineData(0xFDD0)]
    [InlineData(0x1FFFF)]
    public void Unicode_noncharacters_are_rejected_instead_of_crashing_normalization(int codePoint)
    {
        var text = "a" + char.ConvertFromUtf32(codePoint) + "b";
        Assert.Equal("línea 1: contiene caracteres no válidos", PrintDocumentValidator.FindProblem(Doc(Line(text)), 32));
    }

    [Fact]
    public void Lone_surrogate_is_rejected()
    {
        Assert.Equal("línea 1: texto con UTF-16 inválido", PrintDocumentValidator.FindProblem(Doc(Line("a\uD800b")), 32));
    }

    [Theory]
    [InlineData(-1)]
    [InlineData(9)]
    public void Feed_lines_outside_0_to_8_are_rejected(int feedLines)
    {
        Assert.NotNull(PrintDocumentValidator.FindProblem(Doc(Line("x")) with { FeedLines = feedLines }, 32));
    }

    [Fact]
    public void Cut_true_is_rejected_in_protocol_1()
    {
        Assert.Equal("cut debe ser false en el protocolo 1", PrintDocumentValidator.FindProblem(Doc(Line("x")) with { Cut = true }, 32));
    }

    [Fact]
    public void Unknown_kind_is_rejected()
    {
        Assert.Equal("kind desconocido", PrintDocumentValidator.FindProblem(Doc(Line("x")) with { Kind = "copy" }, 32));
    }

    [Fact]
    public void Receipt_number_with_controls_is_rejected()
    {
        Assert.NotNull(PrintDocumentValidator.FindProblem(Doc(Line("x")) with { ReceiptNumber = "RG-1\u001B" }, 32));
    }
}
