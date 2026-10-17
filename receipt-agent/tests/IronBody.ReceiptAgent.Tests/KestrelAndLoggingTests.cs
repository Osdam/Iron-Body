using System.Net;
using System.Net.Sockets;
using IronBody.ReceiptAgent.Diagnostics;
using IronBody.ReceiptAgent.Tests.Support;
using Microsoft.AspNetCore.Hosting.Server;
using Microsoft.AspNetCore.Hosting.Server.Features;
using Microsoft.AspNetCore.Server.Kestrel.Core;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;

namespace IronBody.ReceiptAgent.Tests;

/// <summary>Lo que TestServer no puede probar: Kestrel real (guarda 1, sin Server) y el log en archivo.</summary>
public sealed class KestrelAndLoggingTests
{
    [Fact]
    public async Task Kestrel_listens_only_on_ipv4_loopback_and_sends_no_server_header()
    {
        using var data = new TempDirectory();
        var port = FreeLoopbackPort();
        var (app, options, errors) = AgentHost.Build(Array.Empty<string>(), new AgentHostOverrides
        {
            EnvironmentName = "Development",
            Configuration = new Dictionary<string, string?>
            {
                ["ReceiptPrinter:Port"] = port.ToString(),
                ["ReceiptPrinter:DataDirectory"] = data.Path,
                ["ReceiptPrinter:PrinterBackend"] = "file",
                ["ReceiptPrinter:AllowedOrigins:0"] = "https://ironbodyneiva.cloud",
            },
        });
        Assert.Empty(errors);
        AgentHost.Initialize(app, options);
        await app.StartAsync();
        try
        {
            var addresses = app.Services.GetRequiredService<IServer>().Features.Get<IServerAddressesFeature>()!.Addresses;
            Assert.Equal(new[] { $"http://127.0.0.1:{port}" }, addresses.ToArray());

            using var client = new HttpClient();
            using var response = await client.GetAsync($"http://127.0.0.1:{port}/");
            Assert.Equal(HttpStatusCode.OK, response.StatusCode);
            Assert.False(response.Headers.Contains("Server"));
            Assert.Equal("no-store", response.Headers.CacheControl?.ToString());

            // Nadie escucha en IPv6 ::1 con ese puerto.
            using var v6 = new Socket(AddressFamily.InterNetworkV6, SocketType.Stream, ProtocolType.Tcp);
            await Assert.ThrowsAnyAsync<SocketException>(() => v6.ConnectAsync(IPAddress.IPv6Loopback, port));
        }
        finally
        {
            await app.StopAsync();
            await app.DisposeAsync();
        }
    }

    [Fact]
    public async Task Kestrel_closes_connections_beyond_the_concurrent_limit()
    {
        using var data = new TempDirectory();
        var port = FreeLoopbackPort();
        var (app, options, errors) = AgentHost.Build(Array.Empty<string>(), new AgentHostOverrides
        {
            EnvironmentName = "Development",
            Configuration = new Dictionary<string, string?>
            {
                ["ReceiptPrinter:Port"] = port.ToString(),
                ["ReceiptPrinter:DataDirectory"] = data.Path,
                ["ReceiptPrinter:PrinterBackend"] = "file",
                ["ReceiptPrinter:AllowedOrigins:0"] = "https://ironbodyneiva.cloud",
            },
        });
        Assert.Empty(errors);
        Assert.Equal(64, app.Services.GetRequiredService<IOptions<KestrelServerOptions>>().Value.Limits.MaxConcurrentConnections);
        AgentHost.Initialize(app, options);
        await app.StartAsync();
        var open = new List<Socket>();
        try
        {
            // 64 conexiones que ya hicieron una petición y quedan abiertas (keep-alive): así consta
            // que Kestrel las contó antes de abrir la siguiente.
            for (var i = 0; i < AgentHost.MaxConcurrentConnections; i++)
            {
                var socket = new Socket(AddressFamily.InterNetwork, SocketType.Stream, ProtocolType.Tcp);
                open.Add(socket);
                await socket.ConnectAsync(IPAddress.Loopback, port);
                Assert.True(await SendRootRequestAsync(socket, port) > 0, $"la conexión {i + 1} debía recibir respuesta");
            }

            using (var extra = new Socket(AddressFamily.InterNetwork, SocketType.Stream, ProtocolType.Tcp))
            {
                await extra.ConnectAsync(IPAddress.Loopback, port);
                Assert.Equal(0, await SendRootRequestAsync(extra, port));
            }

            // Al cerrar una, vuelve a haber sitio (Kestrel la libera en segundo plano).
            open[0].Dispose();
            open.RemoveAt(0);
            using var client = new HttpClient { Timeout = TimeSpan.FromSeconds(5) };
            HttpStatusCode? status = null;
            for (var attempt = 0; attempt < 50 && status is null; attempt++)
            {
                try
                {
                    using var response = await client.GetAsync($"http://127.0.0.1:{port}/");
                    status = response.StatusCode;
                }
                catch (HttpRequestException)
                {
                    await Task.Delay(100);
                }
            }

            Assert.Equal(HttpStatusCode.OK, status);
        }
        finally
        {
            open.ForEach(s => s.Dispose());
            await app.StopAsync();
            await app.DisposeAsync();
        }
    }

