using System.Text;
using IronBody.ReceiptAgent.Settings;

namespace IronBody.ReceiptAgent.Printing;

/// <summary>
/// Verifica las reglas del contrato §2 que el backend garantiza. Si algo no cumple el trabajo se
/// rechaza entero (<c>invalid_job</c>): recortar una línea en silencio podría quitar un importe del
/// papel sin que nadie lo note.
/// </summary>
public static class PrintDocumentValidator
{
    public const int MinLines = 1;
    public const int MaxLines = 400;
    public const int MaxLabelLength = 64;

    /// <summary>
    /// Devuelve null si el documento es imprimible, o el motivo. El motivo cita números de línea y
    /// longitudes, nunca el texto: las líneas llevan nombres de clientes e importes.
    /// </summary>
    public static string? FindProblem(PrintDocument document, int stationColumns)
    {
        if (!StationSettingsRules.IsValidColumns(document.Columns))
        {
            return $"columns debe ser 32 o 42 (recibido {document.Columns})";
        }

        if (document.Columns != stationColumns)
        {
            return $"el trabajo viene a {document.Columns} columnas y la estación está configurada a {stationColumns}";
        }

        if (!StationSettingsRules.IsValidFeedLines(document.FeedLines))
        {
            return $"feed_lines debe estar entre 0 y 8 (recibido {document.FeedLines})";
        }

        if (document.Cut)
        {
            return "cut debe ser false en el protocolo 1";
        }

        if (!DocumentKinds.IsKnown(document.Kind))
        {
            return "kind desconocido";
        }

        if (!IsValidLabel(document.ReceiptNumber))
        {
            return "receipt_number con caracteres de control o demasiado largo";
        }

        if (!IsValidLabel(document.CopyLabel))
        {
            return "copy_label con caracteres de control o demasiado largo";
        }

        if (document.Lines.Count is < MinLines or > MaxLines)
        {
            return $"el recibo debe tener entre {MinLines} y {MaxLines} líneas (recibidas {document.Lines.Count})";
        }

        for (var i = 0; i < document.Lines.Count; i++)
        {
            var number = i + 1;
            var line = document.Lines[i];

            if (!LineStyles.IsKnown(line.Style))
            {
                return $"línea {number}: estilo desconocido";
            }

            if (!IsWellFormedUtf16(line.Text))
            {
                return $"línea {number}: texto con UTF-16 inválido";
            }

            if (StationSettingsRules.ContainsControl(line.Text))
            {
                return $"línea {number}: contiene caracteres de control";
            }

            // Un no-carácter (U+FFFE…) hace lanzar a IsNormalized: se rechaza antes con su motivo.
            if (StationSettingsRules.ContainsNoncharacter(line.Text))
            {
                return $"línea {number}: contiene caracteres no válidos";
            }

            if (!line.Text.IsNormalized(NormalizationForm.FormC))
            {
                return $"línea {number}: el texto no está en NFC";
            }

            var length = StationSettingsRules.CodePointLength(line.Text);
            if (length > document.Columns)
            {
                return $"línea {number}: {length} caracteres, el máximo es {document.Columns}";
            }
        }

        return null;
    }

    private static bool IsValidLabel(string? value) =>
        value is null
        || (IsWellFormedUtf16(value)
            && StationSettingsRules.CodePointLength(value) <= MaxLabelLength
            && !StationSettingsRules.ContainsControl(value));

    private static bool IsWellFormedUtf16(string value)
    {
        for (var i = 0; i < value.Length; i++)
        {
            if (Rune.DecodeFromUtf16(value.AsSpan(i), out _, out var consumed) != System.Buffers.OperationStatus.Done)
            {
                return false;
            }

            i += consumed - 1;
        }

        return true;
    }
}
