using System.Text.Json.Serialization;
using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Settings;

namespace IronBody.ReceiptAgent.Backend;

/// <summary>Cuerpo de POST /api/receipt-agent/redeem (contrato §4).</summary>
public sealed record RedeemRequest(
    [property: JsonPropertyName("ticket")] string Ticket,
    [property: JsonPropertyName("station_key")] string StationKey,
    [property: JsonPropertyName("agent")] AgentDescriptor Agent);

public sealed record AgentDescriptor(
    [property: JsonPropertyName("version")] string Version,
    [property: JsonPropertyName("protocol")] int Protocol,
    [property: JsonPropertyName("printer_name")] string? PrinterName,
    [property: JsonPropertyName("columns")] int Columns,
    [property: JsonPropertyName("codepage")] string Codepage);

/// <summary>Cuerpo de POST /api/receipt-agent/report (contrato §4).</summary>
public sealed record ReportRequest(
    [property: JsonPropertyName("ticket")] string Ticket,
    [property: JsonPropertyName("station_key")] string StationKey,
    [property: JsonPropertyName("outcome")] string Outcome,
    [property: JsonPropertyName("error_code")] string? ErrorCode,
    [property: JsonPropertyName("message")] string? Message,
    [property: JsonPropertyName("spool_job_id")] int? SpoolJobId,
    [property: JsonPropertyName("printer_name")] string? PrinterName);

/// <summary>
/// Resultado del report. <see cref="IntegrationError"/>: el backend rechazó el cuerpo (400/422), un
/// fallo de integración que se registra como error. <see cref="Detail"/> es estado HTTP y code.
/// </summary>
public sealed record ReportResult(bool Reported, string? Detail, bool IntegrationError)
{
    public static readonly ReportResult Ok = new(true, null, false);
}

public static class ReportOutcomes
{
    public const string Accepted = "accepted";
    public const string Error = "error";
    public const string Applied = "applied";
}

public static class TicketPurposes
{
    public const string Print = "print";
    public const string Test = "test";
    public const string Configure = "configure";
}

/// <summary>
/// Resultado del canje. O trae un propósito con su carga (documento o ajustes), o un código de §6.
/// <see cref="LogDetail"/> describe el fallo para el log (estado HTTP, código) sin datos del cuerpo.
/// </summary>
public sealed record RedeemOutcome(
    string? Purpose,
    PrintDocument? Job,
    ConfigureSettings? Settings,
    string? ErrorCode,
    string? LogDetail,
    string? CashierMessage = null)
{
    public bool Succeeded => ErrorCode is null;

    public static RedeemOutcome ForJob(string purpose, PrintDocument job) => new(purpose, job, null, null, null);

    public static RedeemOutcome ForSettings(ConfigureSettings settings) => new(TicketPurposes.Configure, null, settings, null, null);

    /// <param name="cashierMessage">Mensaje propio cuando el genérico del código no explica el caso.</param>
    public static RedeemOutcome Fail(string errorCode, string logDetail, string? cashierMessage = null) =>
        new(null, null, null, errorCode, logDetail, cashierMessage);
}
