# calendar-cases.json · el calendario de la política (D9-7 N1 y ContactBudget «Ventana horaria»; §11: calendar-cases.json; §13.3)
#   holidays · los 19 festivos de 2026–2028 (los mismos de ColombianHolidays y de holidays-co-2026-2028.json del H8)
#   window   · un instante → dentro o fuera: motivos de N1 (ley: calendar_outside_legal_hours, calendar_sunday, calendar_holiday) y de N5
#              (horario propio: calendar_outside_own_hours, L9-15)
#   nextSlot · el primer instante permitido desde uno dado (el «primer hueco permitido» de #17 y T22), con horario propio y de la estrategia
# Ventana de N1 = Ley 2300 (L–V 07:00–19:00, S 08:00–15:00) ∩ horas de silencio de ContactPolicy (21:00–08:00) = L–V [08:00, 19:00) y
# S [08:00, 15:00), sin domingos ni festivos; ∩ horario propio (P9-17) ∩ horario de la estrategia (N5). Intervalos semiabiertos.
H8_HOLIDAYS = json.loads((HERE.parent.parent.parent / "voice" / "holidays-co-2026-2028.json").read_text(encoding="utf-8"))


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


def _next_monday(d):
    return d if d.weekday() == 0 else d + dt.timedelta(days=7 - d.weekday())


def holidays(y):
    out = [dt.date(y, m, d) for m, d in [(1, 1), (5, 1), (7, 20), (8, 7), (12, 8), (12, 25)]]
    out += [_next_monday(dt.date(y, m, d)) for m, d in [(1, 6), (3, 19), (6, 29), (8, 15), (10, 12), (11, 1), (11, 11)]]
    if y >= 2026:
        out.append(_next_monday(dt.date(y, 7, 9)))          # Ley 2578 de 2026 (Chiquinquirá), por confirmar (E2-1 del H8)
    e = easter(y)
    out += [e - dt.timedelta(days=3), e - dt.timedelta(days=2)]
    out += [_next_monday(e + dt.timedelta(days=39)), _next_monday(e + dt.timedelta(days=60)), _next_monday(e + dt.timedelta(days=68))]
    return sorted(out)


for _y in (2026, 2027, 2028):
    _mine = [str(d) for d in holidays(_y)]
    _h8 = sorted(x["date"] for x in H8_HOLIDAYS["years"][str(_y)])
    assert _mine == _h8, ("los festivos no cuadran con el fixture del H8", _y, _mine, _h8)
    assert len(_mine) == 19
HOLIDAYS = {d for y in (2026, 2027, 2028) for d in holidays(y)}

N1_WINDOWS = {1: (480, 1140), 2: (480, 1140), 3: (480, 1140), 4: (480, 1140), 5: (480, 1140), 6: (480, 900)}   # ISO día → [abre, cierra) en minutos


def _intersect(a, b):
    lo, hi = max(a[0], b[0]), min(a[1], b[1])
    return (lo, hi) if lo < hi else None


def day_windows(d, own=None, schedule=None):
    """Las franjas permitidas de un día de Bogotá: lista de [abre, cierra) en minutos (vacía = cerrado)."""
    iso = d.isoweekday()
    if iso == 7 or d in HOLIDAYS:
        return []
    base = N1_WINDOWS[iso]
    if own is not None:
        if own == "invalid":
            return []                                         # mal configurado: falla cerrado (como OutboundSchedule, DF-P2 del H8)
        o = own["saturday"] if iso == 6 else own["weekdays"]
        if o is None:
            return []
        base = _intersect(base, tuple(o))
        if base is None:
            return []
    wins = [base]
    if schedule is not None:
        if iso not in schedule["days"]:
            return []
        wins = [w for w in (_intersect(base, (s["from"], s["to"])) for s in schedule["windows"]) if w is not None]
    return wins


def calendar_reasons(now):
    """Los motivos de N1 por calendario: SOLO la ley (Ley 2300 ∩ silencio de ContactPolicy, domingos y festivos). El horario propio es N5 (L9-15)."""
    local = to_bogota(now)
    d = local.date()
    reasons = []
    if d.isoweekday() == 7:
        reasons.append("calendar_sunday")
    if d in HOLIDAYS:
        reasons.append("calendar_holiday")
    if reasons:
        return reasons
    sec = local.hour * 3600 + local.minute * 60 + local.second
    if not any(lo * 60 <= sec < hi * 60 for lo, hi in day_windows(d)):
        return ["calendar_outside_legal_hours"]
    return []


