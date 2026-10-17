namespace IronBody.ReceiptAgent.Tickets;

/// <summary>
/// Presupuesto de tiempo de UN ticket. El CRM corta su petición a los 30 s; el trabajo activo del
/// servicio (canje + impresión + seguimiento + report) cabe en 25 s, así que con la cola vacía el
/// resultado llega antes del corte. La espera en la cola NO cuenta en este presupuesto (solo se mide
/// el tiempo en que el trabajo se está ejecutando), pero en los 30 s del CRM sí: con varios tickets
/// en cola y una impresora atascada, el CRM puede cortar y cerrar su intento como error mientras el
/// servicio aún lo procesa (el report llegaría después como already_finished). En la práctica un
/// cobro da como mucho dos recibos («Cobrar todo», uno por caja).
/// </summary>
/// <remarks>
/// Cuentas del peor caso: canje 8 s + entrega al spooler (normalmente &lt; 0,5 s) + seguimiento 12 s
/// (<see cref="Printing.JobTracker.Timeout"/>) ≈ 20,5 s; quedan ≈ 4,5 s para el report: 2 intentos de
/// hasta 2 s con 0,25 s entre ellos. Si no queda al menos medio segundo, no se reporta
/// (reported=false) y el CRM cierra el intento con su PATCH.
/// </remarks>
public sealed class TicketTimeBudget
{
    public TimeSpan Total { get; init; } = TimeSpan.FromSeconds(25);

    public TimeSpan RedeemTimeout { get; init; } = TimeSpan.FromSeconds(8);

    public TimeSpan ReportAttemptTimeout { get; init; } = TimeSpan.FromSeconds(2);

    public int ReportMaxAttempts { get; init; } = 2;

    public TimeSpan ReportRetryDelay { get; init; } = TimeSpan.FromMilliseconds(250);

    /// <summary>Por debajo de esto no se intenta el report: no daría tiempo a una respuesta útil.</summary>
    public TimeSpan MinimumReportAttempt { get; init; } = TimeSpan.FromMilliseconds(500);
}
