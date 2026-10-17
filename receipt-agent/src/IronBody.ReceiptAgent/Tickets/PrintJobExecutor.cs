using IronBody.ReceiptAgent.Backend;
using IronBody.ReceiptAgent.Diagnostics;
using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Printing.EscPos;
using IronBody.ReceiptAgent.Settings;

namespace IronBody.ReceiptAgent.Tickets;

/// <summary>
/// Lo que hace el trabajador de la cola con un documento: comprobar, convertir a ESC/POS y entregar
/// al spooler. Lee los ajustes en el momento de imprimir (no al canjear) porque un configure pudo
/// cambiarlos mientras el trabajo esperaba en la cola.
/// </summary>
public sealed class PrintJobExecutor
{
    public const string TestDocumentName = "Iron Body prueba";

    private readonly StationSettingsStore _settings;
    private readonly IPrinterBackend _printers;
    private readonly DiagnosticsBuffer _diagnostics;

    public PrintJobExecutor(StationSettingsStore settings, IPrinterBackend printers, DiagnosticsBuffer diagnostics)
    {
        _settings = settings;
        _printers = printers;
        _diagnostics = diagnostics;
    }

    public async Task<PrintOutcome> ExecuteAsync(string purpose, PrintDocument document)
    {
        var settings = _settings.Current;
        var printerName = settings.PrinterName;

        var problem = PrintDocumentValidator.FindProblem(document, settings.Columns);
        if (problem is not null)
        {
            return InvalidJob(problem, printerName);
        }

        if (printerName is null)
        {
            return PrintOutcome.Fail(ErrorCodes.PrinterNotConfigured, CashierMessages.For(ErrorCodes.PrinterNotConfigured), null);
        }

        // Solo impresoras de este equipo: el nombre viene del backend y no se pasa a Windows sin
        // comprobar antes que está en la lista local y no apunta a la red.
        PrinterResolution resolution;
        try
        {
            resolution = LocalPrinterPolicy.Resolve(_printers.ListPrinters(), printerName);
        }
        catch (Exception ex)
        {
            _diagnostics.Error("printers_list_failed", $"Windows no devolvió la lista de impresoras: {ex.GetType().Name}.",
                purpose, document.ReceiptNumber, ErrorCodes.SpoolerError, ex);
            return PrintOutcome.Fail(ErrorCodes.SpoolerError, CashierMessages.For(ErrorCodes.SpoolerError), printerName);
        }

        if (resolution.Status != PrinterResolutionStatus.Found)
        {
            _diagnostics.Error("printer_rejected", $"Impresora «{printerName}» no utilizable: {resolution.Reason}.",
                purpose, document.ReceiptNumber, ErrorCodes.PrinterNotFound);
            var message = resolution.Status == PrinterResolutionStatus.Rejected
                ? CashierMessages.PrinterNotLocal(printerName)
                : CashierMessages.For(ErrorCodes.PrinterNotFound, printerName);
            return PrintOutcome.Fail(ErrorCodes.PrinterNotFound, message, printerName);
        }

        // Nombre canónico de Windows (puede diferir en mayúsculas del configurado).
        printerName = resolution.Printer!.Name;
        var data = EscPosRenderer.Render(document, settings.Codepage);
        var documentName = purpose == TicketPurposes.Test
            ? TestDocumentName
            : document.ReceiptNumber is { } number ? $"Recibo {number}" : "Recibo";

        SpoolResult result;
        try
        {
            result = await _printers.PrintAsync(printerName, documentName, data, CancellationToken.None).ConfigureAwait(false);
        }
        catch (Exception ex)
        {
            _diagnostics.Error("spooler_exception", $"Fallo inesperado al entregar el trabajo a «{printerName}»: {ex.GetType().Name}.",
                purpose, document.ReceiptNumber, ErrorCodes.SpoolerError, ex);
            return PrintOutcome.Fail(ErrorCodes.SpoolerError, CashierMessages.For(ErrorCodes.SpoolerError), printerName);
        }

        if (result.Accepted)
        {
            var message = purpose == TicketPurposes.Test ? CashierMessages.TestAccepted : CashierMessages.PrintAccepted;
            return new PrintOutcome(true, null, message, printerName, result.JobId, result.BytesWritten);
        }

        var code = result.ErrorCode ?? ErrorCodes.PrinterError;
        return PrintOutcome.Fail(code, result.Message ?? CashierMessages.For(code, printerName), printerName,
            result.JobId, result.JobId is null ? null : result.BytesWritten);
    }

    public static PrintOutcome InvalidJob(string problem, string? printerName) =>
        PrintOutcome.Fail(ErrorCodes.InvalidJob, $"{CashierMessages.For(ErrorCodes.InvalidJob)} Detalle: {problem}.", printerName);
}
