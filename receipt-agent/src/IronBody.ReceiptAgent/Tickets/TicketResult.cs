using System.Text.Json.Serialization;
using IronBody.ReceiptAgent.Settings;

namespace IronBody.ReceiptAgent.Tickets;

public static class TicketStates
{
    public const string Accepted = "accepted";
    public const string Applied = "applied";
    public const string Error = "error";
}

/// <summary>Ajustes efectivos que devuelve un configure aplicado.</summary>
public sealed record EffectiveSettings(
    [property: JsonPropertyName("station_name")] string? StationName,
    [property: JsonPropertyName("printer_name")] string? PrinterName,
    [property: JsonPropertyName("columns")] int Columns,
    [property: JsonPropertyName("codepage")] string Codepage,
    [property: JsonPropertyName("feed_lines")] int FeedLines,
    [property: JsonPropertyName("cut")] bool Cut)
{
    public static EffectiveSettings From(StationSettings s) =>
        new(s.StationName, s.PrinterName, s.Columns, s.Codepage, s.FeedLines, false);
}

/// <summary>Respuesta de POST /v1/tickets (contrato §3.2). Siempre HTTP 200 tras las guardas.</summary>
public sealed record TicketResult(
    [property: JsonPropertyName("ok")] bool Ok,
    [property: JsonPropertyName("purpose")] string? Purpose,
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("error_code")] string? ErrorCode,
    [property: JsonPropertyName("message")] string Message,
    [property: JsonPropertyName("receipt_number")] string? ReceiptNumber,
    [property: JsonPropertyName("copy_label")] string? CopyLabel,
    [property: JsonPropertyName("spool_job_id")] int? SpoolJobId,
    [property: JsonPropertyName("printer_name")] string? PrinterName,
    [property: JsonPropertyName("printed_bytes")] int? PrintedBytes,
    [property: JsonPropertyName("reported")] bool Reported,
    [property: JsonPropertyName("settings")] EffectiveSettings? Settings);

/// <summary>Resultado de la fase de impresión, antes de reportarlo.</summary>
public sealed record PrintOutcome(bool Accepted, string? ErrorCode, string Message, string? PrinterName, int? SpoolJobId, int? PrintedBytes)
{
    public static PrintOutcome Fail(string errorCode, string message, string? printerName, int? spoolJobId = null, int? printedBytes = null) =>
        new(false, errorCode, message, printerName, spoolJobId, printedBytes);
}

/// <summary>Último trabajo de impresión o prueba, para GET /v1/status.</summary>
public sealed record LastJob(
    [property: JsonPropertyName("at")] string At,
    [property: JsonPropertyName("purpose")] string Purpose,
    [property: JsonPropertyName("receipt_number")] string? ReceiptNumber,
    [property: JsonPropertyName("state")] string State,
    [property: JsonPropertyName("error_code")] string? ErrorCode);

/// <summary>Estado en memoria del proceso: cuándo arrancó y cuál fue el último trabajo.</summary>
public sealed class AgentState
{
    private LastJob? _lastJob;

    public AgentState(TimeProvider time)
    {
        StartedAt = time.GetUtcNow();
    }

    public DateTimeOffset StartedAt { get; }

    public LastJob? LastJob => Volatile.Read(ref _lastJob);

    public void RecordJob(LastJob job) => Volatile.Write(ref _lastJob, job);
}
