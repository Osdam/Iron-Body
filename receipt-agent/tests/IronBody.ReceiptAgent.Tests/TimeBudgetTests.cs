using System.Diagnostics;
using System.Net;
using System.Text.Json.Nodes;
using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Tests.Support;
using IronBody.ReceiptAgent.Tickets;
using Microsoft.Extensions.DependencyInjection;

namespace IronBody.ReceiptAgent.Tests;

/// <summary>
/// Presupuesto de tiempo de un ticket: el CRM corta a los 30 s y el trabajo activo debe caber en 25 s.
/// Las pruebas usan presupuestos pequeños con el mismo reparto para no esperar de verdad.
/// </summary>
public sealed class TimeBudgetTests
{
    [Fact]
    public void Default_budget_fits_the_worst_case_inside_25_seconds()
    {
        var budget = new TicketTimeBudget();

        // Canje + seguimiento del trabajo + todos los intentos de report con sus esperas.
        var worstCase = budget.RedeemTimeout + JobTracker.Timeout
            + (budget.ReportAttemptTimeout * budget.ReportMaxAttempts) + (budget.ReportRetryDelay * (budget.ReportMaxAttempts - 1));

        Assert.Equal(TimeSpan.FromSeconds(25), budget.Total);
        Assert.Equal(TimeSpan.FromSeconds(8), budget.RedeemTimeout);
        Assert.Equal(TimeSpan.FromSeconds(12), JobTracker.Timeout);
        Assert.True(worstCase <= TimeSpan.FromSeconds(24.5), $"peor caso {worstCase.TotalSeconds} s");
        Assert.True(budget.Total < TimeSpan.FromSeconds(30), "debe quedar margen bajo el corte de 30 s del CRM");
    }

    [Fact]
    public async Task Redeem_slower_than_its_limit_is_api_unreachable_without_waiting_for_the_server()
    {
        await using var host = await AgentTestHost.StartAsync(budget: new TicketTimeBudget { RedeemTimeout = TimeSpan.FromMilliseconds(300) });
        host.ConfigureStation();
        host.Backend.RedeemDelay = TimeSpan.FromSeconds(5);
        var clock = Stopwatch.StartNew();

        var result = await host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("api_unreachable", (string?)result["error_code"]);
        Assert.True(clock.Elapsed < TimeSpan.FromSeconds(3), $"tardó {clock.Elapsed.TotalSeconds} s");
        Assert.Empty(host.Printer.PrintCalls);
    }

    [Fact]
    public async Task Slow_report_attempts_are_cut_at_their_limit_and_leave_reported_false()
    {
        await using var host = await AgentTestHost.StartAsync(budget: new TicketTimeBudget
        {
            ReportAttemptTimeout = TimeSpan.FromMilliseconds(200),
            MinimumReportAttempt = TimeSpan.FromMilliseconds(50),
            ReportRetryDelay = TimeSpan.Zero,
        });
        host.ConfigureStation();
        host.Backend.ReportDelay = TimeSpan.FromSeconds(5);
        var clock = Stopwatch.StartNew();

        var result = await host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("accepted", (string?)result["state"]);
        Assert.False((bool)result["reported"]!);
        Assert.Equal(2, host.Backend.ReportCalls.Count);
        Assert.True(clock.Elapsed < TimeSpan.FromSeconds(3), $"tardó {clock.Elapsed.TotalSeconds} s");
    }

    [Fact]
    public async Task Without_time_left_the_report_is_skipped_and_the_crm_closes_the_attempt()
    {
        await using var host = await AgentTestHost.StartAsync(budget: new TicketTimeBudget
        {
            Total = TimeSpan.FromMilliseconds(600),
            MinimumReportAttempt = TimeSpan.FromMilliseconds(200),
            ReportRetryDelay = TimeSpan.Zero,
        });
        host.ConfigureStation();
        host.Printer.Delay = TimeSpan.FromMilliseconds(700); // la impresión se come todo el presupuesto

        var result = await host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("accepted", (string?)result["state"]);
        Assert.False((bool)result["reported"]!);
        Assert.Empty(host.Backend.ReportCalls);
        var events = (await host.GetJsonAsync("/v1/diagnostics"))["events"]!.AsArray();
        Assert.Contains(events, e => (string?)e!["event"] == "report_skipped");
    }

    [Fact]
    public async Task Waiting_in_the_queue_does_not_consume_the_budget()
    {
        await using var host = await AgentTestHost.StartAsync(budget: new TicketTimeBudget
        {
            Total = TimeSpan.FromMilliseconds(1500),
            MinimumReportAttempt = TimeSpan.FromMilliseconds(200),
            ReportRetryDelay = TimeSpan.Zero,
        });
        host.ConfigureStation();
        var gate = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        host.Printer.Gate = gate;
        var queue = host.App.Services.GetRequiredService<PrintQueue>();
        try
        {
            var first = host.PostTicketAsync(AgentTestHost.NewTicket());
            await host.Printer.FirstPrintStarted.Task.WaitAsync(TimeSpan.FromSeconds(10));
            var second = host.PostTicketAsync(AgentTestHost.NewTicket());
            await TicketFlowTests.WaitUntil(() => queue.Pending == 2);

            // El segundo espera en la cola más que todo su presupuesto.
            await Task.Delay(TimeSpan.FromSeconds(2));
            host.Printer.Gate = null;
            gate.SetResult();
            var results = await Task.WhenAll(first, second);

            // El primero estuvo «imprimiendo» (tiempo activo) más de 1,5 s: no le queda para reportar.
            Assert.False((bool)results[0]["reported"]!);
            // El segundo solo esperó en la cola: su tiempo activo es mínimo y sí reporta.
            Assert.Equal("accepted", (string?)results[1]["state"]);
            Assert.True((bool)results[1]["reported"]!);
        }
        finally
        {
            gate.TrySetResult();
        }
    }
}
