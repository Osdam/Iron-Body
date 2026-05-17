/// Heurística compartida por el frente y el reverso para decidir si lo que la
/// cámara está viendo realmente PARECE un documento de identidad (y no, por
/// ejemplo, un teclado u otro objeto con texto).
///
/// No es infalible, pero evita el caso "validó un teclado": exige señales
/// propias de un documento (palabras esperadas en una cédula/tarjeta, o la
/// combinación fecha + número largo + texto). Tolerante con el encuadre/ángulo:
/// esto solo mira el TEXTO OCR, no la posición exacta.
///
/// Nunca registra el texto OCR en consola.
class DocumentSignals {
  DocumentSignals._();

  /// Subcadenas (sin tildes, en minúscula) típicas de un documento de identidad
  /// colombiano (frente y reverso) y genéricos. Se comparan como substrings, de
  /// modo que lecturas OCR parciales ("registr", "colomb", "ciudadan"…) también
  /// cuentan. Un teclado u objeto cualquiera no contiene ninguna de estas.
  static const List<String> _keywords = [
    'republica', 'colombia',
    'registrad', 'registraduria', 'nacional del estado civil', 'naciona',
    'identific', 'identidad', 'documento',
    'cedula', 'tarjeta', 'ciudadan',
    'nombre', 'apellid',
    'nacimient', 'nacido', 'fecha de nac',
    'expedicion', 'lugar de nac',
    'estado civil', 'mecanizada',
    'grupo sangu', 'sexo', 'estatura',
    // Genéricos (otros países / pasaporte)
    'passport', 'national id', 'identity card', 'date of birth',
  ];

  /// Palabras sueltas (coincidencia exacta) que, por sí solas, son señal débil
  /// pero útiles para el reverso. Un teclado no contiene estas palabras.
  static final RegExp _standaloneWords = RegExp(
    r'\b(rh|registrador|registradora|firma|huella|index|indice|dactilar)\b',
  );

  static final RegExp _dateLike = RegExp(
    r'\b\d{1,2}[\/\-. ]\d{1,2}[\/\-. ]\d{2,4}\b|\b\d{4}[\/\-. ]\d{1,2}[\/\-. ]\d{1,2}\b'
    r'|\b\d{1,2}\s*[a-z]{3,}\s*\d{4}\b',
  );
  static final RegExp _longNumber = RegExp(r'(?<!\d)\d[\d.\s]{5,12}\d(?!\d)');
  static final RegExp _wordLike = RegExp(r'[a-zñ]{4,}');

  /// ¿El texto OCR sugiere que esto es un documento de identidad?
  static bool isLikelyDocument(String ocrText) {
    final raw = ocrText.trim();
    if (raw.length < 4) return false;
    final t = _normalize(raw);

    // 1) Alguna palabra/expresión propia de un documento.
    for (final kw in _keywords) {
      if (t.contains(kw)) return true;
    }
    if (_standaloneWords.hasMatch(t)) return true;

    // 2) Combinación documento-típica: una fecha + un número largo + algo de
    //    texto con palabras reales. Un teclado no tiene fechas ni números largos.
    final hasDate = _dateLike.hasMatch(t);
    final hasLongNum = _longNumber.hasMatch(t);
    final words = _wordLike.allMatches(t).length;
    if (hasDate && hasLongNum && words >= 2) return true;

    return false;
  }

  static String _normalize(String input) {
    var s = input.toLowerCase();
    const from = 'áàâäãéèêëíìîïóòôöõúùûüñ';
    const to = 'aaaaaeeeeiiiiooooouuuun';
    final b = StringBuffer();
    for (final ch in s.split('')) {
      final i = from.indexOf(ch);
      b.write(i == -1 ? ch : to[i]);
    }
    s = b.toString();
    return s.replaceAll(RegExp(r'\s+'), ' ');
  }
}
