using System.Net;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json.Nodes;
using IronBody.ReceiptAgent.Tests.Support;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.RateLimiting;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Options;

namespace IronBody.ReceiptAgent.Tests;

/// <summary>Guardas del contrato §3.1 sobre la tubería real (TestServer).</summary>
public sealed class GuardTests : IAsyncLifetime
{
    private AgentTestHost _host = null!;

    public async Task InitializeAsync() => _host = await AgentTestHost.StartAsync();

    public async Task DisposeAsync() => await _host.DisposeAsync();

    private HttpClient Client => _host.Client;

    private static async Task<string?> Code(HttpResponseMessage response) =>
        (string?)JsonNode.Parse(await response.Content.ReadAsStringAsync())?["code"];

    private static void AssertNoCorsHeaders(HttpResponseMessage response)
    {
        Assert.DoesNotContain(response.Headers, h => h.Key.StartsWith("Access-Control-", StringComparison.OrdinalIgnoreCase));
    }

    private static void AssertSecurityHeaders(HttpResponseMessage response)
    {
        Assert.Equal("no-store", response.Headers.CacheControl?.ToString());
        Assert.Equal("nosniff", Assert.Single(response.Headers.GetValues("X-Content-Type-Options")));
        Assert.False(response.Headers.Contains("Server"));
    }

    [Fact]
    public async Task Root_answers_plain_text_without_origin_and_without_station_data()
    {
        using var response = await Client.GetAsync("/");

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal("text/plain", response.Content.Headers.ContentType?.MediaType);
        Assert.Equal("Iron Body · Servicio de impresión de recibos 1.0.0 · en funcionamiento", await response.Content.ReadAsStringAsync());
        AssertSecurityHeaders(response);
    }

    /// <summary>
    /// Revisión de seguridad MENOR-2: si una configuración abriera otra dirección de escucha, un
    /// cliente de la red falsifica Host y Origin sin esfuerzo (lo que hizo el revisor: 200 con la
    /// station_key desde la IP de LAN). La primera guarda mira la dirección de la conexión.
    /// </summary>
    [Theory]
    [InlineData("192.168.20.22")]
    [InlineData("10.0.0.5")]
    [InlineData("172.16.0.9")]
    [InlineData("::ffff:192.168.20.22")]
    [InlineData("fe80::1")]
    [InlineData("2001:db8::7")]
    public async Task Connections_from_another_machine_are_rejected_before_any_other_guard(string remote)
    {
        await using var host = await AgentTestHost.StartAsync(remoteAddress: remote);

        // Lo que mandaría un cliente de la LAN que conoce el contrato: Host y Origin correctos.
        using var status = await host.Client.SendAsync(AgentTestHost.CrmGet("/v1/status"));
        var statusBody = await status.Content.ReadAsStringAsync();
        Assert.Equal(HttpStatusCode.Forbidden, status.StatusCode);
        Assert.Equal("""{"ok":false,"code":"not_loopback"}""", statusBody);
        Assert.DoesNotContain(host.CurrentSettings.StationKey, statusBody);
        AssertNoCorsHeaders(status);
        AssertSecurityHeaders(status);

        using var ticket = await host.Client.SendAsync(AgentTestHost.CrmPost("/v1/tickets", $$"""{"ticket":"{{AgentTestHost.NewTicket()}}"}"""));
        Assert.Equal(HttpStatusCode.Forbidden, ticket.StatusCode);
        Assert.Equal("not_loopback", await Code(ticket));
        AssertNoCorsHeaders(ticket);

        using var preflight = new HttpRequestMessage(HttpMethod.Options, "/v1/tickets");
        preflight.Headers.Add("Origin", AgentTestHost.AllowedOrigin);
        preflight.Headers.Add("Access-Control-Request-Method", "POST");
        using var preflightResponse = await host.Client.SendAsync(preflight);
        Assert.Equal(HttpStatusCode.Forbidden, preflightResponse.StatusCode);
        AssertNoCorsHeaders(preflightResponse);

        using var root = await host.Client.GetAsync("/");
        Assert.Equal(HttpStatusCode.Forbidden, root.StatusCode);
        Assert.Equal("not_loopback", await Code(root));

        Assert.Empty(host.Backend.Calls);
        Assert.Empty(host.Printer.PrintCalls);
    }