    /// <summary>Manda «GET /» y devuelve los bytes de la primera lectura (0 = Kestrel cerró sin responder).</summary>
    private static async Task<int> SendRootRequestAsync(Socket socket, int port)
    {
        await socket.SendAsync(System.Text.Encoding.ASCII.GetBytes($"GET / HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\n\r\n"), SocketFlags.None);
        var buffer = new byte[4096];
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(10));
        try
        {
            return await socket.ReceiveAsync(buffer, SocketFlags.None, timeout.Token);
        }
        catch (SocketException ex) when (ex.SocketErrorCode is SocketError.ConnectionReset or SocketError.ConnectionAborted)
        {
            return 0;
        }
    }

    [Fact]
    public void File_log_writes_one_json_object_per_line_and_purges_files_older_than_14_days()
    {
        using var data = new TempDirectory();
        var old = Path.Combine(data.Path, "receipt-printer-20200101.log");
        var recent = Path.Combine(data.Path, "receipt-printer-recent.log");
        var unrelated = Path.Combine(data.Path, "otra-cosa.log");
        foreach (var path in new[] { old, recent, unrelated })
        {
            File.WriteAllText(path, "{}\n");
        }

        File.SetLastWriteTimeUtc(old, DateTime.UtcNow.AddDays(-15));
        File.SetLastWriteTimeUtc(recent, DateTime.UtcNow.AddDays(-13));
        File.SetLastWriteTimeUtc(unrelated, DateTime.UtcNow.AddDays(-30));

        using (var provider = new JsonFileLoggerProvider(data.Path, TimeProvider.System))
        {
            var logger = provider.CreateLogger("Prueba");
            logger.LogInformation("Recibo {Number} aceptado", "RG-000123");
            logger.LogError(new InvalidOperationException("fallo"), "Línea con\nsalto");
        }

        Assert.False(File.Exists(old));
        Assert.True(File.Exists(recent));
        Assert.True(File.Exists(unrelated));

        var today = Path.Combine(data.Path, $"receipt-printer-{DateTime.Now:yyyyMMdd}.log");
        var lines = File.ReadAllLines(today);
        Assert.Equal(2, lines.Length);
        var first = System.Text.Json.Nodes.JsonNode.Parse(lines[0])!;
        Assert.Equal("info", (string?)first["level"]);
        Assert.Equal("Prueba", (string?)first["category"]);
        Assert.Equal("Recibo RG-000123 aceptado", (string?)first["message"]);
        var second = System.Text.Json.Nodes.JsonNode.Parse(lines[1])!;
        Assert.Equal("error", (string?)second["level"]);
        Assert.Equal("Línea con\nsalto", (string?)second["message"]);
        Assert.Contains("InvalidOperationException", (string?)second["exception"]);
    }

    internal static int FreeLoopbackPort()
    {
        using var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        return ((IPEndPoint)listener.LocalEndpoint).Port;
    }
}
