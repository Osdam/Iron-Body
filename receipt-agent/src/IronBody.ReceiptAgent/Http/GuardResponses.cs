using System.Text.Json;
using System.Text.Json.Serialization;
using Microsoft.AspNetCore.Http;

namespace IronBody.ReceiptAgent.Http;

/// <summary>Serialización JSON común de las respuestas del servicio.</summary>
public static class AgentJson
{
    public static readonly JsonSerializerOptions Options = new(JsonSerializerDefaults.Web)
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        DefaultIgnoreCondition = JsonIgnoreCondition.Never,
    };
}

/// <summary>
/// Respuestas de rechazo de las guardas: <c>{ok:false, code}</c> (contrato §3.1). Sin detalles
/// internos: el código basta para que el CRM explique qué pasa.
/// </summary>
public static class GuardResponses
{
    public static Task WriteAsync(HttpContext context, int statusCode, string code)
    {
        context.Response.StatusCode = statusCode;
        context.Response.ContentType = "application/json; charset=utf-8";
        return context.Response.WriteAsync(JsonSerializer.Serialize(new GuardError(false, code), AgentJson.Options));
    }

    private sealed record GuardError(bool Ok, string Code);
}
