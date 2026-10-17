using System.Collections.Concurrent;
using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;
using IronBody.ReceiptAgent.Backend;
using IronBody.ReceiptAgent.Diagnostics;
using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Settings;

namespace IronBody.ReceiptAgent.Tickets;

/// <summary>
/// Flujo de un ticket: canjear en el backend → imprimir o aplicar ajustes en la cola → reportar.
/// El ticket es la única autorización: el navegador no manda texto a imprimir, solo el ticket, y lo
/// que se imprime llega del backend por TLS (contrato §0).
/// </summary>
public sealed partial class TicketService
{
    private readonly ConcurrentDictionary<string, Lazy<Task<TicketResult>>> _inFlight = new(StringComparer.Ordinal);
    private readonly BackendClient _backend;
    private readonly StationSettingsStore _settings;
    private readonly PrintQueue _queue;
    private readonly PrintJobExecutor _executor;
    private readonly IPrinterBackend _printers;
    private readonly TicketTimeBudget _budget;
    private readonly DiagnosticsBuffer _diagnostics;
    private readonly AgentState _state;
    private readonly TimeProvider _time;

    public TicketService(BackendClient backend, StationSettingsStore settings, PrintQueue queue, PrintJobExecutor executor,
        IPrinterBackend printers, TicketTimeBudget budget, DiagnosticsBuffer diagnostics, AgentState state, TimeProvider time)
    {
        _printers = printers;
        _budget = budget;
        _backend = backend;
        _settings = settings;
        _queue = queue;
        _executor = executor;
        _diagnostics = diagnostics;
        _state = state;
        _time = time;
    }

    /// <summary>32 bytes en base64url sin relleno (contrato §1).</summary>
    public static bool IsWellFormedTicket(string? ticket) => ticket is not null && TicketPattern().IsMatch(ticket);

    /// <summary>
    /// Dos peticiones con el mismo ticket a la vez comparten el mismo flujo y el mismo resultado: una
    /// sola impresión. El flujo no depende de la conexión del navegador: si el CRM cierra la pestaña a
    /// mitad, el ticket ya está canjeado y el resultado se termina y se reporta igual.
    /// </summary>
    public async Task<TicketResult> ProcessAsync(string ticket)
    {
        // La clave es el hash: el ticket en claro no se queda en estructuras de larga vida.
        var key = Convert.ToHexString(SHA256.HashData(Encoding.ASCII.GetBytes(ticket)));
        var flow = _inFlight.GetOrAdd(key, _ => new Lazy<Task<TicketResult>>(
            () => Task.Run(() => RunAsync(ticket)), LazyThreadSafetyMode.ExecutionAndPublication));
        try
        {
            return await flow.Value.ConfigureAwait(false);
        }
        finally
        {
            _inFlight.TryRemove(KeyValuePair.Create(key, flow));
        }
    }

