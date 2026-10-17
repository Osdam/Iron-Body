using System.Net;
using IronBody.ReceiptAgent.Configuration;
using Microsoft.AspNetCore.Http;
using Microsoft.Net.Http.Headers;

namespace IronBody.ReceiptAgent.Http;

/// <summary>
/// Guardas 2–6 del contrato §3.1, en ese orden, precedidas por la comprobación de que la conexión
/// viene del propio equipo. El servicio escucha en 127.0.0.1, pero cualquier página abierta en el
/// navegador del cajero puede intentar hablarle: estas guardas son las que impiden que una web ajena
/// (o un DNS que apunte a 127.0.0.1) use la impresora o lea la estación.
/// </summary>
public sealed class RequestGuardMiddleware
{
    public const int MaxBodyBytes = 16 * 1024;
    public const string ClientHeader = "X-IronBody-Client";
    public const string ClientHeaderValue = "crm";

    private readonly RequestDelegate _next;
    private readonly HashSet<string> _allowedHosts;
    private readonly HashSet<string> _allowedOrigins;

    public RequestGuardMiddleware(RequestDelegate next, AgentOptions options)
    {
        _next = next;
        _allowedHosts = new HashSet<string>(StringComparer.Ordinal)
        {
            $"127.0.0.1:{options.Port}",
            $"localhost:{options.Port}",
        };
        _allowedOrigins = new HashSet<string>(options.AllowedOrigins, StringComparer.Ordinal);
    }

    public async Task InvokeAsync(HttpContext context)
    {
        var request = context.Request;
        var response = context.Response;

        // 1b. Solo conexiones de este equipo. Kestrel ya escucha solo en 127.0.0.1 (guarda 1), pero
        //     Host y Origin solo frenan a un navegador: un cliente de la red los falsifica. Si una
        //     configuración abriera otra dirección, esta guarda sigue rechazando todo lo que no sea
        //     loopback (y sin dirección conocida tampoco se confía).
        if (context.Connection.RemoteIpAddress is not { } remote || !IPAddress.IsLoopback(remote))
        {
            await GuardResponses.WriteAsync(context, StatusCodes.Status403Forbidden, "not_loopback");
            return;
        }

        // 2. Host exacto: con DNS-rebinding un dominio ajeno resuelve a 127.0.0.1, pero el navegador
        //    sigue mandando ese dominio en Host.
        if (!_allowedHosts.Contains(request.Headers.Host.ToString()))
        {
            await GuardResponses.WriteAsync(context, StatusCodes.Status403Forbidden, "bad_host");
            return;
        }

        // 3. Origin obligatorio y exacto en /v1/*. No se confía solo en CORS: CORS impide LEER la
        //    respuesta, no que la petición se ejecute; aquí se rechaza antes de hacer nada.
        var origins = request.Headers.Origin;
        var origin = origins.Count == 1 ? origins[0] : null;
        var originAllowed = origin is not null && _allowedOrigins.Contains(origin);

        if (request.Path.StartsWithSegments("/v1", StringComparison.OrdinalIgnoreCase) && !originAllowed)
        {
            await GuardResponses.WriteAsync(context, StatusCodes.Status403Forbidden, "origin_not_allowed");
            return;
        }

        if (originAllowed)
        {
            response.Headers.AccessControlAllowOrigin = origin;
            response.Headers.Vary = HeaderNames.Origin;
        }

        // 4. Preflight de un origen permitido. Nunca Allow-Credentials: el servicio no usa cookies.
        if (HttpMethods.IsOptions(request.Method) && originAllowed)
        {
            response.StatusCode = StatusCodes.Status204NoContent;
            response.Headers.AccessControlAllowMethods = "GET, POST";
            response.Headers.AccessControlAllowHeaders = $"Content-Type, {ClientHeader}";
            response.Headers.AccessControlMaxAge = "600";
            if (string.Equals(request.Headers["Access-Control-Request-Private-Network"].ToString(), "true", StringComparison.OrdinalIgnoreCase))
            {
                response.Headers["Access-Control-Allow-Private-Network"] = "true";
            }

            return;
        }

        if (HttpMethods.IsPost(request.Method))
        {
            // 5. JSON y cabecera propia: ambas obligan al navegador a hacer preflight, así que un
            //    formulario HTML de otra web no puede disparar un POST «simple» contra el servicio.
            if (!IsJson(request.ContentType))
            {
                await GuardResponses.WriteAsync(context, StatusCodes.Status415UnsupportedMediaType, "unsupported_media_type");
                return;
            }

            if (!string.Equals(request.Headers[ClientHeader].ToString(), ClientHeaderValue, StringComparison.Ordinal))
            {
                await GuardResponses.WriteAsync(context, StatusCodes.Status400BadRequest, "missing_client_header");
                return;
            }
        }

        // 6. Cuerpo ≤ 16 KB. Se mira Content-Length y, si no viene (chunked), se cuenta al leer.
        if (request.ContentLength > MaxBodyBytes)
        {
            await GuardResponses.WriteAsync(context, StatusCodes.Status413PayloadTooLarge, "payload_too_large");
            return;
        }

        if (HttpMethods.IsPost(request.Method))
        {
            var buffered = await ReadLimitedAsync(request.Body, context.RequestAborted);
            if (buffered is null)
            {
                await GuardResponses.WriteAsync(context, StatusCodes.Status413PayloadTooLarge, "payload_too_large");
                return;
            }

            request.Body = buffered;
        }

        await _next(context);
    }

    private static bool IsJson(string? contentType)
    {
        if (!MediaTypeHeaderValue.TryParse(contentType, out var parsed)
            || !string.Equals(parsed.MediaType.Value, "application/json", StringComparison.OrdinalIgnoreCase))
        {
            return false;
        }

        // Si declara juego de caracteres, solo UTF-8 (el JSON de la API siempre lo es).
        return !parsed.Charset.HasValue || string.Equals(parsed.Charset.Value, "utf-8", StringComparison.OrdinalIgnoreCase);
    }

    /// <summary>Lee como mucho 16 KB + 1 byte; null si el cuerpo es mayor.</summary>
    private static async Task<MemoryStream?> ReadLimitedAsync(Stream body, CancellationToken cancellationToken)
    {
        var buffer = new MemoryStream();
        var chunk = new byte[4096];
        int read;
        while ((read = await body.ReadAsync(chunk, cancellationToken)) > 0)
        {
            if (buffer.Length + read > MaxBodyBytes)
            {
                return null;
            }

            buffer.Write(chunk, 0, read);
        }

        buffer.Position = 0;
        return buffer;
    }
}
