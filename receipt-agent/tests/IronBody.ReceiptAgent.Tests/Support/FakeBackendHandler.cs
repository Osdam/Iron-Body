using System.Collections.Concurrent;
using System.Net;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace IronBody.ReceiptAgent.Tests.Support;

/// <summary>Petición que el servicio hizo al backend simulado.</summary>
public sealed record BackendCall(string Path, JsonObject Body, long? ContentLength, string? ContentType, string? Accept, string? UserAgent);

/// <summary>
/// Backend Laravel simulado a nivel HTTP: el servicio usa su HttpClient real y su parser real; solo
/// cambia quién contesta. Por defecto canjea cualquier ticket como un print de 3 líneas y acepta el report.
/// </summary>
public sealed class FakeBackendHandler : HttpMessageHandler
{
    private readonly ConcurrentQueue<BackendCall> _calls = new();

    public Func<JsonObject, Task<HttpResponseMessage>> Redeem { get; set; } =
        _ => Task.FromResult(Json(HttpStatusCode.OK, PrintRedeem(SampleJob())));

    public Func<JsonObject, Task<HttpResponseMessage>> Report { get; set; } =
        _ => Task.FromResult(Json(HttpStatusCode.OK, new JsonObject { ["ok"] = true, ["recorded"] = true }));

    /// <summary>Lentitud simulada del servidor; respeta la cancelación como un servidor real cortado.</summary>
    public TimeSpan RedeemDelay { get; set; }

    public TimeSpan ReportDelay { get; set; }

    public IReadOnlyList<BackendCall> Calls => _calls.ToArray();

    public IReadOnlyList<BackendCall> RedeemCalls => _calls.Where(c => c.Path.EndsWith("/redeem", StringComparison.Ordinal)).ToArray();

    public IReadOnlyList<BackendCall> ReportCalls => _calls.Where(c => c.Path.EndsWith("/report", StringComparison.Ordinal)).ToArray();

    public List<Uri> RequestUris { get; } = new();

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        var text = request.Content is null ? "{}" : await request.Content.ReadAsStringAsync(cancellationToken);
        var body = JsonNode.Parse(text)!.AsObject();
        lock (RequestUris)
        {
            RequestUris.Add(request.RequestUri!);
        }

        _calls.Enqueue(new BackendCall(request.RequestUri!.AbsolutePath, body, request.Content?.Headers.ContentLength,
            request.Content?.Headers.ContentType?.ToString(), request.Headers.Accept.ToString(), request.Headers.UserAgent.ToString()));
        var path = request.RequestUri.AbsolutePath;
        var delay = path.EndsWith("/redeem", StringComparison.Ordinal) ? RedeemDelay : ReportDelay;
        if (delay > TimeSpan.Zero)
        {
            await Task.Delay(delay, cancellationToken);
        }

        return path switch
        {
            "/api/receipt-agent/redeem" => await Redeem(body),
            "/api/receipt-agent/report" => await Report(body),
            _ => new HttpResponseMessage(HttpStatusCode.NotFound),
        };
    }

    public static HttpResponseMessage Json(HttpStatusCode status, JsonNode body) => new(status)
    {
        Content = new StringContent(body.ToJsonString(), Encoding.UTF8, "application/json"),
    };

    public static HttpResponseMessage Error(HttpStatusCode status, string code) =>
        Json(status, new JsonObject { ["ok"] = false, ["code"] = code, ["message"] = "mensaje del backend" });

    public static JsonObject PrintRedeem(JsonObject job, string purpose = "print") => new()
    {
        ["ok"] = true,
        ["purpose"] = purpose,
        ["ticket_id"] = Guid.NewGuid().ToString(),
        ["job"] = job,
    };

    public static JsonObject ConfigureRedeem(JsonObject settings) => new()
    {
        ["ok"] = true,
        ["purpose"] = "configure",
        ["ticket_id"] = Guid.NewGuid().ToString(),
        ["settings"] = settings,
    };

    public static JsonObject SampleJob(int columns = 32, string? receiptNumber = "RG-000123", string kind = "original", params (string Text, string Style)[] lines)
    {
        var items = lines.Length == 0
            ? new[] { ("IRON BODY WORKOUT", "title"), ("Recibo              RG-000123", "bold"), ("--------------------------------", "normal") }
            : lines;

        var array = new JsonArray();
        foreach (var (text, style) in items)
        {
            array.Add(new JsonObject { ["text"] = text, ["style"] = style });
        }

        return new JsonObject
        {
            ["receipt_number"] = receiptNumber,
            ["copy_label"] = null,
            ["kind"] = kind,
            ["columns"] = columns,
            ["feed_lines"] = 4,
            ["cut"] = false,
            ["lines"] = array,
        };
    }

    public static string ToJson(JsonNode node) => node.ToJsonString(new JsonSerializerOptions { WriteIndented = false });
}