    private async Task<TicketResult> RunAsync(string ticket)
    {
        // Tiempo ACTIVO del ticket (presupuesto de 25 s): se para mientras el trabajo espera en la cola.
        var active = Stopwatch.StartNew();
        var settings = _settings.Current;
        RedeemOutcome redeem;
        try
        {
            redeem = await _backend.RedeemAsync(ticket, settings, CancellationToken.None).ConfigureAwait(false);
        }
        catch (Exception ex)
        {
            _diagnostics.Error("redeem_exception", $"Fallo inesperado al canjear: {ex.GetType().Name}.", errorCode: ErrorCodes.ApiError, exception: ex);
            return TicketPhaseError(ErrorCodes.ApiError, settings);
        }

        if (!redeem.Succeeded)
        {
            // api_error / incompatible_job son fallos del servidor o de integración (p. ej. 422
            // invalid_request): error, con el estado HTTP y el code del backend. Lo demás (ticket
            // caducado, usado…) es operativa normal: aviso.
            var message = $"Canje rechazado: {redeem.LogDetail}. No se imprimió nada.";
            if (redeem.ErrorCode is ErrorCodes.ApiError or ErrorCodes.IncompatibleJob)
            {
                _diagnostics.Error("ticket_rejected", message, errorCode: redeem.ErrorCode);
            }
            else
            {
                _diagnostics.Warn("ticket_rejected", message, errorCode: redeem.ErrorCode);
            }

            return TicketPhaseError(redeem.ErrorCode!, settings, redeem.CashierMessage);
        }

        try
        {
            return redeem.Purpose == TicketPurposes.Configure
                ? await ConfigureAsync(ticket, settings.StationKey, redeem.Settings!, active).ConfigureAwait(false)
                : await PrintAsync(ticket, settings.StationKey, redeem.Purpose!, redeem.Job!, active).ConfigureAwait(false);
        }
        catch (Exception ex)
        {
            // Defecto no previsto tras canjear: el contrato exige 200 con el resultado, nunca un 500.
            // reported=false hace que el CRM cierre el intento con su PATCH.
            _diagnostics.Error("flow_exception", $"Fallo inesperado tras canjear el ticket: {ex.GetType().Name}.",
                redeem.Purpose, redeem.Job?.ReceiptNumber, ErrorCodes.PrinterError, ex);
            return new TicketResult(false, redeem.Purpose, TicketStates.Error, ErrorCodes.PrinterError,
                CashierMessages.For(ErrorCodes.PrinterError), redeem.Job?.ReceiptNumber, redeem.Job?.CopyLabel,
                null, settings.PrinterName, null, false, null);
        }
    }

    private async Task<TicketResult> PrintAsync(string ticket, string stationKey, string purpose, PrintDocument document, Stopwatch active)
    {
        _diagnostics.Info("ticket_redeemed", "Ticket canjeado; trabajo recibido del servidor.", purpose, document.ReceiptNumber);

        PrintOutcome outcome;
        // Se comprueba antes de encolar para que un documento roto no ocupe sitio en la cola; el
        // trabajador lo vuelve a comprobar con los ajustes que haya al imprimir.
        var problem = PrintDocumentValidator.FindProblem(document, _settings.Current.Columns);
        if (problem is not null)
        {
            outcome = PrintJobExecutor.InvalidJob(problem, _settings.Current.PrinterName);
        }
        else
        {
            var work = new QueueWork<PrintOutcome>(
                () => RunActive(active, () => _executor.ExecuteAsync(purpose, document)),
                () => PrintOutcome.Fail(ErrorCodes.PrinterError, CashierMessages.ServiceStopping, _settings.Current.PrinterName));

            active.Stop();
            if (!_queue.TryEnqueue(work))
            {
                active.Start();
                outcome = PrintOutcome.Fail(ErrorCodes.QueueFull, CashierMessages.For(ErrorCodes.QueueFull), _settings.Current.PrinterName);
            }
            else
            {
                try
                {
                    outcome = await work.Completion.ConfigureAwait(false);
                    active.Start();
                }
                catch (Exception ex)
                {
                    active.Start();
                    _diagnostics.Error("print_exception", $"Fallo inesperado al imprimir: {ex.GetType().Name}.",
                        purpose, document.ReceiptNumber, ErrorCodes.PrinterError, ex);
                    outcome = PrintOutcome.Fail(ErrorCodes.PrinterError, CashierMessages.For(ErrorCodes.PrinterError), _settings.Current.PrinterName);
                }
            }
        }

        if (outcome.Accepted)
        {
            _diagnostics.Info("job_accepted",
                $"Trabajo {outcome.SpoolJobId} aceptado por el sistema en «{outcome.PrinterName}» ({outcome.PrintedBytes} bytes).",
                purpose, document.ReceiptNumber);
        }
        else
        {
            _diagnostics.Error("job_error", outcome.Message, purpose, document.ReceiptNumber, outcome.ErrorCode);
        }

        var reported = await ReportAsync(new ReportRequest(ticket, stationKey,
            outcome.Accepted ? ReportOutcomes.Accepted : ReportOutcomes.Error,
            outcome.ErrorCode, outcome.Message, outcome.SpoolJobId, outcome.PrinterName), purpose, document.ReceiptNumber, active).ConfigureAwait(false);

        _state.RecordJob(new LastJob(IsoTime.Format(_time.GetUtcNow()), purpose, document.ReceiptNumber,
            outcome.Accepted ? TicketStates.Accepted : TicketStates.Error, outcome.ErrorCode));

        return new TicketResult(
            outcome.Accepted, purpose, outcome.Accepted ? TicketStates.Accepted : TicketStates.Error, outcome.ErrorCode,
            outcome.Message, document.ReceiptNumber, document.CopyLabel, outcome.SpoolJobId, outcome.PrinterName,
            outcome.PrintedBytes, reported, null);
    }

