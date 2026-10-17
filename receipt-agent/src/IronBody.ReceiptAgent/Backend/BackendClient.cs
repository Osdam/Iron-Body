using System.Net;
using System.Net.Http.Headers;
using System.Text.Json;
using IronBody.ReceiptAgent.Configuration;
using IronBody.ReceiptAgent.Settings;
using IronBody.ReceiptAgent.Tickets;
using Microsoft.Extensions.Logging;

namespace IronBody.ReceiptAgent.Backend;

/// <summary>
/// Cliente de /api/receipt-agent/*. Solo habla con <see cref="AgentOptions.ApiBaseUrl"/> de la
/// configuración de instalación (nunca con una URL que llegue en una petición) y no sigue
/// redirecciones. Cada llamada tiene su propio límite dentro del <see cref="TicketTimeBudget"/>
/// (canje 8 s, report hasta 2 s por intento). Nunca registra el ticket ni los cuerpos.
/// </summary>
public sealed class BackendClient
{
    public const string HttpClientName = "receipt-agent-backend";

    /// <summary>Tope del HttpClient por si un límite por llamada fallara: nunca infinito.</summary>
    public static readonly TimeSpan BackstopTimeout = TimeSpan.FromSeconds(10);
    public const int MaxResponseBytes = 256 * 1024;

    private readonly IHttpClientFactory _factory;
    private readonly AgentOptions _options;
    private readonly TicketTimeBudget _budget;
    private readonly ILogger<BackendClient> _logger;

    public BackendClient(IHttpClientFactory factory, AgentOptions options, TicketTimeBudget budget, ILogger<BackendClient> logger)
    {
        _factory = factory;
        _options = options;
        _budget = budget;
        _logger = logger;
    }

    private Uri RedeemUri => new($"{_options.ApiBase}/api/receipt-agent/redeem");

    private Uri ReportUri => new($"{_options.ApiBase}/api/receipt-agent/report");

    public async Task<RedeemOutcome> RedeemAsync(string ticket, StationSettings settings, CancellationToken cancellationToken)
    {
        var request = new RedeemRequest(ticket, settings.StationKey,
            new AgentDescriptor(ServiceInfo.Version, ServiceInfo.Protocol, settings.PrinterName, settings.Columns, settings.Codepage));

        HttpResponseMessage response;
        string body;
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
        timeout.CancelAfter(_budget.RedeemTimeout);
        try
        {
            var client = _factory.CreateClient(HttpClientName);
            response = await client.PostAsync(RedeemUri, JsonBody(request), timeout.Token).ConfigureAwait(false);
            body = await response.Content.ReadAsStringAsync(timeout.Token).ConfigureAwait(false);
        }
        catch (Exception ex) when (IsTransportFailure(ex, cancellationToken))
        {
            _logger.LogWarning("Canje sin respuesta del backend: {Error}", Describe(ex, _budget.RedeemTimeout));
            return RedeemOutcome.Fail(ErrorCodes.ApiUnreachable, $"sin respuesta ({Describe(ex, _budget.RedeemTimeout)})");
        }

        using (response)
        {
            if (response.IsSuccessStatusCode)
            {
                return RedeemResponseParser.ParseSuccess(body);
            }

            var code = RedeemResponseParser.ReadErrorCode(body);
            var mapped = RedeemResponseParser.MapError(response.StatusCode, code);
            var detail = $"HTTP {(int)response.StatusCode}, code={code ?? "-"}";
            // Un 404 sin code ticket_invalid no es un ticket malo: la ruta no existe (backend sin actualizar).
            return response.StatusCode == HttpStatusCode.NotFound && mapped == ErrorCodes.ApiError
                ? RedeemOutcome.Fail(mapped, detail, CashierMessages.BackendWithoutAgent)
                : RedeemOutcome.Fail(mapped, detail);
        }
    }