    [Fact]
    public async Task Connection_without_a_known_remote_address_is_not_trusted()
    {
        // TestServer sin dirección remota: el servicio no puede saber de dónde viene y no la acepta.
        await using var host = await AgentTestHost.StartAsync(remoteAddress: null);

        using var response = await host.Client.SendAsync(AgentTestHost.CrmGet("/v1/status"));

        Assert.Equal(HttpStatusCode.Forbidden, response.StatusCode);
        Assert.Equal("not_loopback", await Code(response));
    }

    [Theory]
    [InlineData("127.0.0.1")]
    [InlineData("127.0.0.53")]
    [InlineData("::1")]
    public async Task Loopback_connections_pass_the_first_guard(string remote)
    {
        await using var host = await AgentTestHost.StartAsync(remoteAddress: remote);

        using var response = await host.Client.SendAsync(AgentTestHost.CrmGet("/v1/status"));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
    }

    [Fact]
    public async Task Rejected_remote_connections_do_not_spend_the_rate_limit()
    {
        await using var host = await AgentTestHost.StartAsync(remoteAddress: "192.168.20.22");
        for (var i = 0; i < AgentHost.PostsPerMinute + 5; i++)
        {
            using var rejected = await host.Client.SendAsync(AgentTestHost.CrmPost("/v1/tickets", """{"ticket":"x"}"""));
            Assert.Equal("not_loopback", await Code(rejected));
        }

        // La guarda va antes del limitador: ninguna de esas peticiones gastó el cupo de POST.
        var limiter = host.App.Services.GetRequiredService<IOptions<RateLimiterOptions>>().Value.GlobalLimiter!;
        var statistics = limiter.GetStatistics(new DefaultHttpContext { Request = { Method = "POST" } });
        Assert.Equal(AgentHost.PostsPerMinute, statistics!.CurrentAvailablePermits);
    }

