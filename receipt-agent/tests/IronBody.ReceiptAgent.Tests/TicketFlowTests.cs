using System.Net;
using System.Text.Json.Nodes;
using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Printing.EscPos;
using IronBody.ReceiptAgent.Tests.Support;
using IronBody.ReceiptAgent.Tickets;
using Microsoft.Extensions.DependencyInjection;

namespace IronBody.ReceiptAgent.Tests;

/// <summary>
/// POST /v1/tickets de punta a punta: guardas reales, cliente HTTP real contra un backend simulado,
/// cola real y una impresora simulada.
/// </summary>
public sealed class TicketFlowTests : IAsyncLifetime
{
    private const string CustomerName = "ANA MARIA PEREZ GOMEZ";
    private AgentTestHost _host = null!;

    public async Task InitializeAsync()
    {
        _host = await AgentTestHost.StartAsync();
        _host.ConfigureStation();
    }

    public async Task DisposeAsync() => await _host.DisposeAsync();

    private static JsonObject JobWithCustomer() => FakeBackendHandler.SampleJob(lines: new[]
    {
        ("IRON BODY WORKOUT", "title"),
        ("Recibo              RG-000123", "bold"),
        ($"Cliente: {CustomerName}", "normal"),
        ("Suscripción mensual  $ 120.000", "normal"),
        ("TOTAL                $ 120.000", "bold"),
    });

    [Fact]
    public async Task Print_is_accepted_by_the_spooler_and_reported()
    {
        var job = JobWithCustomer();
        _host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Json(HttpStatusCode.OK, FakeBackendHandler.PrintRedeem(job)));
        var ticket = AgentTestHost.NewTicket();

        var result = await _host.PostTicketAsync(ticket);

        var printed = Assert.Single(_host.Printer.Jobs);
        Assert.Equal("POS-58", printed.PrinterName);
        Assert.Equal("Recibo RG-000123", printed.DocumentName);
        var expectedBytes = EscPosRenderer.Render(new PrintDocument("RG-000123", null, "original", 32, 4, false, new[]
        {
            new PrintLine("IRON BODY WORKOUT", "title"),
            new PrintLine("Recibo              RG-000123", "bold"),
            new PrintLine($"Cliente: {CustomerName}", "normal"),
            new PrintLine("Suscripción mensual  $ 120.000", "normal"),
            new PrintLine("TOTAL                $ 120.000", "bold"),
        }), "cp850");
        Assert.Equal(expectedBytes, printed.Data);

        Assert.True((bool)result["ok"]!);
        Assert.Equal("print", (string?)result["purpose"]);
        Assert.Equal("accepted", (string?)result["state"]);
        Assert.Null(result["error_code"]);
        Assert.Equal(CashierMessages.PrintAccepted, (string?)result["message"]);
        Assert.Equal("RG-000123", (string?)result["receipt_number"]);
        Assert.Null(result["copy_label"]);
        Assert.Equal(101, (int)result["spool_job_id"]!);
        Assert.Equal("POS-58", (string?)result["printer_name"]);
        Assert.Equal(expectedBytes.Length, (int)result["printed_bytes"]!);
        Assert.True((bool)result["reported"]!);
        Assert.True(result.ContainsKey("settings"));
        Assert.Null(result["settings"]);
        Assert.Equal(12, result.Count);

        var redeem = Assert.Single(_host.Backend.RedeemCalls).Body;
        Assert.Equal(ticket, (string?)redeem["ticket"]);
        Assert.Equal(_host.CurrentSettings.StationKey, (string?)redeem["station_key"]);
        Assert.Equal(
            """{"version":"1.0.0","protocol":1,"printer_name":"POS-58","columns":32,"codepage":"cp850"}""",
            redeem["agent"]!.ToJsonString());

