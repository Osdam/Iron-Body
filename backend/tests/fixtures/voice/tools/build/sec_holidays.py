# holidays-co-2026-2028.json · Telephony\ColombianHolidays (D8-15: «pura: Ley 51 de 1983 y los móviles de Pascua; el fixture de
# 2026–2028 lo aprueba el propietario»). Dos derivaciones que tienen que coincidir: la lista escrita A MANO abajo y la regla
# (Pascua por el algoritmo gregoriano anónimo + traslado al lunes). El comprobador rehace la Pascua con otro algoritmo.
HO = Fixture("holidays-co-2026-2028.json", header(
    "holidays-co-2026-2028.json",
    ["D8-15 (festivos: Telephony\\ColombianHolidays, Ley 51 de 1983 y los móviles de Pascua; fixture 2026–2028 aprobado por el propietario)",
     "D8-12 (motivo holiday de la puerta de salientes)", "D8-18 (horario de salientes en Colombia)",
     "Enmienda 2, E2-1 (Ley 2578 del 01-06-2026: la Virgen de Chiquinquirá; 19 festivos por año mientras la ley esté vigente, «por confirmar (Ley 2578/2026, demandada)»)"],
    [
        {"what": "Ley 51 de 1983, art. 1 (modifica el art. 177 del Código Sustantivo del Trabajo): los 18 días de fiesta y el traslado al lunes («Ley Emiliani»)",
         "url": "http://www.secretariasenado.gov.co/senado/basedoc/ley_0051_1983.html", "consulted": "NO consultado hoy (sin red)",
         "how": "Texto conocido de la ley: el 6 de enero, 19 de marzo, 29 de junio, 15 de agosto, 12 de octubre, 1 y 11 de noviembre, la Ascensión, el Corpus Christi y el Sagrado Corazón, si no caen en lunes, se trasladan al lunes siguiente (también si caen en domingo). [por confirmar con la fuente oficial antes de aprobar]"},
        {"what": "Ley 2578 del 01-06-2026: el 9 de julio, Nuestra Señora del Rosario de Chiquinquirá, con las disposiciones de la Ley 51 de 1983 (trasladable al lunes); rige desde su promulgación, así que cuenta desde 2026",
         "url": "(no consultada hoy: sin red)", "consulted": "03-11-2026, por la Enmienda 2 (E2-1) del contrato y la nota de app/Services/Telephony/ColombianHolidays.php (B8-a), que cita la noticia del Ministerio del Interior del 05-06-2026 y la sinopsis del Centro de Estudios Regulatorios",
         "how": "POR CONFIRMAR: la ley está demandada ante la Corte Constitucional. Tratar el día como festivo solo restringe más las salientes; si la Corte la tumba, se quita en un commit (E2-1)."},
        {"what": "Fechas de Pascua 2026–2028", "url": "(cálculo)", "consulted": "03-11-2026",
         "how": "Algoritmo gregoriano anónimo (Meeus/Jones/Butcher) en el generador, el de Gauss en el comprobador y easter_days() de PHP 8.3: 05-04-2026, 28-03-2027 y 16-04-2028 (las tres coinciden)."},
    ],
    approvedByOwner=False,
    approvalNote="Propuesta para la aprobación del propietario (D8-15, P-13). Hasta entonces, ColombianHolidays se prueba contra esta lista y la política no se enciende para contactos (fase 4b). Son 19 festivos por año con la Ley 2578 de 2026 (E2-1), el de Chiquinquirá «por confirmar (Ley 2578/2026, demandada)».",
    pendingConfirmation={"Virgen de Chiquinquirá": "por confirmar (Ley 2578/2026, demandada ante la Corte Constitucional; E2-1)"},
    timezone="America/Bogota (UTC−05:00 todo el año, sin horario de verano desde 1993)",
    rule={
        "fixed": ["01-01 Año Nuevo", "05-01 Día del Trabajo", "07-20 Día de la Independencia", "08-07 Batalla de Boyacá",
                  "12-08 Inmaculada Concepción", "12-25 Navidad"],
        "emiliani": ["01-06 Reyes Magos", "03-19 San José", "06-29 San Pedro y San Pablo", "08-15 Asunción de la Virgen",
                     "10-12 Día de la Raza", "11-01 Todos los Santos", "11-11 Independencia de Cartagena"],
        "emilianiLey2578": ["07-09 Virgen de Chiquinquirá, desde 2026 (Ley 2578 de 2026, por confirmar: demandada)"],
        "easter": ["Pascua − 3 Jueves Santo", "Pascua − 2 Viernes Santo"],
        "easterEmiliani": ["Pascua + 39 Ascensión del Señor → lunes (Pascua + 43)", "Pascua + 60 Corpus Christi → lunes (Pascua + 64)",
                           "Pascua + 68 Sagrado Corazón de Jesús → lunes (Pascua + 71)"],
        "move": "si no cae en lunes, al lunes siguiente (de domingo, al día después)",
        "notHolidays": "el domingo de Pascua, el sábado santo, el 24 y el 31 de diciembre no son festivos (el domingo ya queda fuera del horario de salientes)",
    },
))


