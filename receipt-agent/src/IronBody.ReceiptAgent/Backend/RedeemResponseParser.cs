using System.Net;
using System.Text.Json;
using System.Text.RegularExpressions;
using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Settings;
using IronBody.ReceiptAgent.Tickets;

namespace IronBody.ReceiptAgent.Backend;

/// <summary>
/// Interpreta las respuestas del backend. La forma (tipos, campos obligatorios) se comprueba aquí y
/// su fallo es <c>incompatible_job</c>; las reglas de contenido del §2 (líneas, estilos, longitudes)
/// las comprueba después <see cref="PrintDocumentValidator"/> y su fallo es <c>invalid_job</c>.
/// </summary>
public static partial class RedeemResponseParser
{
    public static RedeemOutcome ParseSuccess(string body)
    {
        JsonElement root;
        try
        {
            using var document = JsonDocument.Parse(body);
            root = document.RootElement.Clone();
        }
        catch (JsonException)
        {
            return Incompatible("cuerpo 2xx que no es JSON");
        }

        if (root.ValueKind != JsonValueKind.Object
            || !root.TryGetProperty("ok", out var ok) || ok.ValueKind != JsonValueKind.True)
        {
            return Incompatible("respuesta 2xx sin ok=true");
        }

        var purpose = root.TryGetProperty("purpose", out var p) && p.ValueKind == JsonValueKind.String ? p.GetString() : null;
        switch (purpose)
        {
            case TicketPurposes.Print:
            case TicketPurposes.Test:
                if (!root.TryGetProperty("job", out var job) || job.ValueKind != JsonValueKind.Object)
                {
                    return Incompatible($"propósito {purpose} sin objeto job");
                }

                var parsed = ParseJob(job);
                return parsed is null ? Incompatible("job con campos ausentes o de tipo inesperado") : RedeemOutcome.ForJob(purpose, parsed);

            case TicketPurposes.Configure:
                if (!root.TryGetProperty("settings", out var settings) || settings.ValueKind != JsonValueKind.Object)
                {
                    return Incompatible("propósito configure sin objeto settings");
                }

                var parsedSettings = ParseSettings(settings);
                return parsedSettings is null
                    ? Incompatible("settings con campos ausentes o de tipo inesperado")
                    : RedeemOutcome.ForSettings(parsedSettings);

            default:
                return Incompatible("propósito ausente o desconocido");
        }
    }

    /// <summary>
    /// Estado HTTP + <c>code</c> del cuerpo → código para el CRM. Además del §6, el backend implementado
    /// devuelve 429 rate_limited, 422 invalid_request/invalid_outcome/job_too_long y 500 layout_error
    /// (mapeo acordado con el lead). Cualquier otra combinación es api_error: nunca se revienta.
    /// </summary>
    public static string MapError(HttpStatusCode status, string? code) => (int)status switch
    {
        // Solo es «ticket no válido» si el backend lo dice; un 404 sin ese code es una ruta que no existe.
        404 when code is ErrorCodes.TicketInvalid => ErrorCodes.TicketInvalid,
        410 => ErrorCodes.TicketExpired,
        409 when code is ErrorCodes.TicketUsed or ErrorCodes.AttemptClosed => code!,
        403 when code is ErrorCodes.StationMismatch or ErrorCodes.StationInactive => code!,
        426 => ErrorCodes.ProtocolUnsupported,
        429 => ErrorCodes.RateLimited,
        // El backend no pudo maquetar un documento de más de 400 líneas: el documento es inválido.
        422 when code is "job_too_long" => ErrorCodes.InvalidJob,
        503 when code is ErrorCodes.AgentDisabled => ErrorCodes.AgentDisabled,
        // Hubo respuesta, pero no una prevista (500/layout_error, 422 invalid_request, 3xx, un 409/403
        // sin código conocido, un 503 de mantenimiento…): el servidor respondió con error.
        _ => ErrorCodes.ApiError,
    };

