using System.Globalization;
using IronBody.ReceiptAgent.Configuration;
using IronBody.ReceiptAgent.Tickets;

namespace IronBody.ReceiptAgent.Printing;

/// <summary>
/// Impresora de archivo SOLO para desarrollo (el validador de configuración la prohíbe fuera de
/// Development). Cada trabajo se guarda como .bin en spool-out para inspeccionar los bytes ESC/POS en
/// el Mac. Las impresoras «SIMULAR-…» devuelven los errores de Windows para probar el CRM de punta a punta;
/// cualquier otro nombre no existe (printer_not_found).
/// </summary>
public sealed class FilePrinterBackend : IPrinterBackend
{
    public const string DefaultPrinterName = "POS-58";
    private const string Driver = "archivo (desarrollo)";

    private readonly string _directory;
    private readonly TimeProvider _time;
    private int _nextJobId;

    public FilePrinterBackend(AgentOptions options, TimeProvider time)
    {
        _directory = options.SpoolOutDirectory;
        _time = time;
    }

    /// <summary>
    /// Lista simulada de impresoras locales. «SIMULAR-RED» tiene un puerto TCP/IP para comprobar que
    /// el servicio rechaza impresoras de red; un nombre que no está aquí da printer_not_found.
    /// </summary>
    public IReadOnlyList<PrinterInfo> ListPrinters() => new[]
    {
        new PrinterInfo(DefaultPrinterName, Driver, "USB001", true, PrinterStatuses.Ready),
        new PrinterInfo("SIMULAR-OFFLINE", Driver, "USB002", false, PrinterStatuses.Offline),
        new PrinterInfo("SIMULAR-PAPEL", Driver, "USB003", false, PrinterStatuses.PaperOut),
        new PrinterInfo("SIMULAR-ERROR", Driver, "USB004", false, PrinterStatuses.Error),
        new PrinterInfo("SIMULAR-TIMEOUT", Driver, "USB005", false, PrinterStatuses.Ready),
        new PrinterInfo("SIMULAR-RAW", Driver, "USB006", false, PrinterStatuses.Ready),
        new PrinterInfo("SIMULAR-RED", Driver, "IP_192.168.1.50", false, PrinterStatuses.Ready),
    };

    public async Task<SpoolResult> PrintAsync(string printerName, string documentName, byte[] data, CancellationToken cancellationToken)
    {
        var jobId = Interlocked.Increment(ref _nextJobId);
        var simulated = printerName.ToUpperInvariant() switch
        {
            "SIMULAR-OFFLINE" => SpoolResult.Fail(ErrorCodes.PrinterOffline, null, jobId, data.Length),
            "SIMULAR-PAPEL" => SpoolResult.Fail(ErrorCodes.PaperOut, null, jobId, data.Length),
            "SIMULAR-ERROR" => SpoolResult.Fail(ErrorCodes.PrinterError, null, jobId, data.Length),
            "SIMULAR-TIMEOUT" => SpoolResult.Fail(ErrorCodes.JobTimeout, null, jobId, data.Length),
            "SIMULAR-RAW" => SpoolResult.Fail(ErrorCodes.SpoolerError, CashierMessages.RawNotSupported),
            _ => null,
        };

        if (simulated is not null)
        {
            return simulated;
        }

        Directory.CreateDirectory(_directory);
        var stamp = _time.GetUtcNow().UtcDateTime.ToString("yyyyMMdd'T'HHmmssfff'Z'", CultureInfo.InvariantCulture);
        var path = Path.Combine(_directory, $"{stamp}-job{jobId}.bin");
        await File.WriteAllBytesAsync(path, data, cancellationToken).ConfigureAwait(false);
        return SpoolResult.Ok(jobId, data.Length);
    }
}
