/// Extracción robusta de la fecha de nacimiento a partir del texto OCR de un
/// documento de identidad (Colombia y genéricos).
///
/// Estrategia:
///  1. Normaliza el texto (mayúsculas, espacios, confusiones OCR en tokens
///     numéricos: O↔0, I/L↔1, S↔5, B↔8, Z↔2).
///  2. Busca primero fechas cercanas a etiquetas como "FECHA DE NACIMIENTO",
///     "F. NACIMIENTO", "NACIDO", "DOB", "BIRTH"…
///  3. Si no hay coincidencia por etiqueta, recoge todas las fechas plausibles
///     y devuelve la MÁS ANTIGUA en el pasado (en una cédula/TI las otras
///     fechas — expedición / vence — son recientes o futuras).
///
/// Nunca registra el texto OCR ni datos sensibles en consola.
class DateExtractor {
  DateExtractor._();

  static const int _maxPlausibleAge = 120;

  static const Map<String, int> _months = {
    // español completo
    'enero': 1, 'febrero': 2, 'marzo': 3, 'abril': 4, 'mayo': 5, 'junio': 6,
    'julio': 7, 'agosto': 8, 'septiembre': 9, 'setiembre': 9, 'octubre': 10,
    'noviembre': 11, 'diciembre': 12,
    // español abreviado
    'ene': 1, 'feb': 2, 'mar': 3, 'abr': 4, 'may': 5, 'jun': 6, 'jul': 7,
    'ago': 8, 'sep': 9, 'set': 9, 'oct': 10, 'nov': 11, 'dic': 12,
    // inglés abreviado
    'jan': 1, 'apr': 4, 'aug': 8, 'dec': 12,
    // inglés completo (los que no chocan)
    'january': 1, 'february': 2, 'march': 3, 'april': 4, 'june': 6, 'july': 7,
    'august': 8, 'october': 10, 'november': 11, 'december': 12,
  };

  static final RegExp _birthLabel = RegExp(
    r'(FECHA\s*(DE)?\s*NAC[A-Z.]*)|(F[.\s]*NAC[A-Z.]*)|(NACIMIENTO)|(NACID[OA])'
    r'|(\bDOB\b)|(BIRTH\s*DATE)|(DATE\s*OF\s*BIRTH)|(BORN)',
    caseSensitive: false,
  );

  /// Patrones de fecha (sobre texto ya normalizado, en mayúsculas).
  static final List<RegExp> _patterns = <RegExp>[
    // DD de MMMM de YYYY  → "5 DE ENERO DE 2010"
    RegExp(r'(\d{1,2})\s*DE\s*([A-ZÁÉÍÓÚÑ]{3,12})\s*DE\s*(\d{4})'),
    // DD MMM[M] YYYY  → "5 ENE 2010", "05-ENERO-2010", "5.ENE.2010"
    RegExp(r'(\d{1,2})[\s./\-]+([A-ZÁÉÍÓÚÑ]{3,12})[\s./\-]+(\d{4})'),
    // YYYY MM DD  → "2010-01-05", "2010/01/05", "2010 01 05"
    RegExp(r'(\d{4})[\s./\-]+(\d{1,2})[\s./\-]+(\d{1,2})'),
    // DD MM YYYY  → "05/01/2010", "05-01-2010", "05.01.2010", "05 01 2010"
    RegExp(r'(\d{1,2})[\s./\-]+(\d{1,2})[\s./\-]+(\d{4})'),
    // DD MMM YY  → "05 ENE 10"  (año de 2 dígitos)
    RegExp(r'(\d{1,2})[\s./\-]+([A-ZÁÉÍÓÚÑ]{3,12})[\s./\-]+(\d{2})\b'),
    // DD MM YY  → "05 01 10"  (último recurso; año de 2 dígitos)
    RegExp(r'(\d{1,2})[\s./\-]+(\d{1,2})[\s./\-]+(\d{2})\b'),
  ];