def easter(y):
    a, b, c = y % 19, y // 100, y % 100
    d, e = b // 4, b % 4
    f = (b + 8) // 25
    g = (b - f + 1) // 3
    h = (19 * a + b - d - g + 15) % 30
    i, k = c // 4, c % 4
    l = (32 + 2 * e + 2 * i - h - k) % 7
    m = (a + 11 * h + 22 * l) // 451
    month = (h + l - 7 * m + 114) // 31
    day = ((h + l - 7 * m + 114) % 31) + 1
    return dt.date(y, month, day)


def next_monday(d):
    return d if d.weekday() == 0 else d + dt.timedelta(days=7 - d.weekday())


FIXED = [(1, 1, "Año Nuevo"), (5, 1, "Día del Trabajo"), (7, 20, "Día de la Independencia"), (8, 7, "Batalla de Boyacá"),
         (12, 8, "Inmaculada Concepción"), (12, 25, "Navidad")]
MOVABLE = [(1, 6, "Reyes Magos"), (3, 19, "San José"), (6, 29, "San Pedro y San Pablo"), (8, 15, "Asunción de la Virgen"),
           (10, 12, "Día de la Raza"), (11, 1, "Todos los Santos"), (11, 11, "Independencia de Cartagena")]
LEY_2578 = (7, 9, "Virgen de Chiquinquirá", 2026)   # E2-1: trasladable al lunes, desde 2026, por confirmar
PENDING = "por confirmar (Ley 2578/2026, demandada ante la Corte Constitucional; E2-1)"
EASTER = [(-3, "Jueves Santo"), (-2, "Viernes Santo")]
EASTER_MOVABLE = [(39, "Ascensión del Señor"), (60, "Corpus Christi"), (68, "Sagrado Corazón de Jesús")]


def by_rule(y):
    out = []
    for mo, d, n in FIXED:
        out.append({"date": dt.date(y, mo, d), "name": n, "rule": "fijo", "baseDate": None})
    for mo, d, n in MOVABLE:
        base = dt.date(y, mo, d)
        out.append({"date": next_monday(base), "name": n, "rule": "emiliani", "baseDate": base})
    if y >= LEY_2578[3]:
        base = dt.date(y, LEY_2578[0], LEY_2578[1])
        out.append({"date": next_monday(base), "name": LEY_2578[2], "rule": "emiliani", "baseDate": base, "pending": PENDING})
    e = easter(y)
    for off, n in EASTER:
        out.append({"date": e + dt.timedelta(days=off), "name": n, "rule": "pascua", "baseDate": None})
    for off, n in EASTER_MOVABLE:
        base = e + dt.timedelta(days=off)
        out.append({"date": next_monday(base), "name": n, "rule": "pascua-emiliani", "baseDate": base})
    out.sort(key=lambda x: x["date"])
    return out


# La lista A MANO (contrastada con la regla, no copiada de ella).
BY_HAND = {
    2026: ["01-01", "01-12", "03-23", "04-02", "04-03", "05-01", "05-18", "06-08", "06-15", "06-29", "07-13", "07-20", "08-07", "08-17",
           "10-12", "11-02", "11-16", "12-08", "12-25"],
    2027: ["01-01", "01-11", "03-22", "03-25", "03-26", "05-01", "05-10", "05-31", "06-07", "07-05", "07-12", "07-20", "08-07", "08-16",
           "10-18", "11-01", "11-15", "12-08", "12-25"],
    2028: ["01-01", "01-10", "03-20", "04-13", "04-14", "05-01", "05-29", "06-19", "06-26", "07-03", "07-10", "07-20", "08-07", "08-21",
           "10-16", "11-06", "11-13", "12-08", "12-25"],
}
EASTER_BY_HAND = {2026: "2026-04-05", 2027: "2027-03-28", 2028: "2028-04-16"}

YEARS = {}
ALL = set()
for y in (2026, 2027, 2028):
    rows = by_rule(y)
    got = [r["date"].strftime("%m-%d") for r in rows]
    assert got == BY_HAND[y], (y, got)
    assert easter(y).isoformat() == EASTER_BY_HAND[y]
    assert len(got) == 19 and len(set(got)) == 19, f"{y}: 19 festivos y ninguno en la misma fecha (E2-1)"
    for r in rows:
        if r["rule"] in ("emiliani", "pascua-emiliani"):
            assert r["date"].weekday() == 0
        ALL.add(r["date"])
    YEARS[str(y)] = [{"date": r["date"].isoformat(), "weekday": weekday_es(r["date"]), "name": r["name"], "rule": r["rule"],
                      "baseDate": r["baseDate"].isoformat() if r["baseDate"] else None,
                      "moved": bool(r["baseDate"] and r["baseDate"] != r["date"]),
                      "pendingConfirmation": r.get("pending")} for r in rows]
HO.header["easter"] = {str(k): v for k, v in EASTER_BY_HAND.items()}
HO.header["years"] = YEARS


