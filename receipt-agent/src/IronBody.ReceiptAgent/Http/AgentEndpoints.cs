using System.Text.Json;
using IronBody.ReceiptAgent.Configuration;
using IronBody.ReceiptAgent.Diagnostics;
using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Settings;
using IronBody.ReceiptAgent.Tickets;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Routing;
using Microsoft.Extensions.Logging;

namespace IronBody.ReceiptAgent.Http;

/// <summary>Endpoints del contrato §3.2. Las guardas ya se aplicaron en el middleware.</summary>
public static class AgentEndpoints
{
    public static void MapAgentEndpoints(this IEndpointRouteBuilder app)
    {
        // Sin datos de la estación: es lo que el técnico abre en el navegador para comprobar que vive.
        app.MapGet("/", () => Results.Text(ServiceInfo.RootBanner, "text/plain; charset=utf-8"));

        app.MapGet("/v1/status", GetStatus);
        app.MapGet("/v1/printers", GetPrinters);
        app.MapPost("/v1/tickets", PostTicket);
        app.MapGet("/v1/diagnostics", GetDiagnostics);
    }

    private static IResult GetStatus(AgentOptions options, StationSettingsStore store, IPrinterBackend printers,
        PrintQueue queue, AgentState state, TimeProvider time, ILoggerFactory loggers)
    {
        var settings = store.Current;
        return Json(new
        {
            ok = true,
            service = ServiceInfo.Name,
            version = ServiceInfo.Version,
            protocol = ServiceInfo.Protocol,
            station_key = settings.StationKey,
            station_name = settings.StationName,
            api_base = options.ApiBaseUrl,
            printer = DescribePrinter(settings.PrinterName, printers, loggers),
            settings = new { columns = settings.Columns, codepage = settings.Codepage, feed_lines = settings.FeedLines, cut = false },
            queue = new { pending = queue.Pending },
            last_job = state.LastJob,
            started_at = IsoTime.Format(state.StartedAt),
            now = IsoTime.Format(time.GetUtcNow()),
        });
    }

    private static object DescribePrinter(string? printerName, IPrinterBackend printers, ILoggerFactory loggers)
    {
        if (printerName is null)
        {
            return new { name = (string?)null, found = false, status = PrinterStatuses.NotConfigured, driver = (string?)null, port = (string?)null };
        }

        PrinterResolution resolution;
        try
        {
            // Misma resolución que al imprimir: una impresora de red o de otro equipo es «no encontrada».
            resolution = LocalPrinterPolicy.Resolve(printers.ListPrinters(), printerName);
        }
        catch (Exception ex)
        {
            // El estado es informativo: si Windows no deja enumerar se publica «unknown» en vez de
            // tumbar /v1/status.
            loggers.CreateLogger("IronBody.ReceiptAgent.Status").LogWarning(ex, "No se pudo enumerar las impresoras para «{Printer}»", printerName);
            return new { name = (string?)printerName, found = false, status = PrinterStatuses.Unknown, driver = (string?)null, port = (string?)null };
        }

        var info = resolution.Printer;
        return info is null
            ? new { name = (string?)printerName, found = false, status = PrinterStatuses.NotFound, driver = (string?)null, port = (string?)null }
            : new { name = (string?)info.Name, found = true, status = info.Status, driver = info.Driver, port = info.Port };
    }

    private static IResult GetPrinters(IPrinterBackend printers, DiagnosticsBuffer diagnostics)
    {
        try
        {
            // Solo las que se pueden elegir: locales y sin puerto de red (LocalPrinterPolicy).
            var list = LocalPrinterPolicy.Selectable(printers.ListPrinters()).Select(p => new
            {
                name = p.Name,
                driver = p.Driver,
                port = p.Port,
                is_default = p.IsDefault,
                status = p.Status,
                looks_thermal = PrinterStatusMapper.LooksThermal(p.Name, p.Driver),
            });
            return Json(new { ok = true, printers = list.ToArray() });
        }
        catch (Exception ex)
        {
            diagnostics.Error("printers_list_failed", $"Windows no devolvió la lista de impresoras: {ex.GetType().Name}.",
                errorCode: ErrorCodes.SpoolerError, exception: ex);
            return Json(new
            {
                ok = false,
                code = ErrorCodes.SpoolerError,
                message = "Windows no devolvió la lista de impresoras. Revisa que el servicio «Cola de impresión» esté iniciado.",
                printers = Array.Empty<object>(),
            });
        }
    }

    private static async Task<IResult> PostTicket(HttpContext context, TicketService tickets)
    {
        var ticket = await ReadTicketAsync(context.Request.Body, context.RequestAborted);
        if (ticket is null)
        {
            return Results.Json(new { ok = false, code = "invalid_body" }, AgentJson.Options, statusCode: StatusCodes.Status400BadRequest);
        }

        // El resultado va en el cuerpo y el estado HTTP es siempre 200 (contrato §3.2).
        return Json(await tickets.ProcessAsync(ticket));
    }

    private static IResult GetDiagnostics(DiagnosticsBuffer diagnostics, AgentOptions options) =>
        Json(new
        {
            ok = true,
            events = diagnostics.Snapshot(),
            log_dir = options.LogDirectory,
            settings_path = options.SettingsPath,
        });

    /// <summary>
    /// El cuerpo debe ser exactamente <c>{"ticket": "&lt;43 base64url&gt;"}</c>. Cualquier otro campo se
    /// rechaza: el navegador no tiene nada más que decirle al servicio, y menos texto para imprimir.
    /// </summary>
    internal static async Task<string?> ReadTicketAsync(Stream body, CancellationToken cancellationToken)
    {
        try
        {
            using var document = await JsonDocument.ParseAsync(body, cancellationToken: cancellationToken);
            var root = document.RootElement;
            if (root.ValueKind != JsonValueKind.Object)
            {
                return null;
            }

            string? ticket = null;
            foreach (var property in root.EnumerateObject())
            {
                if (property.Name != "ticket" || ticket is not null || property.Value.ValueKind != JsonValueKind.String)
                {
                    return null;
                }

                ticket = property.Value.GetString();
            }

            return TicketService.IsWellFormedTicket(ticket) ? ticket : null;
        }
        catch (JsonException)
        {
            // JSON mal formado: es un 400 invalid_body, no un fallo del servicio.
            return null;
        }
    }

    private static IResult Json(object value) => Results.Json(value, AgentJson.Options);
}