def own_reasons(now, own):
    """N5 (L9-15): con horario propio configurado y la ley ya cumplida, calendar_outside_own_hours si el instante cae fuera de él."""
    if own is None or calendar_reasons(now):
        return []
    return [] if inside(now, own) else ["calendar_outside_own_hours"]


def inside(now, own=None, schedule=None):
    local = to_bogota(now)
    sec = local.hour * 3600 + local.minute * 60 + local.second
    return any(lo * 60 <= sec < hi * 60 for lo, hi in day_windows(local.date(), own, schedule))


def next_slot(t, own=None, schedule=None):
    """El primer instante ≥ t dentro de la ventana (N1 ∩ propio ∩ estrategia). Si t ya está dentro, t; si no, la apertura (al minuto)."""
    local = to_bogota(t)
    d = local.date()
    sec = local.hour * 3600 + local.minute * 60 + local.second
    for i in range(0, 400):
        day = d + dt.timedelta(days=i)
        for lo, hi in sorted(day_windows(day, own, schedule)):
            if i == 0:
                if sec < lo * 60:
                    return bog(day.year, day.month, day.day, lo // 60, lo % 60)
                if sec < hi * 60:
                    return t
            else:
                return bog(day.year, day.month, day.day, lo // 60, lo % 60)
    raise AssertionError("sin hueco en 400 días")


CAL = Fixture("calendar-cases.json", header(
    "calendar-cases.json",
    ["D9-7 (N1: calendar_outside_legal_hours, calendar_sunday, calendar_holiday; OutboundSchedule::evaluate y ColombianHolidays)",
     "L9-15 (el horario propio tiene su código, calendar_outside_own_hours, en N5)",
     "D9-7 (ContactBudget: «Ventana horaria» = horas de silencio de ContactPolicy ∩ Ley 2300: L–V 08:00–19:00 y S 08:00–15:00, sin domingos ni festivos)",
     "D9-6 (schedule: solo estrecha la ventana legal y la propia) y P9-17 (horario propio, solo más estricto)",
     "§13.1 #17 (defer al primer hueco permitido), §13.2 T16 y T22, §13.3 (07:59, 08:00, 18:59, 19:00, sábado 14:59 y 15:00, lunes festivos, Ley 2578, 29 de febrero, medianoche de Bogotá)"],
    [SOURCE_CONTRACT,
     {"what": "La ventana legal, los intervalos semiabiertos y el horario propio que falla cerrado",
      "url": "backend/app/Services/Telephony/OutboundSchedule.php (LEGAL, evaluate, windows) y backend/app/Services/Commercial/ContactPolicy.php:146-172 (horas de silencio 21:00–08:00)",
      "consulted": "09-11-2026, en este árbol", "how": "Mismas reglas: [abre, cierra) en minutos de Bogotá, el horario propio es la intersección y uno mal escrito deja todo fuera."},
     {"what": "Los festivos 2026–2028 (Ley 51 de 1983, móviles de Pascua y Ley 2578 de 2026, por confirmar)",
      "url": "backend/tests/fixtures/voice/holidays-co-2026-2028.json (TEST8-a) y backend/app/Services/Telephony/ColombianHolidays.php",
      "consulted": "09-11-2026, en este árbol",
      "how": "Recalculados con la regla en el generador y comparados fecha a fecha con el fixture del H8 (assert): 19 por año. La aprobación del propietario sigue pendiente (D8-15)."}],
    timezone="America/Bogota (UTC−05:00 todo el año)",
    n1Windows={"weekdays": [480, 1140], "saturday": [480, 900], "sunday": None, "holidays": None,
               "note": "Minutos de Bogotá, [abre, cierra). 07:00–07:59 es legal por la Ley 2300 pero está en las horas de silencio de ContactPolicy: fuera."},
    shapes={"ownHours": "null (sin horario propio) | 'invalid' (mal configurado: todo fuera) | {weekdays: [abre, cierra] | null, saturday: [abre, cierra] | null}",
            "schedule": "null | {days: [días ISO 1..6], windows: [{from, to}] en minutos de Bogotá}: el horario de la estrategia (D9-6), solo estrecha"},
    decisions={
        "DF-W1": "La ventana de N1 es la intersección de la Ley 2300 con las horas de silencio de ContactPolicy: L–V [08:00, 19:00) y S [08:00, 15:00); semiabierta (las 19:00:00 y las 15:00:00 ya están fuera).",
        "DF-W2": "Motivos de N1: en domingo solo calendar_sunday; en festivo calendar_holiday (y calendar_sunday si además es domingo); calendar_outside_legal_hours solo en un día hábil fuera de su franja legal.",
        "DF-W3": "El primer hueco: si el instante ya está dentro, es él mismo; si no, la apertura siguiente al minuto exacto, saltando domingos, festivos y días sin franja; con horario de la estrategia, la intersección.",
        "DF-W4": "Horario propio mal configurado ('invalid') = ninguna franja, como OutboundSchedule (falla cerrado): calendar_outside_own_hours siempre que la ley se cumpla.",
        "DF-W5": "El horario propio (P9-17) tiene su código, calendar_outside_own_hours, en N5 y no en N1 (ratificada por el lead: L9-15): la traza distingue la ley de la política propia. Se evalúa solo cuando la ley ya se cumple (si no, decide N1); el primer hueco (nextSlot) sí lo tiene en cuenta.",
    },
))

for y in (2026, 2027, 2028):
    CAL.add("holidays", "year", f"{y}: 19 festivos (con Chiquinquirá, Ley 2578)", "D9-7 (ColombianHolidays), E2-1 del H8", "ley", {"year": y},
            {"dates": [str(d) for d in holidays(y)], "count": 19})


def WIN(desc, now, inside_, reasons, *, n5=(), own=None, ref="D9-7 N1, §13.3", basis="decision-fixture", decision="DF-W1"):
    got, got5 = calendar_reasons(now), own_reasons(now, own)
    assert got == reasons and got5 == list(n5) and (not got and not got5) == inside_, (desc, got, got5)
    local = to_bogota(now)
    CAL.add("window", "instant", desc, ref, basis, {"now": now, "ownHours": own},
            {"inside": inside_, "n1": reasons, "n5": list(n5), "bogota": local.strftime("%Y-%m-%d %H:%M:%S"), "weekday": weekday_es(local.date())},
            decision=decision)


WIN("martes 07:59 → fuera (silencio de ContactPolicy aunque la Ley 2300 abra a las 07:00)", bog(2026, 11, 10, 7, 59), False,
    ["calendar_outside_legal_hours"])
WIN("martes 07:30 → fuera", bog(2026, 11, 10, 7, 30), False, ["calendar_outside_legal_hours"])
WIN("martes 08:00:00 → dentro", bog(2026, 11, 10, 8, 0), True, [])
WIN("martes 18:59:59 → dentro", bog(2026, 11, 10, 18, 59, 59), True, [])
WIN("martes 19:00:00 → fuera (semiabierto)", bog(2026, 11, 10, 19, 0), False, ["calendar_outside_legal_hours"])
WIN("lunes 07:59 → fuera (T16)", bog(2026, 11, 9, 7, 59), False, ["calendar_outside_legal_hours"], ref="T16, §13.3")
WIN("sábado 14:59:59 → dentro", bog(2026, 11, 14, 14, 59, 59), True, [])
WIN("sábado 15:00:00 → fuera (T16)", bog(2026, 11, 14, 15, 0), False, ["calendar_outside_legal_hours"], ref="T16, §13.3")
WIN("sábado 07:59 → fuera", bog(2026, 11, 14, 7, 59), False, ["calendar_outside_legal_hours"])
WIN("domingo 10:00 → solo calendar_sunday", bog(2026, 11, 15, 10, 0), False, ["calendar_sunday"], decision="DF-W2", ref="T16, #17")
WIN("domingo 23:59:59 → calendar_sunday", bog(2026, 11, 15, 23, 59, 59), False, ["calendar_sunday"], decision="DF-W2")
WIN("medianoche: el domingo 23:59 de Bogotá ya es lunes en UTC → sigue siendo domingo", "2026-11-16T04:59:00Z", False, ["calendar_sunday"],
    decision="DF-W2", ref="§13.3 (la medianoche de Bogotá)")
WIN("lunes festivo trasladado (Independencia de Cartagena, 11-11 → 16-11-2026) 10:00 → calendar_holiday", bog(2026, 11, 16, 10, 0), False,
    ["calendar_holiday"], ref="T16 (lunes trasladado), §13.3", basis="ley", decision="DF-W2")
WIN("lunes festivo trasladado (Todos los Santos, 01-11 → 02-11-2026)", bog(2026, 11, 2, 9, 0), False, ["calendar_holiday"], basis="ley", decision="DF-W2")
WIN("el 11-11-2026 (miércoles) no es festivo: se trasladó al lunes → dentro", bog(2026, 11, 11, 10, 0), True, [], basis="ley")
WIN("Ley 2578: Chiquinquirá trasladada al lunes 13-07-2026 → calendar_holiday (por confirmar)", bog(2026, 7, 13, 10, 0), False, ["calendar_holiday"],
    ref="T16 (Ley 2578), E2-1 del H8", basis="ley", decision="DF-W2")
WIN("Ley 2578: el jueves 09-07-2026 (fecha de origen) no es festivo", bog(2026, 7, 9, 10, 0), True, [], basis="ley")
WIN("festivo en sábado (01-05-2027) 10:00 → calendar_holiday", bog(2027, 5, 1, 10, 0), False, ["calendar_holiday"], basis="ley", decision="DF-W2")
WIN("Jueves Santo 25-03-2027 → calendar_holiday", bog(2027, 3, 25, 10, 0), False, ["calendar_holiday"], basis="ley", decision="DF-W2")
WIN("29-02-2028 (martes, bisiesto) 10:00 → dentro", bog(2028, 2, 29, 10, 0), True, [], ref="§13.3 (el 29 de febrero)")
WIN("viernes 31-12-2027 18:30 → dentro", bog(2027, 12, 31, 18, 30), True, [])
WIN("horario propio L–V 09:00–18:00: martes 08:30 → la ley se cumple, fuera del propio (N5)", bog(2026, 11, 10, 8, 30), False, [],
    n5=["calendar_outside_own_hours"], own={"weekdays": [540, 1080], "saturday": [540, 780]}, ref="P9-17, L9-15", basis="supuesto-lead", decision="DF-W5")
WIN("horario propio: martes 17:59 → dentro", bog(2026, 11, 10, 17, 59), True, [], own={"weekdays": [540, 1080], "saturday": [540, 780]}, ref="P9-17")
WIN("horario propio S 09:00–13:00: sábado 13:00 → fuera del propio (N5), la ley abre hasta las 15:00", bog(2026, 11, 14, 13, 0), False, [],
    n5=["calendar_outside_own_hours"], own={"weekdays": [540, 1080], "saturday": [540, 780]}, ref="P9-17, L9-15", basis="supuesto-lead", decision="DF-W5")
WIN("horario propio que intenta ampliar (06:00–21:00): no amplía, 07:30 sigue fuera por LEY (N1; N5 ni se mira)", bog(2026, 11, 10, 7, 30), False,
    ["calendar_outside_legal_hours"], own={"weekdays": [360, 1260], "saturday": [360, 1260]}, ref="P9-17 («solo más estricto»), D9-6, L9-15",
    decision="DF-W5")
WIN("horario propio sin sábado: sábado 10:00 → fuera del propio (N5)", bog(2026, 11, 14, 10, 0), False, [], n5=["calendar_outside_own_hours"],
    own={"weekdays": [540, 1080], "saturday": None}, ref="P9-17, L9-15", basis="supuesto-lead", decision="DF-W5")
WIN("horario propio mal configurado: todo fuera del propio (falla cerrado)", bog(2026, 11, 10, 10, 0), False, [], n5=["calendar_outside_own_hours"],
    own="invalid", ref="P9-17, OutboundSchedule (DF-P2 del H8), L9-15", decision="DF-W4")


def NS(desc, t, expected, *, own=None, schedule=None, ref="§13.1 #17, T22", basis="decision-fixture", decision="DF-W3"):
    got = next_slot(t, own, schedule)
    assert got == expected, (desc, got, expected)
    assert inside(got, own, schedule)
    CAL.add("nextSlot", "slot", desc, ref, basis, {"from": t, "ownHours": own, "schedule": schedule},
            {"at": expected, "bogota": to_bogota(expected).strftime("%Y-%m-%d %H:%M:%S"), "weekday": weekday_es(to_bogota(expected).date())},
            decision=decision)


NS("dentro: el mismo instante, sin redondear", bog(2026, 11, 10, 10, 15, 37), bog(2026, 11, 10, 10, 15, 37))
NS("lunes 07:59 → lunes 08:00", bog(2026, 11, 9, 7, 59), bog(2026, 11, 9, 8, 0))
NS("viernes 19:00 → sábado 08:00", bog(2026, 11, 13, 19, 0), bog(2026, 11, 14, 8, 0))
NS("sábado 15:00 → lunes 08:00 (el domingo no)", bog(2026, 11, 7, 15, 0), bog(2026, 11, 9, 8, 0))
NS("domingo 10:00 → lunes 08:00 (#17)", bog(2026, 11, 8, 10, 0), bog(2026, 11, 9, 8, 0))
NS("domingo antes de un lunes festivo (15-11-2026) → martes 17-11 08:00", bog(2026, 11, 15, 10, 0), bog(2026, 11, 17, 8, 0), basis="ley")
NS("lunes festivo 16-11-2026 10:00 → martes 08:00", bog(2026, 11, 16, 10, 0), bog(2026, 11, 17, 8, 0), basis="ley")
NS("Ley 2578: lunes 13-07-2026 → martes 14-07 08:00", bog(2026, 7, 13, 9, 0), bog(2026, 7, 14, 8, 0), basis="ley")
NS("jueves 24-12-2026 19:30 → sábado 26-12 08:00 (Navidad viernes)", bog(2026, 12, 24, 19, 30), bog(2026, 12, 26, 8, 0), basis="ley")
NS("miércoles santo 24-03-2027 20:00 → sábado 27-03 08:00 (jueves y viernes santos)", bog(2027, 3, 24, 20, 0), bog(2027, 3, 27, 8, 0), basis="ley")
NS("jueves 31-12-2026 19:00 → sábado 02-01-2027 08:00 (Año Nuevo viernes)", bog(2026, 12, 31, 19, 0), bog(2027, 1, 2, 8, 0), basis="ley")
NS("medianoche: domingo 23:30 de Bogotá (lunes 04:30Z) → lunes 08:00 de Bogotá", "2026-11-09T04:30:00Z", bog(2026, 11, 9, 8, 0),
   ref="§13.3 (la medianoche de Bogotá)")
NS("29-02-2028 20:00 → miércoles 01-03 08:00", bog(2028, 2, 29, 20, 0), bog(2028, 3, 1, 8, 0), ref="§13.3 (el 29 de febrero)")
NS("con horario propio: martes 08:30 → 09:00", bog(2026, 11, 10, 8, 30), bog(2026, 11, 10, 9, 0), own={"weekdays": [540, 1080], "saturday": [540, 780]},
   ref="P9-17")
NS("con horario propio: sábado 13:30 → martes 17-11 09:00 (el lunes 16-11 es festivo)", bog(2026, 11, 14, 13, 30), bog(2026, 11, 17, 9, 0),
   own={"weekdays": [540, 1080], "saturday": [540, 780]}, ref="P9-17")
NS("con horario de la estrategia (martes a jueves 10:00–12:00): lunes 09:00 → martes 10:00", bog(2026, 11, 9, 9, 0), bog(2026, 11, 10, 10, 0),
   schedule={"days": [2, 3, 4], "windows": [{"from": 600, "to": 720}]}, ref="D9-6 (schedule), N5")
NS("con dos franjas de la estrategia: 12:30 → 14:00", bog(2026, 11, 10, 12, 30), bog(2026, 11, 10, 14, 0),
   schedule={"days": [1, 2, 3, 4, 5], "windows": [{"from": 600, "to": 720}, {"from": 840, "to": 960}]}, ref="D9-6 (schedule), N5")
NS("franja de la estrategia que se sale de la ley (18:00–20:00): solo cuenta hasta las 19:00", bog(2026, 11, 10, 19, 0), bog(2026, 11, 11, 18, 0),
   schedule={"days": [1, 2, 3, 4, 5], "windows": [{"from": 1080, "to": 1200}]}, ref="D9-6 («solo pueden estrechar»)")
