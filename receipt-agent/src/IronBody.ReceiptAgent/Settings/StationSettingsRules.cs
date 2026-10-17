using IronBody.ReceiptAgent.Printing.EscPos;

namespace IronBody.ReceiptAgent.Settings;

/// <summary>
/// Reglas de los ajustes de estación. Son las mismas que valida el backend (contrato §5.3): el servicio
/// las vuelve a comprobar porque un ajuste fuera de rango acabaría en bytes ESC/POS incorrectos.
/// </summary>
public static class StationSettingsRules
{
    public static readonly int[] AllowedColumns = { 32, 42 };
    public const int MinFeedLines = 0;
    public const int MaxFeedLines = 8;
    public const int StationNameMin = 3;
    public const int StationNameMax = 60;
    public const int PrinterNameMax = 120;

    public static bool IsValidColumns(int columns) => Array.IndexOf(AllowedColumns, columns) >= 0;

    public static bool IsValidCodepage(string? codepage) => TextCodepages.IsKnown(codepage);

    public static bool IsValidFeedLines(int feedLines) => feedLines is >= MinFeedLines and <= MaxFeedLines;

    /// <summary>uuid v4 en forma canónica (minúsculas con guiones), como lo genera el servicio.</summary>
    public static bool IsValidStationKey(string? value)
    {
        if (value is null || value.Length != 36 || !Guid.TryParseExact(value, "D", out _))
        {
            return false;
        }

        if (!string.Equals(value, value.ToLowerInvariant(), StringComparison.Ordinal))
        {
            return false;
        }

        // Versión 4 (dígito 13) y variante RFC 4122 (dígito 17 ∈ 8,9,a,b).
        return value[14] == '4' && value[19] is '8' or '9' or 'a' or 'b';
    }

    public static bool IsValidStationName(string? name) =>
        name is null || (CodePointLength(name) is >= StationNameMin and <= StationNameMax && !ContainsControl(name));

    public static bool IsValidPrinterName(string? name) =>
        name is null || (name.Length > 0 && CodePointLength(name) <= PrinterNameMax && !ContainsControl(name));

    /// <summary>Devuelve el primer problema encontrado o null si los ajustes son válidos.</summary>
    public static string? FindProblem(ConfigureSettings s)
    {
        if (!IsValidStationName(s.StationName))
        {
            return $"station_name debe tener entre {StationNameMin} y {StationNameMax} caracteres y ningún carácter de control";
        }

        if (!IsValidPrinterName(s.PrinterName))
        {
            return $"printer_name debe tener entre 1 y {PrinterNameMax} caracteres y ningún carácter de control";
        }

        if (!IsValidColumns(s.Columns))
        {
            return $"columns debe ser 32 o 42 (recibido {s.Columns})";
        }

        if (!IsValidCodepage(s.Codepage))
        {
            return "codepage debe ser cp850, cp437 o ascii";
        }

        if (!IsValidFeedLines(s.FeedLines))
        {
            return $"feed_lines debe estar entre {MinFeedLines} y {MaxFeedLines} (recibido {s.FeedLines})";
        }

        return null;
    }

    public static string? FindProblem(StationSettings s)
    {
        if (!IsValidStationKey(s.StationKey))
        {
            return "station_key no es un uuid v4";
        }

        return FindProblem(new ConfigureSettings(s.StationName, s.PrinterName, s.Columns, s.Codepage, s.FeedLines));
    }

    public static int CodePointLength(string value)
    {
        var count = 0;
        foreach (var _ in value.EnumerateRunes())
        {
            count++;
        }

        return count;
    }

    /// <summary>Controles C0, DEL y C1 (U+0000–U+001F, U+007F–U+009F), igual que el contrato §2.</summary>
    public static bool IsControl(int codePoint) => codePoint is <= 0x1F or (>= 0x7F and <= 0x9F);

    /// <summary>No-caracteres Unicode (U+FDD0–U+FDEF y U+xxFFFE/U+xxFFFF): nunca son texto legítimo.</summary>
    public static bool IsNoncharacter(int codePoint) => codePoint is >= 0xFDD0 and <= 0xFDEF || (codePoint & 0xFFFE) == 0xFFFE;

    public static bool ContainsNoncharacter(string value)
    {
        foreach (var rune in value.EnumerateRunes())
        {
            if (IsNoncharacter(rune.Value))
            {
                return true;
            }
        }

        return false;
    }

    public static bool ContainsControl(string value)
    {
        foreach (var rune in value.EnumerateRunes())
        {
            if (IsControl(rune.Value))
            {
                return true;
            }
        }

        return false;
    }
}