    [Theory]
    [InlineData("127.0.0.1:17888", HttpStatusCode.OK)]
    [InlineData("localhost:17888", HttpStatusCode.OK)]
    [InlineData("evil.example:17888", HttpStatusCode.Forbidden)]
    [InlineData("127.0.0.1", HttpStatusCode.Forbidden)]
    [InlineData("127.0.0.1:17889", HttpStatusCode.Forbidden)]
    [InlineData("127.0.0.1.nip.io:17888", HttpStatusCode.Forbidden)]
    [InlineData("[::1]:17888", HttpStatusCode.Forbidden)]
    public async Task Host_must_be_exactly_loopback_with_the_port(string host, HttpStatusCode expected)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, "/");
        request.Headers.Host = host;

        using var response = await Client.SendAsync(request);

        Assert.Equal(expected, response.StatusCode);
        if (expected == HttpStatusCode.Forbidden)
        {
            Assert.Equal("bad_host", await Code(response));
            AssertSecurityHeaders(response);
        }
    }

    [Fact]
    public async Task Dns_rebinding_with_allowed_origin_is_still_rejected_by_host()
    {
        using var request = AgentTestHost.CrmGet("/v1/status");
        request.Headers.Host = "attacker.example:17888";

        using var response = await Client.SendAsync(request);

        Assert.Equal(HttpStatusCode.Forbidden, response.StatusCode);
        Assert.Equal("bad_host", await Code(response));
        AssertNoCorsHeaders(response);
    }

    [Theory]
    [InlineData(null)]
    [InlineData("https://evil.example")]
    [InlineData("https://ironbodyneiva.cloud/")]
    [InlineData("http://ironbodyneiva.cloud")]
    [InlineData("https://ironbodyneiva.cloud.evil.example")]
    [InlineData("null")]
    public async Task V1_requires_an_exact_allowed_origin_and_rejects_without_cors_headers(string? origin)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, "/v1/status");
        if (origin is not null)
        {
            request.Headers.Add("Origin", origin);
        }

        using var response = await Client.SendAsync(request);

        Assert.Equal(HttpStatusCode.Forbidden, response.StatusCode);
        Assert.Equal("origin_not_allowed", await Code(response));
        AssertNoCorsHeaders(response);
        AssertSecurityHeaders(response);
    }

    [Theory]
    [InlineData("/V1/status")]
    [InlineData("/v1/STATUS")]
    [InlineData("/v1/tickets")]
    [InlineData("/v1/no-existe")]
    public async Task Origin_check_covers_every_v1_path_regardless_of_case(string path)
    {
        using var response = await Client.GetAsync(path);

        Assert.Equal(HttpStatusCode.Forbidden, response.StatusCode);
        Assert.Equal("origin_not_allowed", await Code(response));
    }

    [Fact]
    public async Task Ticket_post_from_foreign_origin_is_not_processed()
    {
        using var request = AgentTestHost.CrmPost("/v1/tickets", $$"""{"ticket":"{{AgentTestHost.NewTicket()}}"}""");
        request.Headers.Remove("Origin");
        request.Headers.Add("Origin", "https://evil.example");

        using var response = await Client.SendAsync(request);

        Assert.Equal(HttpStatusCode.Forbidden, response.StatusCode);
        AssertNoCorsHeaders(response);
        Assert.Empty(_host.Backend.Calls);
    }

    [Fact]
    public async Task Allowed_origin_gets_its_own_origin_back_and_vary_but_never_credentials()
    {
        using var response = await Client.SendAsync(AgentTestHost.CrmGet("/v1/status"));

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal(AgentTestHost.AllowedOrigin, Assert.Single(response.Headers.GetValues("Access-Control-Allow-Origin")));
        Assert.Contains("Origin", response.Headers.Vary);
        Assert.False(response.Headers.Contains("Access-Control-Allow-Credentials"));
        AssertSecurityHeaders(response);
    }

    [Fact]
    public async Task Www_origin_is_a_separate_allowed_origin_and_gets_itself_back()
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, "/v1/status");
        request.Headers.Add("Origin", AgentTestHost.WwwOrigin);

        using var response = await Client.SendAsync(request);

        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal(AgentTestHost.WwwOrigin, Assert.Single(response.Headers.GetValues("Access-Control-Allow-Origin")));
    }

    [Theory]
    [InlineData("https://www.ironbodyneiva.cloud/")]
    [InlineData("https://WWW.ironbodyneiva.cloud")]
    [InlineData("http://www.ironbodyneiva.cloud")]
    [InlineData("https://cdn.ironbodyneiva.cloud")]
    [InlineData("https://www.ironbodyneiva.cloud:8443")]
    public async Task Only_the_exact_www_origin_matches_no_wildcards(string origin)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, "/v1/status");
        request.Headers.Add("Origin", origin);

        using var response = await Client.SendAsync(request);

        Assert.Equal(HttpStatusCode.Forbidden, response.StatusCode);
        AssertNoCorsHeaders(response);
    }

    [Fact]
    public async Task Preflight_with_private_network_request_gets_private_network_allowed()
    {
        using var request = new HttpRequestMessage(HttpMethod.Options, "/v1/tickets");
        request.Headers.Add("Origin", AgentTestHost.AllowedOrigin);
        request.Headers.Add("Access-Control-Request-Method", "POST");
        request.Headers.Add("Access-Control-Request-Headers", "content-type,x-ironbody-client");
        request.Headers.Add("Access-Control-Request-Private-Network", "true");

        using var response = await Client.SendAsync(request);

        Assert.Equal(HttpStatusCode.NoContent, response.StatusCode);
        Assert.Equal(AgentTestHost.AllowedOrigin, Single(response, "Access-Control-Allow-Origin"));
        Assert.Equal("GET, POST", Single(response, "Access-Control-Allow-Methods"));
        Assert.Equal("Content-Type, X-IronBody-Client", Single(response, "Access-Control-Allow-Headers"));
        Assert.Equal("600", Single(response, "Access-Control-Max-Age"));
        Assert.Equal("true", Single(response, "Access-Control-Allow-Private-Network"));
        Assert.Contains("Origin", response.Headers.Vary);
        Assert.False(response.Headers.Contains("Access-Control-Allow-Credentials"));
        AssertSecurityHeaders(response);
        Assert.Empty(_host.Backend.Calls);
    }

    [Fact]
    public async Task Preflight_without_private_network_request_does_not_add_it()
    {
        using var request = new HttpRequestMessage(HttpMethod.Options, "/v1/tickets");
        request.Headers.Add("Origin", AgentTestHost.AllowedOrigin);
        request.Headers.Add("Access-Control-Request-Method", "POST");

        using var response = await Client.SendAsync(request);

        Assert.Equal(HttpStatusCode.NoContent, response.StatusCode);
        Assert.False(response.Headers.Contains("Access-Control-Allow-Private-Network"));
    }

    [Fact]
    public async Task Preflight_from_foreign_origin_is_rejected_without_cors_headers()
    {
        using var request = new HttpRequestMessage(HttpMethod.Options, "/v1/tickets");
        request.Headers.Add("Origin", "https://evil.example");
        request.Headers.Add("Access-Control-Request-Method", "POST");
        request.Headers.Add("Access-Control-Request-Private-Network", "true");

        using var response = await Client.SendAsync(request);

        Assert.Equal(HttpStatusCode.Forbidden, response.StatusCode);
        AssertNoCorsHeaders(response);
    }

    [Theory]
    [InlineData("text/plain")]
    [InlineData("application/x-www-form-urlencoded")]
    [InlineData("multipart/form-data")]
    [InlineData("application/json; charset=iso-8859-1")]
    public async Task Post_must_be_json(string contentType)
    {
        using var request = AgentTestHost.CrmPost("/v1/tickets", $$"""{"ticket":"{{AgentTestHost.NewTicket()}}"}""");
        request.Content!.Headers.ContentType = MediaTypeHeaderValue.Parse(contentType);

        using var response = await Client.SendAsync(request);

        Assert.Equal(HttpStatusCode.UnsupportedMediaType, response.StatusCode);
        Assert.Equal("unsupported_media_type", await Code(response));
        // Origen permitido: el CRM puede leer el motivo.
        Assert.Equal(AgentTestHost.AllowedOrigin, Single(response, "Access-Control-Allow-Origin"));
        Assert.Empty(_host.Backend.Calls);
    }

    [Theory]
    [InlineData(null)]
    [InlineData("CRM")]
    [InlineData("crm2")]
    [InlineData("")]
    public async Task Post_requires_the_client_header(string? value)
    {
        using var request = AgentTestHost.CrmPost("/v1/tickets", $$"""{"ticket":"{{AgentTestHost.NewTicket()}}"}""");
        request.Headers.Remove("X-IronBody-Client");
        if (value is not null)
        {
            request.Headers.TryAddWithoutValidation("X-IronBody-Client", value);
        }

        using var response = await Client.SendAsync(request);

        Assert.Equal(HttpStatusCode.BadRequest, response.StatusCode);
        Assert.Equal("missing_client_header", await Code(response));
        Assert.Empty(_host.Backend.Calls);
    }

    [Fact]
    public async Task Body_over_16_KB_with_content_length_is_rejected()
    {
        var body = $$"""{"ticket":"{{new string('a', 17 * 1024)}}"}""";
        using var response = await Client.SendAsync(AgentTestHost.CrmPost("/v1/tickets", body));

        Assert.Equal(HttpStatusCode.RequestEntityTooLarge, response.StatusCode);
        Assert.Equal("payload_too_large", await Code(response));
        Assert.Empty(_host.Backend.Calls);
    }

    [Fact]
    public async Task Chunked_body_over_16_KB_is_rejected_while_reading()
    {
        var bytes = Encoding.UTF8.GetBytes($$"""{"ticket":"{{new string('a', 17 * 1024)}}"}""");
        using var request = AgentTestHost.CrmPost("/v1/tickets", "{}");
        request.Content = new StreamContent(new NonSeekableStream(bytes));
        request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");

        using var response = await Client.SendAsync(request);

        Assert.Equal(HttpStatusCode.RequestEntityTooLarge, response.StatusCode);
        Assert.Equal("payload_too_large", await Code(response));
    }

    [Theory]
    [InlineData("")]
    [InlineData("no es json")]
    [InlineData("[]")]
    [InlineData("""{"ticket":123}""")]
    [InlineData("""{"ticket":"corto"}""")]
    [InlineData("""{"ticket":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="}""")]
    [InlineData("""{"ticket":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\n"}""")]
    [InlineData("""{"ticket":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA","text":"imprime esto"}""")]
    [InlineData("""{"ticket":"AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA","ticket":"BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB"}""")]
    public async Task Malformed_body_is_invalid_body_and_never_reaches_the_backend(string body)
    {
        using var response = await Client.SendAsync(AgentTestHost.CrmPost("/v1/tickets", body));

        Assert.Equal(HttpStatusCode.BadRequest, response.StatusCode);
        Assert.Equal("invalid_body", await Code(response));
        Assert.Empty(_host.Backend.Calls);
    }

    [Fact]
    public async Task Thirty_posts_per_minute_then_rate_limited()
    {
        for (var i = 0; i < 30; i++)
        {
            using var ok = await Client.SendAsync(AgentTestHost.CrmPost("/v1/tickets", "{}"));
            Assert.Equal(HttpStatusCode.BadRequest, ok.StatusCode); // pasa las guardas; el cuerpo es inválido
        }

        using var limited = await Client.SendAsync(AgentTestHost.CrmPost("/v1/tickets", "{}"));

        Assert.Equal(HttpStatusCode.TooManyRequests, limited.StatusCode);
        Assert.Equal("rate_limited", await Code(limited));
        Assert.Equal(AgentTestHost.AllowedOrigin, Single(limited, "Access-Control-Allow-Origin"));
        AssertSecurityHeaders(limited);

        // Los GET tienen su propio cupo.
        using var get = await Client.SendAsync(AgentTestHost.CrmGet("/v1/status"));
        Assert.Equal(HttpStatusCode.OK, get.StatusCode);
    }

    [Fact]
    public async Task One_hundred_twenty_gets_per_minute_then_rate_limited()
    {
        for (var i = 0; i < 120; i++)
        {
            using var ok = await Client.SendAsync(AgentTestHost.CrmGet("/v1/status"));
            Assert.Equal(HttpStatusCode.OK, ok.StatusCode);
        }

        using var limited = await Client.SendAsync(AgentTestHost.CrmGet("/v1/status"));
        Assert.Equal(HttpStatusCode.TooManyRequests, limited.StatusCode);
        Assert.Equal("rate_limited", await Code(limited));

        using var root = await Client.GetAsync("/");
        Assert.Equal(HttpStatusCode.TooManyRequests, root.StatusCode);
    }

    [Fact]
    public async Task Unknown_routes_still_carry_the_security_headers()
    {
        using var response = await Client.GetAsync("/no-existe");

        Assert.Equal(HttpStatusCode.NotFound, response.StatusCode);
        AssertSecurityHeaders(response);
    }

    private static string Single(HttpResponseMessage response, string header) => Assert.Single(response.Headers.GetValues(header));

    /// <summary>Cuerpo sin longitud conocida: llega «chunked», sin Content-Length.</summary>
    private sealed class NonSeekableStream : Stream
    {
        private readonly MemoryStream _inner;

        public NonSeekableStream(byte[] data) => _inner = new MemoryStream(data);

        public override bool CanRead => true;
        public override bool CanSeek => false;
        public override bool CanWrite => false;
        public override long Length => throw new NotSupportedException();
        public override long Position { get => throw new NotSupportedException(); set => throw new NotSupportedException(); }
        public override void Flush() { }
        public override int Read(byte[] buffer, int offset, int count) => _inner.Read(buffer, offset, count);
        public override long Seek(long offset, SeekOrigin origin) => throw new NotSupportedException();
        public override void SetLength(long value) => throw new NotSupportedException();
        public override void Write(byte[] buffer, int offset, int count) => throw new NotSupportedException();
    }
}