    private async Task<TicketResult> ConfigureAsync(string ticket, string stationKey, ConfigureSettings incoming, Stopwatch active)
    {
        _diagnostics.Info("ticket_redeemed", "Ticket de configuración canjeado.", TicketPurposes.Configure);

        var problem = StationSettingsRules.FindProblem(incoming);
        if (problem is not null)
        {
            return await ConfigureFailedAsync(ticket, stationKey, active, ErrorCodes.InvalidJob,
                $"{CashierMessages.For(ErrorCodes.InvalidJob)} Detalle: {problem}.").ConfigureAwait(false);
        }

        if (incoming.PrinterName is not null)
        {
            PrinterResolution resolution;
            try
            {
                resolution = LocalPrinterPolicy.Resolve(_printers.ListPrinters(), incoming.PrinterName);
            }
            catch (Exception ex)
            {
                _diagnostics.Error("printers_list_failed", $"Windows no devolvió la lista de impresoras: {ex.GetType().Name}.",
                    TicketPurposes.Configure, errorCode: ErrorCodes.SpoolerError, exception: ex);
                return await ConfigureFailedAsync(ticket, stationKey, active, ErrorCodes.SpoolerError, CashierMessages.For(ErrorCodes.SpoolerError)).ConfigureAwait(false);
            }

            if (resolution.Status != PrinterResolutionStatus.Found)
            {
                // Solo impresoras de este equipo: una cola de otro host recibiría los recibos.
                _diagnostics.Error("printer_rejected", $"Configure con impresora «{incoming.PrinterName}» no utilizable: {resolution.Reason}.",
                    TicketPurposes.Configure, errorCode: ErrorCodes.PrinterNotFound);
                return await ConfigureFailedAsync(ticket, stationKey, active, ErrorCodes.PrinterNotFound, CashierMessages.ChooseLocalPrinter).ConfigureAwait(false);
            }

            incoming = incoming with { PrinterName = resolution.Printer!.Name };
        }

        // Por la cola: así un cambio de columnas o impresora nunca cae a mitad de un recibo.
        var work = new QueueWork<(StationSettings? Applied, string? Failure)>(
            () => RunActive(active, () => Task.FromResult(ApplySettings(incoming))),
            () => (null, CashierMessages.ServiceStopping));

        active.Stop();
        var enqueued = _queue.TryEnqueue(work);
        if (!enqueued)
        {
            active.Start();
            return await ConfigureFailedAsync(ticket, stationKey, active, ErrorCodes.QueueFull, CashierMessages.For(ErrorCodes.QueueFull)).ConfigureAwait(false);
        }

        var (applied, failure) = await work.Completion.ConfigureAwait(false);
        active.Start();
        if (applied is null)
        {
            return await ConfigureFailedAsync(ticket, stationKey, active, ErrorCodes.PrinterError, failure ?? CashierMessages.SettingsNotSaved).ConfigureAwait(false);
        }

        _diagnostics.Info("settings_applied",
            $"Ajustes aplicados: impresora «{applied.PrinterName ?? "sin configurar"}», {applied.Columns} columnas, {applied.Codepage}, avance {applied.FeedLines}.",
            TicketPurposes.Configure);

        var reported = await ReportAsync(new ReportRequest(ticket, stationKey, ReportOutcomes.Applied, null,
            CashierMessages.SettingsApplied, null, applied.PrinterName), TicketPurposes.Configure, null, active).ConfigureAwait(false);

        return new TicketResult(true, TicketPurposes.Configure, TicketStates.Applied, null, CashierMessages.SettingsApplied,
            null, null, null, applied.PrinterName, null, reported, EffectiveSettings.From(applied));
    }

