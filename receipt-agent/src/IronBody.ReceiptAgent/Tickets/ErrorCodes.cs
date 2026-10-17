namespace IronBody.ReceiptAgent.Tickets;

/// <summary>
/// Códigos de <c>error_code</c> que ve el CRM (contrato §6). Son contrato: el CRM traduce y decide con
/// ellos, así que no se inventan códigos nuevos aquí.
/// </summary>
public static class ErrorCodes
{
    // Fase ticket: no se imprimió nada, el intento sigue `started` y reported=false.
    public const string TicketInvalid = "ticket_invalid";
    public const string TicketExpired = "ticket_expired";
    public const string TicketUsed = "ticket_used";
    public const string StationMismatch = "station_mismatch";
    public const string StationInactive = "station_inactive";
    public const string AgentDisabled = "agent_disabled";
    public const string ProtocolUnsupported = "protocol_unsupported";
    public const string AttemptClosed = "attempt_closed";
    public const string ApiUnreachable = "api_unreachable";
    public const string ApiError = "api_error";
    public const string IncompatibleJob = "incompatible_job";

    /// <summary>
    /// 429 del backend (su throttle de 60/min por IP). No está en el §6 del contrato v1: lo añadió el
    /// lead al implementar el backend. Es fase ticket (no se imprimió nada).
    /// </summary>
    public const string RateLimited = "rate_limited";

    // Fase impresión: el servicio reporta el resultado al backend.
    public const string PrinterNotConfigured = "printer_not_configured";
    public const string PrinterNotFound = "printer_not_found";
    public const string PrinterOffline = "printer_offline";
    public const string PaperOut = "paper_out";
    public const string PrinterError = "printer_error";
    public const string JobTimeout = "job_timeout";
    public const string SpoolerError = "spooler_error";
    public const string InvalidJob = "invalid_job";
    public const string QueueFull = "queue_full";
}
