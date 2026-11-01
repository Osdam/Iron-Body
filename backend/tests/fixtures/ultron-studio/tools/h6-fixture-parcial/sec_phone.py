# phone · StudioCanaryPhone::parse() y ::masked() (contrato §I4.3)
# Cada caso: (descripción, entrada cruda, canal, prefijos permitidos, resultado escrito a mano).
# Resultado: ("ok", e164, countryCode, last4) o (código de error,).
W, V = "whatsapp", "voice"
CO = ["57"]
BUL = "•••"


def masked(cc, last4):
    return f"+{cc} {BUL} {BUL} {last4}"


def P(desc, raw, channel, allowed, *exp):
    if exp[0] == "ok":
        _, e164, cc, last4 = exp
        expected = {"ok": True, "e164": e164, "countryCode": cc, "last4": last4, "masked": masked(cc, last4)}
    else:
        assert len(exp) == 1
        expected = {"ok": False, "error": exp[0]}
    add("phone", "parse", f"{channel} · {desc}", {"raw": raw, "channel": channel, "allowedCountryCodes": allowed}, expected)


IF, CNA, NAC = "invalid_format", "country_not_allowed", "not_allowed_for_channel"

# ── Válidos en WhatsApp (país 57: ^\+573\d{9}$) ──
P("móvil en E.164, sin separadores", "+573000000001", W, CO, "ok", "+573000000001", "57", "0001")
P("móvil con espacios", "+57 300 000 0001", W, CO, "ok", "+573000000001", "57", "0001")
P("móvil agrupado de dos en dos", "+57 300 000 00 02", W, CO, "ok", "+573000000002", "57", "0002")
P("móvil con guiones", "+57-300-000-0003", W, CO, "ok", "+573000000003", "57", "0003")
P("móvil con puntos", "+57.300.000.0004", W, CO, "ok", "+573000000004", "57", "0004")
P("móvil con paréntesis y guion", "+57 (300) 000-0005", W, CO, "ok", "+573000000005", "57", "0005")
P("el prefijo entre paréntesis con el + dentro", "(+57) 300 000 0006", W, CO, "ok", "+573000000006", "57", "0006")
P("espacios al principio y al final", "  +57 300 000 0007  ", W, CO, "ok", "+573000000007", "57", "0007")
P("un guion entre cada dígito: solo se quitan, sin mirar dónde están", "+5-7-3-0-0-0-0-0-0-0-0-8", W, CO, "ok", "+573000000008", "57", "0008")
P("paréntesis, puntos y guiones repetidos sin sentido", "+57((300))..000--0009", W, CO, "ok", "+573000000009", "57", "0009")
P("un guion antes del +", "-+573000000010", W, CO, "ok", "+573000000010", "57", "0010")
P("un punto al final", "+57 300 000 0011.", W, CO, "ok", "+573000000011", "57", "0011")
P("otro prefijo de móvil (310)", "+57 310 000 0012", W, CO, "ok", "+573100000012", "57", "0012")
P("prefijo de móvil 350", "+57 350 000 0013", W, CO, "ok", "+573500000013", "57", "0013")
P("el ejemplo del contrato (+57 ••• ••• 4821)", "+57 300 000 4821", W, CO, "ok", "+573000004821", "57", "4821")

# ── Válidos en voz (país 57: ^\+57(?:3\d{9}|60\d{8})$) ──
P("fijo de Bogotá (601)", "+57 601 000 0001", V, CO, "ok", "+576010000001", "57", "0001")
P("fijo de Medellín (604) con paréntesis y guion", "+57 (604) 000-0002", V, CO, "ok", "+576040000002", "57", "0002")
P("fijo 608", "+57 608 000 0003", V, CO, "ok", "+576080000003", "57", "0003")
P("un móvil también vale para voz", "+57 300 000 0014", V, CO, "ok", "+573000000014", "57", "0014")
P("600: la regla 60\\d{8} lo admite (literal)", "+57 600 000 0004", V, CO, "ok", "+576000000004", "57", "0004")
P("609: la regla 60\\d{8} lo admite (literal)", "+57 609 000 0005", V, CO, "ok", "+576090000005", "57", "0005")

# ── Regla del canal (país 57): pasa E.164 y cae en el canal ──
P("un fijo (601) no vale para WhatsApp", "+57 601 000 0001", W, CO, NAC)
P("móvil con un dígito de menos: pasa E.164 y cae en el canal", "+57 300 000 000", W, CO, NAC)
P("móvil con un dígito de más: pasa E.164 y cae en el canal", "+57 300 000 00012", W, CO, NAC)
P("móvil con un dígito de menos", "+57 300 000 000", V, CO, NAC)
P("fijo con el formato viejo (1 + 7 dígitos)", "+57 1 000 0001", V, CO, NAC)
P("fijo con el formato viejo (1 + 7 dígitos)", "+57 1 000 0001", W, CO, NAC)
P("número que empieza por 2", "+57 200 000 0001", W, CO, NAC)
P("número que empieza por 2", "+57 200 000 0001", V, CO, NAC)
P("61X no es fijo: solo 60X", "+57 610 000 0001", V, CO, NAC)
P("fijo con un dígito de más", "+57 601 000 00012", V, CO, NAC)
P("fijo con un dígito de menos", "+57 601 000 000", V, CO, NAC)
P("línea 01 8000 (1 800…) no es 60X ni móvil", "+57 1 800 000 0001", V, CO, NAC)
P("57 seguido de 0", "+57 0300 000 0001", W, CO, NAC)