    private (StationSettings? Applied, string? Failure) ApplySettings(ConfigureSettings incoming)
    {
        try
        {
            return (_settings.Apply(incoming), null);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            _diagnostics.Error("settings_write_failed", $"No se pudieron guardar los ajustes: {ex.GetType().Name}.",
                TicketPurposes.Configure, errorCode: ErrorCodes.PrinterError, exception: ex);
            return (null, CashierMessages.SettingsNotSaved);
        }
    }

    private async Task<TicketResult> ConfigureFailedAsync(string ticket, string stationKey, Stopwatch active, string errorCode, string message)
    {
        _diagnostics.Error("configure_error", message, TicketPurposes.Configure, errorCode: errorCode);
        var current = _settings.Current;
        var reported = await ReportAsync(new ReportRequest(ticket, stationKey, ReportOutcomes.Error, errorCode, message, null, current.PrinterName),
            TicketPurposes.Configure, null, active).ConfigureAwait(false);
        return new TicketResult(false, TicketPurposes.Configure, TicketStates.Error, errorCode, message,
            null, null, null, current.PrinterName, null, reported, null);
    }

    private async Task<bool> ReportAsync(ReportRequest report, string purpose, string? receiptNumber, Stopwatch active)
    {
        var available = _budget.Total - active.Elapsed;
        if (available < _budget.MinimumReportAttempt)
        {
            _diagnostics.Warn("report_skipped",
                $"Sin tiempo para reportar dentro del presupuesto de {_budget.Total.TotalSeconds:0} s (usados {active.Elapsed.TotalSeconds:0.0} s); el CRM cerrará el intento.",
                purpose, receiptNumber, report.ErrorCode);
            return false;
        }

        ReportResult result;
        try
        {
            result = await _backend.ReportAsync(report, available, CancellationToken.None).ConfigureAwait(false);
        }
        catch (Exception ex)
        {
            _diagnostics.Error("report_exception", $"Fallo inesperado al reportar: {ex.GetType().Name}.", purpose, receiptNumber, report.ErrorCode, ex);
            return false;
        }

        if (result.Reported)
        {
            return true;
        }

        if (result.IntegrationError)
        {
            // 400/422 (invalid_request, invalid_outcome…): el backend no entiende lo que se le manda.
            _diagnostics.Error("report_rejected",
                $"El servidor rechazó el reporte ({result.Detail}); el CRM cerrará el intento.", purpose, receiptNumber, report.ErrorCode);
        }
        else
        {
            _diagnostics.Warn("report_failed",
                $"No se pudo contar el resultado al servidor ({result.Detail}); el CRM cerrará el intento.", purpose, receiptNumber, report.ErrorCode);
        }

        return false;
    }

    /// <summary>Fase ticket (§6): nada se imprimió, nada se reporta; el CRM cierra el intento.</summary>
    private static TicketResult TicketPhaseError(string errorCode, StationSettings settings, string? message = null) =>
        new(false, null, TicketStates.Error, errorCode, message ?? CashierMessages.For(errorCode), null, null, null, settings.PrinterName, null, false, null);

    /// <summary>Ejecuta un trabajo de la cola contando su duración en el tiempo activo del ticket.</summary>
    private static async Task<T> RunActive<T>(Stopwatch active, Func<Task<T>> work)
    {
        active.Start();
        try
        {
            return await work().ConfigureAwait(false);
        }
        finally
        {
            active.Stop();
        }
    }

    [GeneratedRegex(@"^[A-Za-z0-9_-]{43}\z", RegexOptions.CultureInvariant)]
    private static partial Regex TicketPattern();
}
