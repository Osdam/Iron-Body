using System.Threading.Channels;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

namespace IronBody.ReceiptAgent.Printing;

/// <summary>Trabajo de la cola: se ejecuta en el único trabajador o se abandona al detener el servicio.</summary>
public abstract class QueueWork
{
    internal abstract Task RunAsync();

    internal abstract void Abandon();
}

public sealed class QueueWork<T> : QueueWork
{
    private readonly Func<Task<T>> _run;
    private readonly Func<T> _abandoned;
    private readonly TaskCompletionSource<T> _completion = new(TaskCreationOptions.RunContinuationsAsynchronously);

    /// <param name="run">Lo que hace el trabajador.</param>
    /// <param name="abandoned">Resultado si el servicio se detiene antes de llegar a este trabajo.</param>
    public QueueWork(Func<Task<T>> run, Func<T> abandoned)
    {
        _run = run;
        _abandoned = abandoned;
    }

    public Task<T> Completion => _completion.Task;

    internal override async Task RunAsync()
    {
        try
        {
            _completion.TrySetResult(await _run().ConfigureAwait(false));
        }
        catch (Exception ex)
        {
            // Se entrega a quien espera el resultado, que lo registra y lo traduce a un código.
            _completion.TrySetException(ex);
        }
    }

    internal override void Abandon() => _completion.TrySetResult(_abandoned());
}

/// <summary>
/// Cola de UN trabajador (contrato §3.2): la impresora recibe un trabajo cada vez, y los ajustes de
/// un configure nunca cambian a mitad de un recibo porque pasan por la misma cola. Como mucho 20 en
/// espera; el siguiente recibe <c>queue_full</c> en vez de esperar sin límite.
/// </summary>
public sealed class PrintQueue : BackgroundService
{
    public const int MaxWaiting = 20;

    private readonly Channel<QueueWork> _channel = Channel.CreateBounded<QueueWork>(new BoundedChannelOptions(MaxWaiting)
    {
        SingleReader = true,
        SingleWriter = false,
        FullMode = BoundedChannelFullMode.Wait,
    });

    private readonly ILogger<PrintQueue> _logger;
    private int _pending;

    public PrintQueue(ILogger<PrintQueue> logger)
    {
        _logger = logger;
    }

    /// <summary>Trabajos en espera más el que se está imprimiendo.</summary>
    public int Pending => Volatile.Read(ref _pending);

    public bool TryEnqueue(QueueWork work)
    {
        Interlocked.Increment(ref _pending);
        if (_channel.Writer.TryWrite(work))
        {
            return true;
        }

        Interlocked.Decrement(ref _pending);
        return false;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        try
        {
            await foreach (var work in _channel.Reader.ReadAllAsync(stoppingToken).ConfigureAwait(false))
            {
                // Sin token: un trabajo empezado se termina (y se reporta) aunque el servicio se esté
                // deteniendo; dejarlo a medias podría imprimirlo sin que nadie lo sepa.
                await work.RunAsync().ConfigureAwait(false);
                Interlocked.Decrement(ref _pending);
            }
        }
        catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
        {
            // Parada normal del servicio: se sigue abajo para cerrar la cola.
        }

        _channel.Writer.TryComplete();
        var abandoned = 0;
        while (_channel.Reader.TryRead(out var work))
        {
            work.Abandon();
            Interlocked.Decrement(ref _pending);
            abandoned++;
        }

        if (abandoned > 0)
        {
            _logger.LogWarning("El servicio se detuvo con {Count} trabajos en espera; se devolvieron como no impresos.", abandoned);
        }
    }
}