# ── País: el prefijo MÁS LARGO de la lista con el que empieza ──
P("EE. UU. con solo 57 permitido", "+1 202 555 0101", W, CO, CNA)
P("EE. UU. con 1 permitido: sin regla de canal, basta E.164", "+1 202 555 0101", W, ["57", "1"], "ok", "+12025550101", "1", "0101")
P("EE. UU. con 1 permitido, con paréntesis y guion", "+1 (202) 555-0102", V, ["57", "1"], "ok", "+12025550102", "1", "0102")
P("Reino Unido (rango de ficción 7946 0xxx) con 44 permitido", "+44 20 7946 0001", W, ["57", "44"], "ok", "+442079460001", "44", "0001")
P("Reino Unido con solo 57 permitido", "+44 20 7946 0002", V, CO, CNA)
P("['5','57']: gana 57 y un fijo cae en la regla de WhatsApp", "+57 601 000 0007", W, ["5", "57"], NAC)
P("['57','5']: el orden de la lista no cuenta, gana el más largo", "+57 601 000 0007", W, ["57", "5"], NAC)
P("['5','57']: un móvil colombiano sale con país 57", "+57 300 000 0015", W, ["5", "57"], "ok", "+573000000015", "57", "0015")
P("['5','57'] con un número de México: solo casa 5 (configuración patológica, literal)", "+52 55 0000 0001", W, ["5", "57"], "ok", "+525500000001", "5", "0001")
P("lista de países vacía: no casa ninguno", "+57 300 000 0016", W, [], CNA)
P("Ecuador (593) permitido: sin regla de canal", "+593 2 000 0001", V, ["57", "593"], "ok", "+59320000001", "593", "0001")
P("solo 1 permitido y un móvil colombiano", "+57 300 000 0017", W, ["1"], CNA)
P("57 aparece dentro del número pero no al principio", "+1 575 555 0100", W, CO, CNA)

# ── Formato: se quitan SOLO U+0020, '-', '.', '(' y ')'; después ^\+[1-9]\d{7,14}$ ──
P("vacío", "", W, CO, IF)
P("solo espacios", "   ", W, CO, IF)
P("solo separadores", "( ) - .", W, CO, IF)
P("solo el +", "+", W, CO, IF)
P("sin +", "573000000001", W, CO, IF)
P("00 en vez de +", "0057 300 000 0001", W, CO, IF)
P("dos +", "++57 300 000 0001", W, CO, IF)
P("un + en medio", "+57 300 +000 0001", W, CO, IF)
P("un + al final", "+57 300 000 0001+", W, CO, IF)
P("extensión con letras", "+57 300 000 0001 ext. 12", W, CO, IF)
P("la letra O en vez de cero", "+57 3OO OOO OOO1", W, CO, IF)
P("tabulador (no es U+0020)", "+57\t300 000 0001", W, CO, IF)
P("espacio duro U+00A0 (no es U+0020)", "+57 300 000 0001", W, CO, IF)
P("espacio fino U+202F", "+57 300 000 0001", W, CO, IF)
P("raya U+2013 (no es '-')", "+57–300–000–0001", W, CO, IF)
P("signo menos U+2212 (no es '-')", "+57−300−000−0001", W, CO, IF)
P("barra", "+57/300/000/0001", W, CO, IF)
P("guion bajo", "+57_300_000_0001", W, CO, IF)
P("salto de línea al final ($ de PCRE sin D lo dejaría pasar)", "+57 300 000 0001\n", W, CO, IF)
P("salto de línea al principio", "\n+57 300 000 0001", W, CO, IF)
P("CRLF al final", "+57 300 000 0001\r\n", W, CO, IF)
P("NUL al final", "+573000000001\u0000", W, CO, IF)
P("+ de ancho completo U+FF0B", "＋57 300 000 0001", W, CO, IF)
P("dígitos de ancho completo (\\d con /u los aceptaría)", "+57 ３００ ０００ ０００１", W, CO, IF)
P("dígitos arábigo-índicos", "+57 ٣٠٠ ٠٠٠ ٠٠٠١", W, CO, IF)
P("espacio de anchura cero U+200B", "+57​3000000001", W, CO, IF)
P("esquema tel:", "tel:+573000000001", W, CO, IF)
P("emoji al final", "+57 300 000 0001 \U0001F4F1", W, CO, IF)
P("dos números separados por coma", "+57 300 000 0001, +57 300 000 0002", W, CO, IF)
P("un 0 tras el +", "+057 300 000 0001", W, CO, IF)
P("+0", "+0", W, CO, IF)
P("7 dígitos: uno menos que el mínimo de E.164", "+1234567", W, ["1"], IF)
P("8 dígitos: el mínimo de E.164", "+12345678", W, ["1"], "ok", "+12345678", "1", "5678")
P("15 dígitos: el máximo de E.164", "+123456789012345", V, ["1"], "ok", "+123456789012345", "1", "2345")
P("16 dígitos: uno más que el máximo", "+1234567890123456", V, ["1"], IF)
P("el formato va antes que el país: letras en un número de un país no permitido", "+1 202 555 0101 x", W, CO, IF)
P("el país va antes que el canal: un fijo de un país no permitido", "+44 20 7946 0003", W, CO, CNA)

# ── masked(countryCode, last4): "+<país> ••• ••• <4 dígitos>" (U+2022) ──
for cc, last4 in [("57", "4821"), ("1", "0101"), ("593", "0001"), ("57", "0000")]:
    add("phone", "masked", f"+{cc} y {last4}", {"countryCode": cc, "last4": last4}, {"masked": masked(cc, last4)})
