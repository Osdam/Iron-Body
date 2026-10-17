using IronBody.ReceiptAgent.Tickets;

namespace IronBody.ReceiptAgent.Printing;

/// <summary>Banderas JOB_STATUS_* de winspool (JOB_INFO_1.Status). Valores de la API de Windows.</summary>
public static class JobStatusFlags
{
    public const uint Paused = 0x00000001;
    public const uint Error = 0x00000002;
    public const uint Deleting = 0x00000004;
    public const uint Spooling = 0x00000008;
    public const uint Printing = 0x00000010;
    public const uint Offline = 0x00000020;
    public const uint PaperOut = 0x00000040;
    public const uint Printed = 0x00000080;
    public const uint BlockedDevq = 0x00000200;
    public const uint UserIntervention = 0x00000400;
    public const uint Restart = 0x00000800;
    public const uint Complete = 0x00001000;
}

public enum JobObservationKind
{
    /// <summary>El trabajo sigue en la cola; <see cref="JobObservation.Status"/> trae sus banderas.</summary>
    Present,

    /// <summary>GetJob dice que el trabajo ya no existe: salió de la cola.</summary>
    Gone,

    /// <summary>GetJob falló por otro motivo; no se sabe nada nuevo del trabajo.</summary>
    QueryFailed,
}

public readonly record struct JobObservation(JobObservationKind Kind, uint Status = 0, int Win32Error = 0)
{
    public static JobObservation Present(uint status) => new(JobObservationKind.Present, status);

    public static JobObservation Gone() => new(JobObservationKind.Gone);

    public static JobObservation Failed(int win32Error) => new(JobObservationKind.QueryFailed, 0, win32Error);
}

public enum TrackVerdict
{
    Continue,
    Accepted,
    Failed,
}

public readonly record struct TrackDecision(TrackVerdict Verdict, string? ErrorCode, bool Cancel)
{
    public static readonly TrackDecision Continue = new(TrackVerdict.Continue, null, false);
    public static readonly TrackDecision Accepted = new(TrackVerdict.Accepted, null, false);

    public static TrackDecision FailAndCancel(string errorCode) => new(TrackVerdict.Failed, errorCode, true);
}

/// <summary>
/// Decisión pura del seguimiento de un trabajo en la cola (contrato §3.3), separada de winspool para
/// probarla sin Windows. Un trabajo con error o que no avanza se CANCELA: si se quedara en la cola
/// podría salir horas después y duplicar una reimpresión.
/// </summary>
public static class JobTracker
{
    public static readonly TimeSpan PollInterval = TimeSpan.FromMilliseconds(250);
    public static readonly TimeSpan Timeout = TimeSpan.FromSeconds(12);

    private const uint OtherErrors = JobStatusFlags.Error | JobStatusFlags.BlockedDevq | JobStatusFlags.UserIntervention;

    public static TrackDecision Decide(JobObservation observation, TimeSpan elapsed, TimeSpan timeout)
    {
        if (observation.Kind == JobObservationKind.Gone)
        {
            return TrackDecision.Accepted;
        }

        if (observation.Kind == JobObservationKind.Present)
        {
            var status = observation.Status;

            // El contrato pone primero «impreso/completo»: si Windows ya lo dio por impreso no se
            // cancela ni se reporta error por una bandera de error que quedó colgada.
            if ((status & (JobStatusFlags.Printed | JobStatusFlags.Complete)) != 0)
            {
                return TrackDecision.Accepted;
            }

            // De lo más concreto a lo más genérico: ERROR suele venir junto a OFFLINE o PAPEROUT.
            if ((status & JobStatusFlags.PaperOut) != 0)
            {
                return TrackDecision.FailAndCancel(ErrorCodes.PaperOut);
            }

            if ((status & JobStatusFlags.Offline) != 0)
            {
                return TrackDecision.FailAndCancel(ErrorCodes.PrinterOffline);
            }

            if ((status & OtherErrors) != 0)
            {
                return TrackDecision.FailAndCancel(ErrorCodes.PrinterError);
            }
        }

        // Presente sin error, o consulta fallida: se sigue esperando hasta el límite.
        return elapsed >= timeout ? TrackDecision.FailAndCancel(ErrorCodes.JobTimeout) : TrackDecision.Continue;
    }
}

/// <summary>
/// Bucle de seguimiento: consulta, decide y espera. El reloj y la espera se inyectan para que las
/// pruebas recorran secuencias de 12 s sin esperar.
/// </summary>
public static class SpoolJobMonitor
{
    public static async Task<TrackDecision> TrackAsync(
        Func<JobObservation> query,
        Func<TimeSpan> elapsed,
        Func<TimeSpan, Task> delay,
        TimeSpan pollInterval,
        TimeSpan timeout)
    {
        while (true)
        {
            var decision = JobTracker.Decide(query(), elapsed(), timeout);
            if (decision.Verdict != TrackVerdict.Continue)
            {
                return decision;
            }

            await delay(pollInterval).ConfigureAwait(false);
        }
    }
}
