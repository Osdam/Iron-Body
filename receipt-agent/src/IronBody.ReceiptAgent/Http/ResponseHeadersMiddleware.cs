using Microsoft.AspNetCore.Http;
using Microsoft.Extensions.Logging;

namespace IronBody.ReceiptAgent.Http;

/// <summary>
/// Guarda 8 (contrato §3.1): toda respuesta sin caché, sin sniffing y sin cabecera Server. Se aplica
/// en OnStarting para cubrir también los rechazos de las guardas, el 429 y los 404 del enrutador.
/// Además es el último recurso ante una excepción no prevista: 500 sin detalles internos.
/// </summary>
public sealed class ResponseHeadersMiddleware
{
    private readonly RequestDelegate _next;
    private readonly ILogger<ResponseHeadersMiddleware> _logger;

    public ResponseHeadersMiddleware(RequestDelegate next, ILogger<ResponseHeadersMiddleware> logger)
    {
        _next = next;
        _logger = logger;
    }

    public async Task InvokeAsync(HttpContext context)
    {
        context.Response.OnStarting(static state =>
        {
            var headers = ((HttpContext)state).Response.Headers;
            headers.CacheControl = "no-store";
            headers.XContentTypeOptions = "nosniff";
            headers.Remove("Server");
            return Task.CompletedTask;
        }, context);

        try
        {
            await _next(context);
        }
        catch (Exception ex) when (!context.Response.HasStarted && !context.RequestAborted.IsCancellationRequested)
        {
            _logger.LogError(ex, "Excepción no controlada en {Method} {Path}", context.Request.Method, context.Request.Path.Value);
            // Se conservan las cabeceras CORS para que el CRM pueda leer el código del error.
            var allowOrigin = context.Response.Headers.AccessControlAllowOrigin;
            var vary = context.Response.Headers.Vary;
            context.Response.Clear();
            context.Response.Headers.AccessControlAllowOrigin = allowOrigin;
            context.Response.Headers.Vary = vary;
            await GuardResponses.WriteAsync(context, StatusCodes.Status500InternalServerError, "internal_error");
        }
    }
}