  /// Devuelve la fecha de nacimiento más probable, o null si no se reconoce.
  static DateTime? extractBirthDate(String rawText) {
    if (rawText.trim().isEmpty) return null;
    final norm = _normalize(rawText);

    // 1) Fechas ancladas a una etiqueta de nacimiento.
    for (final label in _birthLabel.allMatches(norm)) {
      final start = label.end;
      final window = norm.substring(start, (start + 60).clamp(0, norm.length));
      final d = _firstDateIn(window);
      if (d != null && _isPlausibleBirth(d)) return d;
    }

    // 2) Todas las fechas plausibles → la más antigua en el pasado.
    final candidates = <DateTime>[];
    for (final pattern in _patterns) {
      for (final m in pattern.allMatches(norm)) {
        final d = _matchToDate(m);
        if (d != null && _isPlausibleBirth(d)) candidates.add(d);
      }
    }
    if (candidates.isEmpty) return null;
    candidates.sort();
    return candidates.first;
  }

  /// ¿El texto contiene una etiqueta de nacimiento? (uso interno / depuración)
  static bool hasBirthLabel(String rawText) =>
      _birthLabel.hasMatch(_normalize(rawText));

  // ── Internos ──────────────────────────────────────────────────────────────

  static DateTime? _firstDateIn(String text) {
    for (final pattern in _patterns) {
      final m = pattern.firstMatch(text);
      if (m != null) {
        final d = _matchToDate(m);
        if (d != null) return d;
      }
    }
    return null;
  }

  static String _normalize(String input) {
    var t = input.toUpperCase();
    // Cualquier espacio en blanco (saltos de línea, tabs…) → espacio simple.
    t = t.replaceAll(RegExp(r'\s+'), ' ');
    // Confusiones OCR dentro de tokens cortos que parecen fecha/número:
    // contienen letras confundibles + al menos 2 dígitos. No se toca texto
    // largo (nombres) para no corromperlo.
    t = t.replaceAllMapped(
      RegExp(r'\b[0-9OISBLZ]{1,4}(?:[./\- ][0-9OISBLZ]{1,4}){0,2}\b'),
      (m) {
        final tok = m[0]!;
        final digitCount = RegExp(r'\d').allMatches(tok).length;
        // Solo si el token ya tiene al menos un dígito (probable fecha/número),
        // para no corromper palabras como "ISO", "OSO"…
        if (digitCount < 1) return tok;
        return tok
            .replaceAll('O', '0')
            .replaceAll('I', '1')
            .replaceAll('L', '1')
            .replaceAll('S', '5')
            .replaceAll('B', '8')
            .replaceAll('Z', '2');
      },
    );
    return t.replaceAll(RegExp(r' {2,}'), ' ').trim();
  }

  static DateTime? _matchToDate(RegExpMatch m) {
    final g1 = m.group(1);
    final g2 = m.group(2);
    final g3 = m.group(3);
    if (g1 == null || g2 == null || g3 == null) return null;

    // Caso YYYY primero.
    if (g1.length == 4) {
      return _build(int.tryParse(g3), int.tryParse(g2), int.tryParse(g1));
    }

    // ¿g2 es un mes textual?
    int? month;
    if (RegExp(r'^[A-ZÁÉÍÓÚÑ]+$').hasMatch(g2)) {
      final name = g2.toLowerCase();
      month = _months[name] ??
          _months[name.length >= 3 ? name.substring(0, 3) : name];
      if (month == null) return null;
    } else {
      month = int.tryParse(g2);
    }

    final day = int.tryParse(g1);
    var year = int.tryParse(g3);
    if (year == null) return null;
    if (g3.length == 2) {
      final pivot = DateTime.now().year % 100;
      year = year > pivot ? 1900 + year : 2000 + year;
    }
    return _build(day, month, year);
  }

  static DateTime? _build(int? day, int? month, int? year) {
    if (day == null || month == null || year == null) return null;
    if (month < 1 || month > 12) return null;
    if (day < 1 || day > 31) return null;
    if (year < 1900 || year > DateTime.now().year) return null;
    final d = DateTime(year, month, day);
    // DateTime normaliza fechas inválidas (31/02 → 03/03). Rechazarlas.
    if (d.year != year || d.month != month || d.day != day) return null;
    return d;
  }

  static bool _isPlausibleBirth(DateTime d) {
    final now = DateTime.now();
    if (d.isAfter(now)) return false;
    var age = now.year - d.year;
    if (now.month < d.month || (now.month == d.month && now.day < d.day)) age--;
    return age >= 0 && age <= _maxPlausibleAge;
  }
}
