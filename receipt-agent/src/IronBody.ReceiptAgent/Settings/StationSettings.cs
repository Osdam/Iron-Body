using System.Text.Json.Serialization;
using IronBody.ReceiptAgent.Printing.EscPos;

namespace IronBody.ReceiptAgent.Settings;

/// <summary>
/// Ajustes de la estación (settings.json en DataDirectory). Inmutable: quien lo lee obtiene una foto
/// coherente aunque un configure lo cambie a la vez.
/// </summary>
public sealed record StationSettings(
    [property: JsonPropertyName("station_key")] string StationKey,
    [property: JsonPropertyName("station_name")] string? StationName,
    [property: JsonPropertyName("printer_name")] string? PrinterName,
    [property: JsonPropertyName("columns")] int Columns,
    [property: JsonPropertyName("codepage")] string Codepage,
    [property: JsonPropertyName("feed_lines")] int FeedLines)
{
    public const int DefaultColumns = 32;
    public const string DefaultCodepage = TextCodepages.Cp850;
    public const int DefaultFeedLines = 4;

    public static StationSettings CreateNew(string stationKey) =>
        new(stationKey, null, null, DefaultColumns, DefaultCodepage, DefaultFeedLines);
}

/// <summary>Ajustes que manda el backend en un ticket <c>configure</c> (contrato §4).</summary>
public sealed record ConfigureSettings(string? StationName, string? PrinterName, int Columns, string Codepage, int FeedLines);