        var report = Assert.Single(_host.Backend.ReportCalls).Body;
        Assert.Equal(ticket, (string?)report["ticket"]);
        Assert.Equal(_host.CurrentSettings.StationKey, (string?)report["station_key"]);
        Assert.Equal("accepted", (string?)report["outcome"]);
        Assert.Null(report["error_code"]);
        Assert.Equal(101, (int)report["spool_job_id"]!);
        Assert.Equal("POS-58", (string?)report["printer_name"]);

        Assert.All(_host.Backend.RequestUris, uri => Assert.StartsWith("https://api.ironbodyneiva.cloud/api/receipt-agent/", uri.ToString()));
    }

    [Fact]
    public async Task Backend_requests_are_json_with_content_length_and_ask_for_json()
    {
        await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal(2, _host.Backend.Calls.Count);
        Assert.All(_host.Backend.Calls, call =>
        {
            // Sin «chunked»: algunos servidores PHP no aceptan cuerpos sin longitud.
            Assert.NotNull(call.ContentLength);
            Assert.True(call.ContentLength > 0);
            Assert.Equal("application/json; charset=utf-8", call.ContentType);
            // Laravel responde JSON (y no una redirección) a los errores si se le pide JSON.
            Assert.Equal("application/json", call.Accept);
            Assert.Equal("IronBody-ReceiptPrinter/1.0.0", call.UserAgent);
        });
    }

    [Fact]
    public async Task Ticket_customer_data_and_amounts_never_reach_logs_or_diagnostics()
    {
        _host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Json(HttpStatusCode.OK, FakeBackendHandler.PrintRedeem(JobWithCustomer())));
        var ticket = AgentTestHost.NewTicket();
        await _host.PostTicketAsync(ticket);
        var failing = AgentTestHost.NewTicket();
        _host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Error(HttpStatusCode.Gone, "ticket_expired"));
        await _host.PostTicketAsync(failing);

        var diagnostics = (await _host.GetJsonAsync("/v1/diagnostics")).ToJsonString();
        var files = string.Join('\n', Directory.GetFiles(_host.Options.LogDirectory).Select(File.ReadAllText));

        foreach (var text in new[] { _host.Logs.AllText, diagnostics, files })
        {
            Assert.DoesNotContain(ticket, text);
            Assert.DoesNotContain(failing, text);
            Assert.DoesNotContain(CustomerName, text);
            Assert.DoesNotContain("120.000", text);
            Assert.DoesNotContain("mensaje del backend", text);
        }

        Assert.Contains("RG-000123", diagnostics);
        Assert.Contains("ticket_expired", diagnostics);
    }

    [Fact]
    public async Task Status_shows_the_last_job()
    {
        await _host.PostTicketAsync(AgentTestHost.NewTicket());

        var status = await _host.GetJsonAsync("/v1/status");

        var lastJob = status["last_job"]!.AsObject();
        Assert.Equal("print", (string?)lastJob["purpose"]);
        Assert.Equal("RG-000123", (string?)lastJob["receipt_number"]);
        Assert.Equal("accepted", (string?)lastJob["state"]);
        Assert.Null(lastJob["error_code"]);
        Assert.Equal(0, (int)status["queue"]!["pending"]!);
    }

    [Theory]
    [InlineData("paper_out")]
    [InlineData("printer_offline")]
    [InlineData("printer_error")]
    [InlineData("job_timeout")]
    public async Task Printer_error_is_reported_with_its_code(string code)
    {
        _host.Printer.Outcome = job => SpoolResult.Fail(code, null, 55, job.Data.Length);

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.False((bool)result["ok"]!);
        Assert.Equal("error", (string?)result["state"]);
        Assert.Equal(code, (string?)result["error_code"]);
        Assert.Equal(CashierMessages.For(code, "POS-58"), (string?)result["message"]);
        Assert.Equal(55, (int)result["spool_job_id"]!);
        Assert.True((bool)result["reported"]!);

        var report = Assert.Single(_host.Backend.ReportCalls).Body;
        Assert.Equal("error", (string?)report["outcome"]);
        Assert.Equal(code, (string?)report["error_code"]);
        Assert.Equal(CashierMessages.For(code, "POS-58"), (string?)report["message"]);
    }

    [Fact]
    public async Task Printer_offline_message_is_the_one_for_the_cashier()
    {
        _host.Printer.Outcome = _ => SpoolResult.Fail(ErrorCodes.PrinterOffline);

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("La impresora no responde: revisa que esté encendida, con papel y conectada por USB.", (string?)result["message"]);
    }

    [Fact]
    public async Task Report_that_keeps_failing_is_tried_twice_and_leaves_reported_false()
    {
        _host.Backend.Report = _ => Task.FromResult(new HttpResponseMessage(HttpStatusCode.InternalServerError));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("accepted", (string?)result["state"]);
        Assert.False((bool)result["reported"]!);
        Assert.Equal(2, _host.Backend.ReportCalls.Count);
    }

    [Fact]
    public async Task Report_without_network_is_tried_twice()
    {
        _host.Backend.Report = _ => throw new HttpRequestException("sin red");

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.False((bool)result["reported"]!);
        Assert.Equal(2, _host.Backend.ReportCalls.Count);
    }

    [Fact]
    public async Task Report_recovers_on_a_later_attempt()
    {
        var attempts = 0;
        _host.Backend.Report = _ => Task.FromResult(Interlocked.Increment(ref attempts) < 2
            ? new HttpResponseMessage(HttpStatusCode.BadGateway)
            : FakeBackendHandler.Json(HttpStatusCode.OK, new JsonObject { ["ok"] = true, ["recorded"] = true }));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.True((bool)result["reported"]!);
        Assert.Equal(2, _host.Backend.ReportCalls.Count);
    }

    [Fact]
    public async Task Already_reported_counts_as_reported()
    {
        _host.Backend.Report = _ => Task.FromResult(FakeBackendHandler.Error(HttpStatusCode.Conflict, "already_reported"));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.True((bool)result["reported"]!);
        Assert.Single(_host.Backend.ReportCalls);
    }

    [Theory]
    [InlineData(HttpStatusCode.UnprocessableEntity, "validation")]
    [InlineData(HttpStatusCode.Gone, "report_window_closed")]
    [InlineData(HttpStatusCode.Forbidden, "station_mismatch")]
    public async Task Report_rejected_by_a_client_error_is_not_retried(HttpStatusCode status, string code)
    {
        _host.Backend.Report = _ => Task.FromResult(FakeBackendHandler.Error(status, code));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.False((bool)result["reported"]!);
        Assert.Single(_host.Backend.ReportCalls);
    }

    public static TheoryData<int, string?, string> RedeemErrors() => new()
    {
        { 404, "ticket_invalid", "ticket_invalid" },
        { 410, "ticket_expired", "ticket_expired" },
        { 409, "ticket_used", "ticket_used" },
        { 409, "attempt_closed", "attempt_closed" },
        { 409, "otra_cosa", "api_error" },
        { 403, "station_mismatch", "station_mismatch" },
        { 403, "station_inactive", "station_inactive" },
        { 403, null, "api_error" },
        { 426, "protocol_unsupported", "protocol_unsupported" },
        { 503, "agent_disabled", "agent_disabled" },
        { 503, null, "api_error" },
        { 502, null, "api_error" },
        { 422, "validation", "api_error" },
        { 302, null, "api_error" },
        // Códigos que añadió el backend implementado (mapeo acordado con el lead).
        { 429, "rate_limited", "rate_limited" },
        { 429, null, "rate_limited" },
        { 422, "invalid_request", "api_error" },
        { 422, "invalid_outcome", "api_error" },
        { 422, "job_too_long", "invalid_job" },
        { 500, "layout_error", "api_error" },
        { 500, null, "api_error" },
        { 400, null, "api_error" },
        { 401, "unauthenticated", "api_error" },
        { 418, "codigo_que_no_existe", "api_error" },
        { 451, "<script>alert(1)</script>", "api_error" },
    };

    [Theory]
    [MemberData(nameof(RedeemErrors))]
    public async Task Redeem_errors_map_to_contract_codes_without_printing_or_reporting(int status, string? code, string expected)
    {
        _host.Backend.Redeem = _ =>
        {
            var response = code is null
                ? new HttpResponseMessage((HttpStatusCode)status)
                : FakeBackendHandler.Error((HttpStatusCode)status, code);
            if (status == 302)
            {
                response.Headers.Location = new Uri("https://evil.example/api/receipt-agent/redeem");
            }

            return Task.FromResult(response);
        };

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.False((bool)result["ok"]!);
        Assert.Null(result["purpose"]);
        Assert.Equal("error", (string?)result["state"]);
        Assert.Equal(expected, (string?)result["error_code"]);
        Assert.Equal(CashierMessages.For(expected), (string?)result["message"]);
        Assert.False((bool)result["reported"]!);
        Assert.Empty(_host.Printer.Jobs);
        Assert.Empty(_host.Backend.ReportCalls);
        // La redirección no se sigue: solo hubo una petición, y fue al host configurado.
        Assert.All(_host.Backend.RequestUris, uri => Assert.Equal("api.ironbodyneiva.cloud", uri.Host));
    }

    [Theory]
    [InlineData(null)]
    [InlineData("not_found")]
    [InlineData("route_missing")]
    public async Task Not_found_without_ticket_invalid_code_means_the_backend_lacks_the_service(string? code)
    {
        _host.Backend.Redeem = _ => Task.FromResult(code is null
            ? new HttpResponseMessage(HttpStatusCode.NotFound) { Content = new StringContent("<html>404</html>") }
            : FakeBackendHandler.Error(HttpStatusCode.NotFound, code));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("api_error", (string?)result["error_code"]);
        Assert.Equal("El servidor no tiene el servicio de impresión (¿backend sin actualizar?).", (string?)result["message"]);
        Assert.False((bool)result["reported"]!);
        Assert.Empty(_host.Printer.PrintCalls);
    }

    [Fact]
    public async Task Rate_limited_by_the_server_shows_its_own_message()
    {
        _host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Error(HttpStatusCode.TooManyRequests, "rate_limited"));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("rate_limited", (string?)result["error_code"]);
        Assert.Equal("Demasiadas solicitudes al servidor; espera un momento y reintenta.", (string?)result["message"]);
        Assert.Empty(_host.Printer.PrintCalls);
    }

    [Theory]
    [InlineData("invalid_request")]
    [InlineData("invalid_outcome")]
    public async Task Integration_failure_on_redeem_is_logged_as_error_with_the_backend_code(string code)
    {
        _host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Error(HttpStatusCode.UnprocessableEntity, code));
        var ticket = AgentTestHost.NewTicket();

        var result = await _host.PostTicketAsync(ticket);

        Assert.Equal("api_error", (string?)result["error_code"]);
        var events = (await _host.GetJsonAsync("/v1/diagnostics"))["events"]!.AsArray();
        var rejected = events.First(e => (string?)e!["event"] == "ticket_rejected")!;
        Assert.Equal("error", (string?)rejected["level"]);
        Assert.Contains($"HTTP 422, code={code}", (string?)rejected["message"]);
        Assert.DoesNotContain(ticket, _host.Logs.AllText);
    }

    [Fact]
    public async Task Job_too_long_from_the_server_is_invalid_job_without_printing()
    {
        _host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Error(HttpStatusCode.UnprocessableEntity, "job_too_long"));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("invalid_job", (string?)result["error_code"]);
        Assert.False((bool)result["reported"]!);
        Assert.Empty(_host.Printer.PrintCalls);
        Assert.Empty(_host.Backend.ReportCalls);
    }

    [Fact]
    public async Task Unknown_backend_code_never_reaches_the_logs_verbatim()
    {
        _host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Error((HttpStatusCode)418, "<script>alert(1)</script>"));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("api_error", (string?)result["error_code"]);
        Assert.DoesNotContain("<script>", _host.Logs.AllText);
        Assert.DoesNotContain("<script>", (await _host.GetJsonAsync("/v1/diagnostics")).ToJsonString());
    }

    [Fact]
    public async Task Report_rejected_as_invalid_outcome_is_an_error_event_and_not_retried()
    {
        _host.Backend.Report = _ => Task.FromResult(FakeBackendHandler.Error(HttpStatusCode.UnprocessableEntity, "invalid_outcome"));
        var ticket = AgentTestHost.NewTicket();

        var result = await _host.PostTicketAsync(ticket);

        Assert.Equal("accepted", (string?)result["state"]);
        Assert.False((bool)result["reported"]!);
        Assert.Single(_host.Backend.ReportCalls);
        var events = (await _host.GetJsonAsync("/v1/diagnostics"))["events"]!.AsArray();
        var rejected = events.First(e => (string?)e!["event"] == "report_rejected")!;
        Assert.Equal("error", (string?)rejected["level"]);
        Assert.Contains("HTTP 422, code=invalid_outcome", (string?)rejected["message"]);
        Assert.DoesNotContain(ticket, _host.Logs.AllText);
    }

    [Fact]
    public async Task Report_rate_limited_is_retried_within_the_budget()
    {
        _host.Backend.Report = _ => Task.FromResult(FakeBackendHandler.Error(HttpStatusCode.TooManyRequests, "rate_limited"));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.False((bool)result["reported"]!);
        Assert.Equal(2, _host.Backend.ReportCalls.Count);
        var events = (await _host.GetJsonAsync("/v1/diagnostics"))["events"]!.AsArray();
        Assert.Contains(events, e => (string?)e!["event"] == "report_failed" && ((string?)e["message"])!.Contains("2 intento(s)"));
    }

    [Fact]
    public async Task Redeem_without_network_is_api_unreachable()
    {
        _host.Backend.Redeem = _ => throw new HttpRequestException("No such host is known.");

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("api_unreachable", (string?)result["error_code"]);
        Assert.False((bool)result["reported"]!);
        Assert.Empty(_host.Printer.Jobs);
    }

    [Fact]
    public async Task Redeem_timeout_is_api_unreachable()
    {
        _host.Backend.Redeem = _ => throw new TaskCanceledException("timeout", new TimeoutException());

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("api_unreachable", (string?)result["error_code"]);
    }

    public static TheoryData<string> UnexpectedBodies() => new()
    {
        "no es json",
        """{"ok":false}""",
        """{"ok":true,"purpose":"print"}""",
        """{"ok":true,"purpose":"borrar_todo","job":{}}""",
        """{"ok":true,"purpose":"print","job":{"kind":"original","columns":"32","feed_lines":4,"cut":false,"lines":[]}}""",
        """{"ok":true,"purpose":"print","job":{"kind":"original","columns":32,"feed_lines":4,"cut":false,"lines":"IRON BODY"}}""",
        """{"ok":true,"purpose":"print","job":{"kind":"original","columns":32,"feed_lines":4,"cut":false,"lines":[{"text":1,"style":"normal"}]}}""",
        """{"ok":true,"purpose":"print","job":{"kind":"original","columns":32,"feed_lines":4,"lines":[{"text":"x","style":"normal"}]}}""",
        """{"ok":true,"purpose":"configure","settings":{"columns":32}}""",
    };

    [Theory]
    [MemberData(nameof(UnexpectedBodies))]
    public async Task Unexpected_redeem_body_is_incompatible_job(string body)
    {
        _host.Backend.Redeem = _ => Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new StringContent(body, System.Text.Encoding.UTF8, "application/json"),
        });

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("incompatible_job", (string?)result["error_code"]);
        Assert.False((bool)result["reported"]!);
        Assert.Empty(_host.Printer.Jobs);
        Assert.Empty(_host.Backend.ReportCalls);
    }

    [Fact]
    public async Task Invalid_document_is_rejected_whole_and_reported_as_invalid_job()
    {
        var job = FakeBackendHandler.SampleJob(lines: new[] { ("OK", "normal"), (new string('x', 33), "normal") });
        _host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Json(HttpStatusCode.OK, FakeBackendHandler.PrintRedeem(job)));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("invalid_job", (string?)result["error_code"]);
        Assert.Contains("línea 2: 33 caracteres, el máximo es 32", (string?)result["message"]);
        Assert.True((bool)result["reported"]!);
        Assert.Empty(_host.Printer.Jobs);
        var report = Assert.Single(_host.Backend.ReportCalls).Body;
        Assert.Equal("error", (string?)report["outcome"]);
        Assert.Equal("invalid_job", (string?)report["error_code"]);
    }

    [Fact]
    public async Task Control_character_from_the_backend_is_invalid_job_and_nothing_is_printed()
    {
        var job = FakeBackendHandler.SampleJob(lines: new[] { ("RG-1\u001Bd\u0009", "normal") });
        _host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Json(HttpStatusCode.OK, FakeBackendHandler.PrintRedeem(job)));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("invalid_job", (string?)result["error_code"]);
        Assert.Empty(_host.Printer.Jobs);
    }

    [Fact]
    public async Task Unicode_noncharacter_from_the_backend_is_invalid_job_not_a_crash()
    {
        var job = FakeBackendHandler.SampleJob(lines: new[] { ("Total" + (char)0xFFFE, "normal") });
        _host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Json(HttpStatusCode.OK, FakeBackendHandler.PrintRedeem(job)));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("invalid_job", (string?)result["error_code"]);
        Assert.True((bool)result["reported"]!);
        Assert.Empty(_host.Printer.Jobs);
    }

    [Fact]
    public async Task Job_columns_different_from_the_station_is_invalid_job()
    {
        var job = FakeBackendHandler.SampleJob(columns: 42);
        _host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Json(HttpStatusCode.OK, FakeBackendHandler.PrintRedeem(job)));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("invalid_job", (string?)result["error_code"]);
        Assert.Empty(_host.Printer.Jobs);
    }

    [Fact]
    public async Task Unexpected_spooler_exception_answers_200_with_spooler_error_and_reports_it()
    {
        _host.Printer.Outcome = _ => throw new InvalidOperationException("defecto simulado");

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("error", (string?)result["state"]);
        Assert.Equal("spooler_error", (string?)result["error_code"]);
        Assert.Equal("RG-000123", (string?)result["receipt_number"]);
        Assert.True((bool)result["reported"]!);
        Assert.DoesNotContain("defecto simulado", (string?)result["message"]);
    }

    [Fact]
    public async Task Station_without_printer_is_printer_not_configured()
    {
        _host.ConfigureStation(printerName: null);

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("printer_not_configured", (string?)result["error_code"]);
        Assert.True((bool)result["reported"]!);
        Assert.Equal("printer_not_configured", (string?)Assert.Single(_host.Backend.ReportCalls).Body["error_code"]);
    }

    [Fact]
    public async Task Printer_missing_in_windows_is_printer_not_found()
    {
        _host.ConfigureStation(printerName: "POS-80 que no existe");

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("printer_not_found", (string?)result["error_code"]);
        Assert.Contains("«POS-80 que no existe»", (string?)result["message"]);
        Assert.Empty(_host.Printer.Jobs);
    }

    [Fact]
    public async Task Test_page_prints_with_its_own_document_name()
    {
        var job = FakeBackendHandler.SampleJob(receiptNumber: null, kind: "test", lines: new[] { ("IRON BODY", "title"), ("PRUEBA POS", "normal"), ("OK", "bold") });
        _host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Json(HttpStatusCode.OK, FakeBackendHandler.PrintRedeem(job, "test")));

        var result = await _host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("test", (string?)result["purpose"]);
        Assert.Equal("accepted", (string?)result["state"]);
        Assert.Equal(CashierMessages.TestAccepted, (string?)result["message"]);
        Assert.Equal("Iron Body prueba", Assert.Single(_host.Printer.Jobs).DocumentName);
        Assert.Equal("accepted", (string?)Assert.Single(_host.Backend.ReportCalls).Body["outcome"]);
    }

    [Fact]
    public async Task Same_ticket_twice_at_once_prints_once_and_both_get_the_same_result()
    {
        var release = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        _host.Backend.Redeem = async _ =>
        {
            await release.Task;
            return FakeBackendHandler.Json(HttpStatusCode.OK, FakeBackendHandler.PrintRedeem(FakeBackendHandler.SampleJob()));
        };
        var ticket = AgentTestHost.NewTicket();

        var first = _host.PostTicketAsync(ticket);
        var second = _host.PostTicketAsync(ticket);
        await WaitUntil(() => _host.Backend.RedeemCalls.Count == 1);
        await Task.Delay(100);
        release.SetResult();
        var results = await Task.WhenAll(first, second);

        Assert.Equal(results[0].ToJsonString(), results[1].ToJsonString());
        Assert.Equal("accepted", (string?)results[0]["state"]);
        Assert.Single(_host.Backend.RedeemCalls);
        Assert.Single(_host.Printer.Jobs);
        Assert.Single(_host.Backend.ReportCalls);
    }

    [Fact]
    public async Task Twenty_waiting_jobs_fill_the_queue_and_the_next_one_is_queue_full()
    {
        var gate = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        _host.Printer.Gate = gate;
        var queue = _host.App.Services.GetRequiredService<PrintQueue>();
        try
        {
            var printing = _host.PostTicketAsync(AgentTestHost.NewTicket());
            await _host.Printer.FirstPrintStarted.Task.WaitAsync(TimeSpan.FromSeconds(10));
            var waiting = Enumerable.Range(0, PrintQueue.MaxWaiting).Select(_ => _host.PostTicketAsync(AgentTestHost.NewTicket())).ToArray();
            await WaitUntil(() => queue.Pending == 1 + PrintQueue.MaxWaiting);

            // Con límite, la respuesta es inmediata; sin él se quedaría esperando a la impresora.
            var rejected = await _host.PostTicketAsync(AgentTestHost.NewTicket()).WaitAsync(TimeSpan.FromSeconds(10));

            Assert.Equal("queue_full", (string?)rejected["error_code"]);
            Assert.Equal(CashierMessages.For(ErrorCodes.QueueFull), (string?)rejected["message"]);
            Assert.True((bool)rejected["reported"]!);
            Assert.Contains(_host.Backend.ReportCalls, c => (string?)c.Body["error_code"] == "queue_full");

            gate.SetResult();
            var all = await Task.WhenAll(waiting.Prepend(printing));
            Assert.All(all, r => Assert.Equal("accepted", (string?)r["state"]));
            Assert.Equal(1 + PrintQueue.MaxWaiting, _host.Printer.Jobs.Count);
            Assert.Equal(0, queue.Pending);
        }
        finally
        {
            // Si la prueba falla, se suelta la impresora para que el servicio pueda detenerse.
            gate.TrySetResult();
        }
    }

    internal static async Task WaitUntil(Func<bool> condition)
    {
        var deadline = DateTime.UtcNow.AddSeconds(10);
        while (!condition())
        {
            Assert.True(DateTime.UtcNow < deadline, "la condición no se cumplió en 10 s");
            await Task.Delay(10);
        }
    }
}
