using System.Text.Json.Serialization;
using Microsoft.Extensions.Logging;

namespace IronBody.ReceiptAgent.Diagnostics;

/// <summary>Evento de diagnóstico (GET /v1/diagnostics). Sin nombres de clientes, importes ni tickets.</summary>
public sealed record DiagnosticEvent(
    [property: JsonPropertyName("at")] string At,
    [property: JsonPropertyName("level")] string Level,
    [property: JsonPropertyName("event")] string Event,
    [property: JsonPropertyName("purpose")] string? Purpose,
    [property: JsonPropertyName("receipt_number")] string? ReceiptNumber,
    [property: JsonPropertyName("error_code")] string? ErrorCode,
    [property: JsonPropertyName("message")] string Message);

/// <summary>
/// Búfer circular de los últimos 100 eventos, y a la vez su anotación en el log. Todo lo que entra
/// aquí lo construye el propio servicio (códigos, números de recibo, nombres de impresora): nunca el
/// texto de las líneas del recibo ni el ticket, que son los que llevan datos personales o autorizan.
/// </summary>
public sealed class DiagnosticsBuffer
{
    public const int Capacity = 100;

    private readonly object _gate = new();
    private readonly Queue<DiagnosticEvent> _events = new(Capacity);
    private readonly TimeProvider _time;
    private readonly ILogger<DiagnosticsBuffer> _logger;

    public DiagnosticsBuffer(TimeProvider time, ILogger<DiagnosticsBuffer> logger)
    {
        _time = time;
        _logger = logger;
    }

    public void Info(string evt, string message, string? purpose = null, string? receiptNumber = null, string? errorCode = null) =>
        Add("info", LogLevel.Information, evt, message, purpose, receiptNumber, errorCode, null);

    public void Warn(string evt, string message, string? purpose = null, string? receiptNumber = null, string? errorCode = null) =>
        Add("warn", LogLevel.Warning, evt, message, purpose, receiptNumber, errorCode, null);

    public void Error(string evt, string message, string? purpose = null, string? receiptNumber = null, string? errorCode = null, Exception? exception = null) =>
        Add("error", LogLevel.Error, evt, message, purpose, receiptNumber, errorCode, exception);

    public IReadOnlyList<DiagnosticEvent> Snapshot()
    {
        lock (_gate)
        {
            // Más recientes primero: es lo que mira el técnico.
            return _events.Reverse().ToArray();
        }
    }

    private void Add(string level, LogLevel logLevel, string evt, string message, string? purpose, string? receiptNumber, string? errorCode, Exception? exception)
    {
        var item = new DiagnosticEvent(IsoTime.Format(_time.GetUtcNow()), level, evt, purpose, receiptNumber, errorCode, message);
        lock (_gate)
        {
            if (_events.Count == Capacity)
            {
                _events.Dequeue();
            }

            _events.Enqueue(item);
        }

        _logger.Log(logLevel, exception,
            "{Event}: {Message} (propósito={Purpose}, recibo={ReceiptNumber}, código={ErrorCode})",
            evt, message, purpose ?? "-", receiptNumber ?? "-", errorCode ?? "-");
    }
}

public static class IsoTime
{
    /// <summary>ISO-8601 en UTC con milisegundos y sufijo Z.</summary>
    public static string Format(DateTimeOffset value) =>
        value.UtcDateTime.ToString("yyyy-MM-dd'T'HH:mm:ss.fff'Z'", System.Globalization.CultureInfo.InvariantCulture);
}
