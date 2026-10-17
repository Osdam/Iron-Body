using System.Globalization;
using System.Text;

namespace IronBody.ReceiptAgent.Printing.EscPos;

/// <summary>Páginas de código admitidas (contrato §1) y su número para ESC t.</summary>
public static class TextCodepages
{
    public const string Cp850 = "cp850";
    public const string Cp437 = "cp437";
    public const string Ascii = "ascii";

    public static bool IsKnown(string? codepage) => codepage is Cp850 or Cp437 or Ascii;

    /// <summary>n de ESC t n (contrato §2.1): cp850→2, cp437→0, ascii→0.</summary>
    public static byte EscPosTable(string codepage) => codepage switch
    {
        Cp850 => 2,
        Cp437 => 0,
        Ascii => 0,
        _ => throw new ArgumentOutOfRangeException(nameof(codepage), codepage, "Página de códigos desconocida."),
    };
}

/// <summary>
/// Texto → bytes de la impresora (contrato §2.2). Garantía de seguridad: cada byte que sale de aquí es
/// imprimible (0x20–0x7E o ≥ 0x80 de la tabla); un control nunca sale, así que el texto de un recibo
/// no puede convertirse en un comando ESC/POS aunque la validación previa fallara.
/// </summary>
public static class ReceiptTextEncoder
{
    private const byte Question = (byte)'?';

    private static readonly Dictionary<char, byte> Cp850Reverse = CodePageTables.BuildReverse(CodePageTables.Cp850High);
    private static readonly Dictionary<char, byte> Cp437Reverse = CodePageTables.BuildReverse(CodePageTables.Cp437High);

    public static byte[] Encode(string text, string codepage)
    {
        var output = new List<byte>(text.Length + 8);
        EncodeTo(text, codepage, output);
        return output.ToArray();
    }

    public static void EncodeTo(string text, string codepage, List<byte> output)
    {
        if (!TextCodepages.IsKnown(codepage))
        {
            throw new ArgumentOutOfRangeException(nameof(codepage), codepage, "Página de códigos desconocida.");
        }

        foreach (var rune in ToNfc(text).EnumerateRunes())
        {
            EncodeRune(rune, codepage, output);
        }
    }

    private static void EncodeRune(Rune rune, string codepage, List<byte> output)
    {
        var value = rune.Value;

        if (value is >= 0x20 and <= 0x7E)
        {
            output.Add((byte)value);
            return;
        }

        // Tipográficos: iguales en las tres páginas porque ninguna los tiene.
        switch (value)
        {
            case 0x2018 or 0x2019:
                output.Add((byte)'\'');
                return;
            case 0x201C or 0x201D:
                output.Add((byte)'"');
                return;
            case 0x2013 or 0x2014:
                output.Add((byte)'-');
                return;
            case 0x2026:
                output.Add((byte)'.');
                output.Add((byte)'.');
                output.Add((byte)'.');
                return;
            case 0x00A0:
                output.Add((byte)' ');
                return;
        }

        switch (codepage)
        {
            case TextCodepages.Cp850:
                output.Add(LookUp(Cp850Reverse, rune) ?? Question);
                return;
            case TextCodepages.Cp437:
                // CP437 no tiene Á Í Ó Ú (ni otras mayúsculas acentuadas): se imprime la letra sin tilde.
                output.Add(LookUp(Cp437Reverse, rune) ?? FoldToAsciiLetter(rune) ?? Question);
                return;
            default:
                output.Add(value switch
                {
                    0x00BF => (byte)'?', // ¿
                    0x00A1 => (byte)'!', // ¡
                    0x00B7 => (byte)'.', // · (separador del CRM: «32 col · cp850»)
                    _ => FoldToAsciiLetter(rune) ?? Question,
                });
                return;
        }
    }

    private static byte? LookUp(Dictionary<char, byte> table, Rune rune) =>
        rune.IsBmp && table.TryGetValue((char)rune.Value, out var b) ? b : null;

    /// <summary>
    /// Quita los diacríticos (NFD): «Á» → A, «ñ» → n. Solo devuelve algo si queda exactamente una letra
    /// ASCII seguida de marcas combinantes; cualquier otra cosa se imprime como «?».
    /// </summary>
    private static byte? FoldToAsciiLetter(Rune rune)
    {
        if (!rune.IsBmp)
        {
            return null;
        }

        var decomposed = rune.ToString().Normalize(NormalizationForm.FormD);
        if (decomposed.Length < 2 || !char.IsAsciiLetter(decomposed[0]))
        {
            return null;
        }

        for (var i = 1; i < decomposed.Length; i++)
        {
            if (CharUnicodeInfo.GetUnicodeCategory(decomposed[i]) != UnicodeCategory.NonSpacingMark)
            {
                return null;
            }
        }

        return (byte)decomposed[0];
    }

    /// <summary>
    /// NFC tolerante: una sustituta suelta (UTF-16 inválido) o un no-carácter (U+FFFE…) hacen lanzar a
    /// Normalize, así que antes se cambian por U+FFFD, que luego sale como «?».
    /// </summary>
    private static string ToNfc(string text)
    {
        var builder = new StringBuilder(text.Length);
        foreach (var rune in text.EnumerateRunes())
        {
            builder.Append(Settings.StationSettingsRules.IsNoncharacter(rune.Value) ? Rune.ReplacementChar.ToString() : rune.ToString());
        }

        return builder.ToString().Normalize(NormalizationForm.FormC);
    }
}
