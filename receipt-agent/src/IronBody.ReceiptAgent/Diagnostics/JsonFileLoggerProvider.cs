using System.Globalization;
using System.Text;
using System.Text.Json;
using Microsoft.Extensions.Logging;

namespace IronBody.ReceiptAgent.Diagnostics;

/// <summary>
/// Log en archivo, un objeto JSON por línea, un archivo por día local
/// (logs\receipt-printer-AAAAMMDD.log) y retención de 14 días. Es lo que el técnico abre en el PC de
/// recepción; el Visor de eventos solo recibe avisos y errores.
/// </summary>
public sealed class JsonFileLoggerProvider : ILoggerProvider
{
    public const int RetentionDays = 14;
    public const string FilePrefix = "receipt-printer-";
    public const string FileExtension = ".log";

    // Escape relajado: el archivo lo lee una persona («generó», no «gener\u00F3»). Los controles y los
    // saltos de línea se siguen escapando, así que cada evento ocupa exactamente una línea.
    private static readonly JsonSerializerOptions LineJson = new()
    {
        WriteIndented = false,
        Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping,
    };

    private readonly string _directory;
    private readonly TimeProvider _time;
    private readonly object _gate = new();
    private StreamWriter? _writer;
    private DateOnly _writerDate;
    private DateTimeOffset _lastFailureReport = DateTimeOffset.MinValue;

    public JsonFileLoggerProvider(string directory, TimeProvider time)
    {
        _directory = directory;
        _time = time;
    }

    public ILogger CreateLogger(string categoryName) => new JsonFileLogger(categoryName, this);

    public void Dispose()
    {
        lock (_gate)
        {
            _writer?.Dispose();
            _writer = null;
        }
    }

    internal void Write(LogLevel level, string category, EventId eventId, string message, Exception? exception)
    {
        var now = _time.GetLocalNow();
        var line = JsonSerializer.Serialize(new LogLine(
            IsoTime.Format(now), LevelName(level), category, eventId.Id == 0 ? null : eventId.Id, message, exception?.ToString()), LineJson);

        lock (_gate)
        {
            try
            {
                EnsureWriter(DateOnly.FromDateTime(now.DateTime));
                _writer!.WriteLine(line);
                _writer.Flush();
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
            {
                // No se puede registrar el fallo del log en el propio log, y lanzar desde un logger
                // rompería la operación que se estaba registrando. Se avisa por stderr (como mucho cada
                // 5 minutos) y el Visor de eventos sigue recibiendo los avisos y errores.
                _writer?.Dispose();
                _writer = null;
                if (now - _lastFailureReport > TimeSpan.FromMinutes(5))
                {
                    _lastFailureReport = now;
                    Console.Error.WriteLine($"[receipt-printer] No se pudo escribir el log en {_directory}: {ex.Message}");
                }
            }
        }
    }

    private void EnsureWriter(DateOnly today)
    {
        if (_writer is not null && _writerDate == today)
        {
            return;
        }

        _writer?.Dispose();
        Directory.CreateDirectory(_directory);
        var path = Path.Combine(_directory, $"{FilePrefix}{today.ToString("yyyyMMdd", CultureInfo.InvariantCulture)}{FileExtension}");
        var stream = new FileStream(path, FileMode.Append, FileAccess.Write, FileShare.ReadWrite | FileShare.Delete);
        _writer = new StreamWriter(stream, new UTF8Encoding(encoderShouldEmitUTF8Identifier: false));
        _writerDate = today;
        PurgeOldFiles();
    }

    /// <summary>Borra los archivos de log con más de 14 días. Se ejecuta al abrir el archivo del día.</summary>
    internal void PurgeOldFiles()
    {
        var limit = _time.GetUtcNow().UtcDateTime.AddDays(-RetentionDays);
        foreach (var file in Directory.EnumerateFiles(_directory, $"{FilePrefix}*{FileExtension}"))
        {
            try
            {
                if (File.GetLastWriteTimeUtc(file) < limit)
                {
                    File.Delete(file);
                }
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
            {
                // Un archivo viejo bloqueado (abierto en un editor) no debe impedir escribir el log de
                // hoy; se vuelve a intentar al abrir el archivo del día siguiente o al reiniciar.
            }
        }
    }

    private static string LevelName(LogLevel level) => level switch
    {
        LogLevel.Trace => "trace",
        LogLevel.Debug => "debug",
        LogLevel.Information => "info",
        LogLevel.Warning => "warn",
        LogLevel.Error => "error",
        LogLevel.Critical => "critical",
        _ => "none",
    };

    private sealed record LogLine(
        [property: System.Text.Json.Serialization.JsonPropertyName("at")] string At,
        [property: System.Text.Json.Serialization.JsonPropertyName("level")] string Level,
        [property: System.Text.Json.Serialization.JsonPropertyName("category")] string Category,
        [property: System.Text.Json.Serialization.JsonPropertyName("event_id")] int? EventId,
        [property: System.Text.Json.Serialization.JsonPropertyName("message")] string Message,
        [property: System.Text.Json.Serialization.JsonPropertyName("exception")] string? Exception);

    private sealed class JsonFileLogger : ILogger
    {
        private readonly string _category;
        private readonly JsonFileLoggerProvider _provider;

        public JsonFileLogger(string category, JsonFileLoggerProvider provider)
        {
            _category = category;
            _provider = provider;
        }

        public IDisposable? BeginScope<TState>(TState state) where TState : notnull => null;

        public bool IsEnabled(LogLevel logLevel) => logLevel != LogLevel.None;

        public void Log<TState>(LogLevel logLevel, EventId eventId, TState state, Exception? exception, Func<TState, Exception?, string> formatter)
        {
            if (!IsEnabled(logLevel))
            {
                return;
            }

            _provider.Write(logLevel, _category, eventId, formatter(state, exception), exception);
        }
    }
}