    /// <summary>
    /// Lee <c>code</c> de un cuerpo de error <c>{ok:false, code, message}</c>. Solo se acepta un
    /// identificador corto en minúsculas: es lo único del cuerpo que llega a los logs.
    /// </summary>
    public static string? ReadErrorCode(string? body)
    {
        if (string.IsNullOrWhiteSpace(body))
        {
            return null;
        }

        try
        {
            using var document = JsonDocument.Parse(body);
            var code = document.RootElement.ValueKind == JsonValueKind.Object
                       && document.RootElement.TryGetProperty("code", out var element)
                       && element.ValueKind == JsonValueKind.String
                ? element.GetString()
                : null;
            return code is not null && ErrorCodePattern().IsMatch(code) ? code : null;
        }
        catch (JsonException)
        {
            // Cuerpo de error no JSON (página de un proxy, por ejemplo): se decide solo por el estado.
            return null;
        }
    }

    [GeneratedRegex(@"^[a-z0-9_]{1,64}\z", RegexOptions.CultureInvariant)]
    private static partial Regex ErrorCodePattern();

    private static PrintDocument? ParseJob(JsonElement job)
    {
        if (!TryOptionalString(job, "receipt_number", out var receiptNumber)
            || !TryOptionalString(job, "copy_label", out var copyLabel)
            || !TryRequiredString(job, "kind", out var kind)
            || !TryRequiredInt(job, "columns", out var columns)
            || !TryRequiredInt(job, "feed_lines", out var feedLines)
            || !job.TryGetProperty("cut", out var cut) || cut.ValueKind is not (JsonValueKind.True or JsonValueKind.False)
            || !job.TryGetProperty("lines", out var lines) || lines.ValueKind != JsonValueKind.Array)
        {
            return null;
        }

        var parsedLines = new List<PrintLine>(lines.GetArrayLength());
        foreach (var line in lines.EnumerateArray())
        {
            if (line.ValueKind != JsonValueKind.Object
                || !TryRequiredString(line, "text", out var text)
                || !TryRequiredString(line, "style", out var style))
            {
                return null;
            }

            parsedLines.Add(new PrintLine(text, style));
        }

        return new PrintDocument(receiptNumber, copyLabel, kind, columns, feedLines, cut.GetBoolean(), parsedLines);
    }

    private static ConfigureSettings? ParseSettings(JsonElement settings)
    {
        if (!TryOptionalString(settings, "station_name", out var stationName)
            || !TryOptionalString(settings, "printer_name", out var printerName)
            || !TryRequiredInt(settings, "columns", out var columns)
            || !TryRequiredString(settings, "codepage", out var codepage)
            || !TryRequiredInt(settings, "feed_lines", out var feedLines))
        {
            return null;
        }

        return new ConfigureSettings(stationName, printerName, columns, codepage, feedLines);
    }

    private static bool TryRequiredString(JsonElement obj, string name, out string value)
    {
        if (obj.TryGetProperty(name, out var element) && element.ValueKind == JsonValueKind.String)
        {
            value = element.GetString()!;
            return true;
        }

        value = string.Empty;
        return false;
    }

    /// <summary>Cadena o null; ausente cuenta como null. Otro tipo es incompatible.</summary>
    private static bool TryOptionalString(JsonElement obj, string name, out string? value)
    {
        value = null;
        if (!obj.TryGetProperty(name, out var element) || element.ValueKind == JsonValueKind.Null)
        {
            return true;
        }

        if (element.ValueKind != JsonValueKind.String)
        {
            return false;
        }

        value = element.GetString();
        return true;
    }

    private static bool TryRequiredInt(JsonElement obj, string name, out int value)
    {
        value = 0;
        return obj.TryGetProperty(name, out var element) && element.ValueKind == JsonValueKind.Number && element.TryGetInt32(out value);
    }

    private static RedeemOutcome Incompatible(string detail) => RedeemOutcome.Fail(ErrorCodes.IncompatibleJob, detail);
}
