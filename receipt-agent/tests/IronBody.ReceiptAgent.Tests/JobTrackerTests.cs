using IronBody.ReceiptAgent.Printing;
using static IronBody.ReceiptAgent.Printing.JobStatusFlags;

namespace IronBody.ReceiptAgent.Tests;

/// <summary>
/// Seguimiento del trabajo en la cola (contrato §3.3) con secuencias simuladas de GetJob: la misma
/// decisión que usa el spooler real en Windows, probada sin Windows.
/// </summary>
public sealed class JobTrackerTests
{
    private static readonly TimeSpan Early = TimeSpan.FromSeconds(1);

    [Fact]
    public void Job_gone_from_the_queue_is_accepted()
    {
        Assert.Equal(TrackDecision.Accepted, JobTracker.Decide(JobObservation.Gone(), Early, JobTracker.Timeout));
    }

    [Theory]
    [InlineData(Printed)]
    [InlineData(Complete)]
    [InlineData(Printed | Error)]
    [InlineData(Complete | Deleting)]
    public void Printed_or_complete_is_accepted(uint status)
    {
        Assert.Equal(TrackDecision.Accepted, JobTracker.Decide(JobObservation.Present(status), Early, JobTracker.Timeout));
    }

    [Theory]
    [InlineData(PaperOut, "paper_out")]
    [InlineData(PaperOut | Error, "paper_out")]
    [InlineData(Offline, "printer_offline")]
    [InlineData(Offline | Error | JobStatusFlags.Printing, "printer_offline")]
    [InlineData(Error, "printer_error")]
    [InlineData(BlockedDevq, "printer_error")]
    [InlineData(UserIntervention, "printer_error")]
    public void Error_flags_fail_and_cancel_the_job(uint status, string expectedCode)
    {
        var decision = JobTracker.Decide(JobObservation.Present(status), Early, JobTracker.Timeout);

        Assert.Equal(TrackVerdict.Failed, decision.Verdict);
        Assert.Equal(expectedCode, decision.ErrorCode);
        Assert.True(decision.Cancel);
    }

    [Theory]
    [InlineData(0u)]
    [InlineData(Spooling)]
    [InlineData(JobStatusFlags.Printing)]
    [InlineData(Paused)]
    [InlineData(Restart)]
    public void Job_still_in_queue_without_errors_keeps_waiting_until_the_timeout(uint status)
    {
        Assert.Equal(TrackDecision.Continue, JobTracker.Decide(JobObservation.Present(status), TimeSpan.FromSeconds(11.9), JobTracker.Timeout));

        var late = JobTracker.Decide(JobObservation.Present(status), TimeSpan.FromSeconds(12), JobTracker.Timeout);
        Assert.Equal(TrackVerdict.Failed, late.Verdict);
        Assert.Equal("job_timeout", late.ErrorCode);
        Assert.True(late.Cancel);
    }

    [Fact]
    public void Failed_query_is_not_a_verdict_until_the_timeout()
    {
        Assert.Equal(TrackDecision.Continue, JobTracker.Decide(JobObservation.Failed(5), Early, JobTracker.Timeout));
        Assert.Equal("job_timeout", JobTracker.Decide(JobObservation.Failed(5), JobTracker.Timeout, JobTracker.Timeout).ErrorCode);
    }

    [Fact]
    public async Task Sequence_spooling_printing_gone_is_accepted()
    {
        var (decision, queries) = await Track(JobObservation.Present(Spooling), JobObservation.Present(JobStatusFlags.Printing), JobObservation.Gone());

        Assert.Equal(TrackDecision.Accepted, decision);
        Assert.Equal(3, queries);
    }

    [Fact]
    public async Task Sequence_that_turns_into_an_error_is_cancelled_as_printer_error()
    {
        var (decision, _) = await Track(JobObservation.Present(Spooling), JobObservation.Present(JobStatusFlags.Printing), JobObservation.Present(JobStatusFlags.Printing | Error));

        Assert.Equal(TrackDecision.FailAndCancel("printer_error"), decision);
    }

    [Fact]
    public async Task Printer_unplugged_mid_job_is_cancelled_as_offline()
    {
        var (decision, _) = await Track(JobObservation.Present(Spooling), JobObservation.Failed(5), JobObservation.Present(Offline | Error));

        Assert.Equal(TrackDecision.FailAndCancel("printer_offline"), decision);
    }

    [Fact]
    public async Task Job_stuck_in_the_queue_is_cancelled_at_12_seconds_after_49_polls()
    {
        var (decision, queries) = await Track(Enumerable.Repeat(JobObservation.Present(Spooling), 1000).ToArray());

        Assert.Equal(TrackDecision.FailAndCancel("job_timeout"), decision);
        // Consultas en t = 0, 0.25, …, 12.0 s: 49 en total, cada 250 ms.
        Assert.Equal(49, queries);
    }

    /// <summary>Recorre la secuencia con un reloj simulado que avanza lo que se espera.</summary>
    private static async Task<(TrackDecision Decision, int Queries)> Track(params JobObservation[] sequence)
    {
        var elapsed = TimeSpan.Zero;
        var index = 0;
        var decision = await SpoolJobMonitor.TrackAsync(
            () => sequence[Math.Min(index++, sequence.Length - 1)],
            () => elapsed,
            delay =>
            {
                Assert.Equal(TimeSpan.FromMilliseconds(250), delay);
                elapsed += delay;
                return Task.CompletedTask;
            },
            JobTracker.PollInterval,
            JobTracker.Timeout);
        return (decision, index);
    }
}
