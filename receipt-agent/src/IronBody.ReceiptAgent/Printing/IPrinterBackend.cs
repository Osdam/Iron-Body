namespace IronBody.ReceiptAgent.Printing;

/// <summary>Estados de impresora que publica el servicio (contrato §3.2).</summary>
public static class PrinterStatuses
{
    public const string Ready = "ready";
    public const string Offline = "offline";
    public const string Error = "error";
    public const string PaperOut = "paper_out";
    public const string Unknown = "unknown";
    public const string NotConfigured = "not_configured";
    public const string NotFound = "not_found";
}

/// <summary>
/// Impresora vista por Windows. El estado es informativo: no decide si se imprime.
/// <see cref="IsNetwork"/> refleja PRINTER_ATTRIBUTE_NETWORK.
/// </summary>
public sealed record PrinterInfo(string Name, string? Driver, string? Port, bool IsDefault, string Status, bool IsNetwork = false);

/// <summary>Resultado de entregar un trabajo al spooler y seguirlo en la cola.</summary>
public sealed record SpoolResult(bool Accepted, int? JobId, int BytesWritten, string? ErrorCode, string? Message)
{
    public static SpoolResult Ok(int jobId, int bytes) => new(true, jobId, bytes, null, null);

    public static SpoolResult Fail(string errorCode, string? message = null, int? jobId = null, int bytes = 0) =>
        new(false, jobId, bytes, errorCode, message);
}

/// <summary>
/// Acceso a las impresoras. En Windows es el spooler (winspool.drv); en desarrollo, archivos .bin.
/// </summary>
public interface IPrinterBackend
{
    /// <summary>
    /// Impresoras LOCALES de este equipo (EnumPrinters PRINTER_ENUM_LOCAL, nunca conexiones de red).
    /// Toda resolución de nombres pasa por esta lista y por <see cref="LocalPrinterPolicy"/>.
    /// </summary>
    IReadOnlyList<PrinterInfo> ListPrinters();

    /// <summary>
    /// Entrega los bytes en RAW y sigue el trabajo hasta aceptado, error o tiempo agotado. Solo se
    /// llama con un nombre ya resuelto contra <see cref="ListPrinters"/>.
    /// </summary>
    Task<SpoolResult> PrintAsync(string printerName, string documentName, byte[] data, CancellationToken cancellationToken);
}