def D(desc, date_s, holiday, *, name=None, why=None, basis="ley", pending=None):
    exp = {"holiday": holiday}
    if holiday:
        exp["name"] = name
    if pending:
        exp["pendingConfirmation"] = pending
    if why:
        exp["why"] = why
    d = dt.date.fromisoformat(date_s)
    assert (d in ALL) == holiday, desc
    HO.add("isHoliday", "date", desc, "D8-15, E2-1" if pending or name == LEY_2578[2] else "D8-15", basis,
           {"date": date_s, "weekday": weekday_es(d)}, exp)


def name_of(d):
    for rows in YEARS.values():
        for r in rows:
            if r["date"] == d:
                return r["name"]


for y, rows in YEARS.items():
    for r in rows:
        if r["moved"]:
            D(f"{r['name']} {y}: se celebra el lunes {r['date']}", r["date"], True, name=r["name"], pending=r["pendingConfirmation"])
            D(f"{r['name']} {y}: su fecha de origen {r['baseDate']} ({weekday_es(dt.date.fromisoformat(r['baseDate']))}) no es festivo",
              r["baseDate"], False, why="trasladado al lunes (Ley 51 de 1983" + (", por la Ley 2578 de 2026)" if r["pendingConfirmation"] else ")"),
              pending=r["pendingConfirmation"])
D("Todos los Santos 2027 cae en lunes: no se traslada", "2027-11-01", True, name="Todos los Santos")
D("San Pedro y San Pablo 2026 cae en lunes: no se traslada", "2026-06-29", True, name="San Pedro y San Pablo")
D("Día de la Raza 2026 cae en lunes: no se traslada", "2026-10-12", True, name="Día de la Raza")
D("1 de mayo de 2027 cae en sábado y sigue siendo festivo (los fijos no se trasladan)", "2027-05-01", True, name="Día del Trabajo")
D("7 de agosto de 2027 cae en sábado y sigue siendo festivo", "2027-08-07", True, name="Batalla de Boyacá")
D("Navidad de 2027 cae en sábado y sigue siendo festivo", "2027-12-25", True, name="Navidad")
D("1 de enero de 2028 cae en sábado y sigue siendo festivo", "2028-01-01", True, name="Año Nuevo")
D("Jueves Santo 2026", "2026-04-02", True, name="Jueves Santo")
D("Viernes Santo 2027 (marzo)", "2027-03-26", True, name="Viernes Santo")
D("el sábado santo de 2026 no es festivo", "2026-04-04", False)
D("el domingo de Pascua de 2026 no es festivo", "2026-04-05", False)
D("el lunes de Pascua de 2028 no es festivo", "2028-04-17", False)
D("24 de diciembre de 2026 no es festivo", "2026-12-24", False)
D("31 de diciembre de 2027 no es festivo", "2027-12-31", False)
D("29 de febrero de 2028 (bisiesto) es un martes laborable", "2028-02-29", False)
D("el día siguiente a un festivo trasladado (martes 13-01-2026) no es festivo", "2026-01-13", False)


def At(desc, utc, holiday, *, name=None, pending=None):
    b = to_bogota(utc)
    exp = {"bogotaDate": b.date().isoformat(), "bogotaTime": b.strftime("%H:%M:%S"), "holiday": holiday}
    if holiday:
        exp["name"] = name
    if pending:
        exp["pendingConfirmation"] = pending
    assert (b.date() in ALL) == holiday, desc
    HO.add("isHoliday", "instant", desc, "D8-15, E2-1" if pending else "D8-15", "ley", {"at": utc}, exp)


At("04:59:59 UTC del 12-01-2026 todavía es domingo 11 en Bogotá: no es festivo", "2026-01-12T04:59:59Z", False)
At("05:00:00 UTC del 12-01-2026 es lunes 12 a medianoche en Bogotá: festivo", "2026-01-12T05:00:00Z", True, name="Reyes Magos")
At("04:59:59 UTC del 13-01-2026 todavía es el lunes festivo en Bogotá", "2026-01-13T04:59:59Z", True, name="Reyes Magos")
At("05:00:00 UTC del 13-01-2026 ya es martes: no es festivo", "2026-01-13T05:00:00Z", False)
At("Navidad de 2026 a las 23:30 de Bogotá (04:30 UTC del 26)", "2026-12-26T04:30:00Z", True, name="Navidad")
At("Año Nuevo de 2027 a las 00:00 de Bogotá (05:00 UTC)", "2027-01-01T05:00:00Z", True, name="Año Nuevo")
At("Virgen de Chiquinquirá 2026 (E2-1, por confirmar): el lunes 13-07 a las 00:00 de Bogotá (05:00 UTC) ya es festivo", "2026-07-13T05:00:00Z", True,
   name="Virgen de Chiquinquirá", pending=PENDING)
At("Virgen de Chiquinquirá 2026: el domingo 12-07 a las 23:59:59 de Bogotá (04:59:59 UTC del 13) todavía no", "2026-07-13T04:59:59Z", False,
   pending=PENDING)
At("31-12-2026 a las 23:59:59 de Bogotá (04:59:59 UTC del 1 de enero): no es festivo", "2027-01-01T04:59:59Z", False)