    /// <summary>
    /// Cuenta el resultado al backend con el tiempo que quede del presupuesto: hasta 2 intentos de
    /// hasta 2 s si no hay respuesta o el servidor falla (5xx, 429). Si no quedó registrado, el CRM
    /// cierra el intento con su PATCH.
    /// </summary>
    public async Task<ReportResult> ReportAsync(ReportRequest report, TimeSpan available, CancellationToken cancellationToken)
    {
        var clock = System.Diagnostics.Stopwatch.StartNew();
        var detail = "sin tiempo para intentarlo dentro del presupuesto";
        var attempts = 0;
        while (attempts < _budget.ReportMaxAttempts)
        {
            var remaining = available - clock.Elapsed;
            if (remaining < _budget.MinimumReportAttempt)
            {
                break;
            }

            attempts++;
            var attemptTimeout = remaining < _budget.ReportAttemptTimeout ? remaining : _budget.ReportAttemptTimeout;
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken);
            timeout.CancelAfter(attemptTimeout);
            try
            {
                var client = _factory.CreateClient(HttpClientName);
                using var response = await client.PostAsync(ReportUri, JsonBody(report), timeout.Token).ConfigureAwait(false);
                var body = await response.Content.ReadAsStringAsync(timeout.Token).ConfigureAwait(false);

                if (response.IsSuccessStatusCode)
                {
                    return ReportResult.Ok;
                }

                var code = RedeemResponseParser.ReadErrorCode(body);
                if (response.StatusCode == HttpStatusCode.Conflict && code == "already_reported")
                {
                    // Un intento anterior llegó aunque su respuesta se perdiera: ya está registrado.
                    return ReportResult.Ok;
                }

                var status = (int)response.StatusCode;
                detail = $"HTTP {status}, code={code ?? "-"}";
                var retryable = status >= 500 || response.StatusCode == HttpStatusCode.TooManyRequests;
                _logger.LogWarning("Report rechazado por el backend (intento {Attempt}/{Max}): {Detail}", attempts, _budget.ReportMaxAttempts, detail);
                if (!retryable)
                {
                    return new ReportResult(false, detail, status is 400 or 422);
                }
            }
            catch (Exception ex) when (IsTransportFailure(ex, cancellationToken))
            {
                detail = $"sin respuesta: {Describe(ex, attemptTimeout)}";
                _logger.LogWarning("Report sin respuesta del backend (intento {Attempt}/{Max}): {Error}",
                    attempts, _budget.ReportMaxAttempts, Describe(ex, attemptTimeout));
            }

            if (attempts < _budget.ReportMaxAttempts && available - clock.Elapsed - _budget.ReportRetryDelay >= _budget.MinimumReportAttempt)
            {
                await Task.Delay(_budget.ReportRetryDelay, cancellationToken).ConfigureAwait(false);
            }
        }

        return new ReportResult(false, $"{detail}, {attempts} intento(s)", false);
    }

    /// <summary>
    /// Cuerpo JSON ya serializado, con Content-Length. PostAsJsonAsync lo mandaría «chunked», y no todo
    /// servidor PHP lo acepta (php artisan serve, algunos proxies FastCGI).
    /// </summary>
    private static ByteArrayContent JsonBody<T>(T value)
    {
        var content = new ByteArrayContent(JsonSerializer.SerializeToUtf8Bytes(value));
        content.Headers.ContentType = new MediaTypeHeaderValue("application/json") { CharSet = "utf-8" };
        return content;
    }

    /// <summary>
    /// Fallos de transporte: red, DNS, TLS, límite de la llamada agotado (cancelación que no pidió el
    /// llamador) o respuesta mayor de lo admitido. Una cancelación pedida por el llamador no entra aquí.
    /// </summary>
    private static bool IsTransportFailure(Exception ex, CancellationToken cancellationToken) =>
        ex is HttpRequestException or IOException
        || (ex is OperationCanceledException && !cancellationToken.IsCancellationRequested);

    private static string Describe(Exception ex, TimeSpan limit) =>
        ex is OperationCanceledException
            ? $"tiempo agotado ({limit.TotalSeconds:0.##} s)"
            : $"{ex.GetType().Name}: {ex.Message}";
}
