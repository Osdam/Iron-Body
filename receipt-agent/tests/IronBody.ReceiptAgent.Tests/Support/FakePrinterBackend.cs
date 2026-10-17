using System.Collections.Concurrent;
using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Tickets;

namespace IronBody.ReceiptAgent.Tests.Support;

public sealed record PrintedJob(string PrinterName, string DocumentName, byte[] Data);

/// <summary>Impresora simulada: registra lo que se le entrega y devuelve el resultado que se le diga.</summary>
public sealed class FakePrinterBackend : IPrinterBackend
{
    private readonly ConcurrentQueue<PrintedJob> _jobs = new();
    private int _nextJobId = 100;

    public List<PrinterInfo> Printers { get; } = new()
    {
        new PrinterInfo("POS-58", "POS-58", "USB001", false, PrinterStatuses.Ready),
        new PrinterInfo("Microsoft Print to PDF", "Microsoft Print To PDF", "PORTPROMPT:", true, PrinterStatuses.Ready),
    };

    /// <summary>Resultado de cada impresión; null = aceptado con el siguiente id.</summary>
    public Func<PrintedJob, SpoolResult?> Outcome { get; set; } = _ => null;

    /// <summary>Duración simulada de cada impresión (spooler + seguimiento).</summary>
    public TimeSpan Delay { get; set; }

    /// <summary>Si se fija, cada impresión espera a que se complete (para llenar la cola).</summary>
    public TaskCompletionSource? Gate { get; set; }

    /// <summary>Se completa cuando una impresión empieza (antes de esperar a <see cref="Gate"/>).</summary>
    public TaskCompletionSource FirstPrintStarted { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);

    public IReadOnlyList<PrintedJob> Jobs => _jobs.ToArray();

    /// <summary>Nombres con los que se llamó a PrintAsync (para comprobar que uno rechazado nunca llega).</summary>
    public ConcurrentQueue<string> PrintCalls { get; } = new();

    public IReadOnlyList<PrinterInfo> ListPrinters() => Printers;

    public async Task<SpoolResult> PrintAsync(string printerName, string documentName, byte[] data, CancellationToken cancellationToken)
    {
        PrintCalls.Enqueue(printerName);
        if (!Printers.Any(p => string.Equals(p.Name, printerName, StringComparison.OrdinalIgnoreCase)))
        {
            return SpoolResult.Fail(ErrorCodes.PrinterNotFound);
        }

        var job = new PrintedJob(printerName, documentName, data);
        _jobs.Enqueue(job);
        FirstPrintStarted.TrySetResult();
        if (Gate is not null)
        {
            await Gate.Task;
        }

        if (Delay > TimeSpan.Zero)
        {
            await Task.Delay(Delay, CancellationToken.None);
        }

        return Outcome(job) ?? SpoolResult.Ok(Interlocked.Increment(ref _nextJobId), data.Length);
    }
}
