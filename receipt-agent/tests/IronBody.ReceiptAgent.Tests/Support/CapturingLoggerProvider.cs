using System.Collections.Concurrent;
using Microsoft.Extensions.Logging;

namespace IronBody.ReceiptAgent.Tests.Support;

/// <summary>Captura todo lo que el servicio registra, para comprobar que el ticket nunca aparece.</summary>
public sealed class CapturingLoggerProvider : ILoggerProvider
{
    private readonly ConcurrentQueue<string> _entries = new();

    public IReadOnlyList<string> Entries => _entries.ToArray();

    public string AllText => string.Join('\n', _entries);

    public ILogger CreateLogger(string categoryName) => new Logger(categoryName, _entries);

    public void Dispose()
    {
    }

    private sealed class Logger : ILogger
    {
        private readonly string _category;
        private readonly ConcurrentQueue<string> _entries;

        public Logger(string category, ConcurrentQueue<string> entries)
        {
            _category = category;
            _entries = entries;
        }

        public IDisposable? BeginScope<TState>(TState state) where TState : notnull => null;

        public bool IsEnabled(LogLevel logLevel) => true;

        public void Log<TState>(LogLevel logLevel, EventId eventId, TState state, Exception? exception, Func<TState, Exception?, string> formatter) =>
            _entries.Enqueue($"{logLevel} {_category}: {formatter(state, exception)} {exception}");
    }
}
