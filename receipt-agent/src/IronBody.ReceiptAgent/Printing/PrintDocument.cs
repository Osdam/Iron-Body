namespace IronBody.ReceiptAgent.Printing;

/// <summary>Una línea del documento de impresión (contrato §2).</summary>
public sealed record PrintLine(string Text, string Style);

/// <summary>
/// Documento de impresión tal como lo maqueta el backend (contrato §2). El servicio no añade ni quita
/// líneas: el rótulo de copia y el número de recibo ya vienen dentro de <see cref="Lines"/>.
/// </summary>
public sealed record PrintDocument(
    string? ReceiptNumber,
    string? CopyLabel,
    string Kind,
    int Columns,
    int FeedLines,
    bool Cut,
    IReadOnlyList<PrintLine> Lines);

public static class LineStyles
{
    public const string Normal = "normal";
    public const string Bold = "bold";
    public const string Title = "title";

    public static bool IsKnown(string? style) => style is Normal or Bold or Title;
}

public static class DocumentKinds
{
    public static bool IsKnown(string? kind) => kind is "original" or "retry" or "reprint" or "test";
}
