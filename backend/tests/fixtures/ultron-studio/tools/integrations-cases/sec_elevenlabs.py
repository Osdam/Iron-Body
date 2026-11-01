# elevenlabsSignature · ElevenLabsWebhookSignature::verify($rawBody, $header, $secret, $now) (contrato §I6.4)
# elevenlabsEnvelope · ElevenLabsWebhookEnvelope::type($rawBody)
import calendar

NOW = calendar.timegm((2026, 11, 1, 15, 0, 0, 0, 0, 0))
assert NOW == 1793545200
T0 = NOW - 60
SECRET = "wsec_h6fixture_webhook_000000000000000001"
OTHER = "wsec_h6fixture_webhook_000000000000000002"
BODY = ('{"type":"post_call_transcription","event_timestamp":%d,"data":{"agent_id":"agent_h6fixture_0001",'
        '"conversation_id":"conv_h6fixture_0001","status":"done"}}') % T0
BODY_U = '{"type":"post_call_transcription","data":{"agent_id":"agent_h6fixture_0001","summary":"Llamó por la promoción de año nuevo 🎉 ñandú"}}'
S0 = el_sig(str(T0), BODY, SECRET)


def G(desc, header, exp, *, body=BODY, secret=SECRET, now=NOW):
    add("elevenlabsSignature", "verify", desc, {"rawBody": body, "header": header, "secret": secret, "now": now}, exp)


def r(x):
    return {"result": x}


OK, MISS, MAL, EXP, FUT, MIS = r("ok"), r("missing_signature"), r("malformed_signature"), r("expired"), r("from_future"), r("mismatch")
BAD = "0" * 64

G("válida", f"t={T0},v0={S0}", OK)
G("v0 en mayúsculas: se compara en minúsculas", f"t={T0},v0={S0.upper()}", OK)
G("v0 antes que t", f"v0={S0},t={T0}", OK)
G("dos v0 y el segundo vale (rotación del secreto)", f"t={T0},v0={BAD},v0={S0}", OK)
G("dos v0 y ninguno vale", f"t={T0},v0={BAD},v0={'1' * 64}", MIS)
G("el mismo v0 dos veces", f"t={T0},v0={S0},v0={S0}", OK)
G("espacios alrededor de las partes y partes vacías", f" t={T0} , , v0={S0} ,", OK)
G("tabulador delante de v0: trim lo quita", f"t={T0},\tv0={S0}", OK)
G("espacio duro U+00A0 delante de v0: trim de PHP no lo quita y la clave no es v0", f"t={T0}, v0={S0}", MAL)
G("una clave desconocida se ignora", f"t={T0},v1={BAD},v0={S0}", OK)
G("una clave desconocida con '=' en el valor se ignora (se parte por el primero)", f"t={T0},v0={S0},x=a=b", OK)
G("cabecera nula", None, MISS)
G("cabecera vacía", "", MISS)
G("cabecera de solo espacios", "   ", MISS)
G("solo comas y espacios: no está vacía y no tiene t", " , , ", MAL)
G("sin t", f"v0={S0}", MAL)
G("dos t iguales", f"t={T0},t={T0},v0={S0}", MAL)
G("dos t distintos", f"t={T0},t={T0 - 1},v0={S0}", MAL)
G("t no numérico", f"t=abc,v0={S0}", MAL)
G("t de 13 dígitos", f"t=1{T0:012d},v0={S0}", MAL)
G("t negativo", f"t=-{T0},v0={S0}", MAL)
G("t vacío", f"t=,v0={S0}", MAL)
G("t con decimales", f"t={T0}.0,v0={S0}", MAL)
G("t con dígitos arábigo-índicos", "t=" + "".join(chr(0x0660 + int(c)) for c in str(T0)) + f",v0={S0}", MAL)
G("sin v0", f"t={T0}", MAL)
G("v0 de 63 caracteres", f"t={T0},v0={S0[:63]}", MAL)
G("v0 de 65 caracteres", f"t={T0},v0={S0}0", MAL)
G("v0 con un carácter que no es hexadecimal", f"t={T0},v0={S0[:63]}g", MAL)
G("un v0 bueno y otro mal formado: toda parte t o v0 tiene que tener forma", f"t={T0},v0={S0},v0=zz", MAL)
G("una parte sin '='", f"t={T0},v0={S0},basura", MAL)
G("espacios alrededor del '=': no se recortan clave ni valor", f"t = {T0},v0={S0}", MAL)
G("T en mayúscula no es t", f"T={T0},v0={S0}", MAL)
G("V0 en mayúscula no es v0", f"t={T0},V0={S0}", MAL)
_te = NOW - 1801
G("caducada: t = now − 1801", f"t={_te},v0={el_sig(str(_te), BODY, SECRET)}", EXP)
_tl = NOW - 1800
G("en el límite: t = now − 1800 todavía vale", f"t={_tl},v0={el_sig(str(_tl), BODY, SECRET)}", OK)
_tf = NOW + 301
G("del futuro: t = now + 301", f"t={_tf},v0={el_sig(str(_tf), BODY, SECRET)}", FUT)
_tfl = NOW + 300
G("en el límite del futuro: t = now + 300 todavía vale", f"t={_tfl},v0={el_sig(str(_tfl), BODY, SECRET)}", OK)
G("caducada y con la firma mala: el tiempo antes que la firma", f"t={_te},v0={BAD}", EXP)
G("del futuro y con la firma mala", f"t={_tf},v0={BAD}", FUT)
G("t = 0", f"t=0,v0={el_sig('0', BODY, SECRET)}", EXP)
_tz = "00" + str(T0)
assert len(_tz) == 12
G("t con ceros a la izquierda (12 dígitos) firmado sobre ese mismo texto", f"t={_tz},v0={el_sig(_tz, BODY, SECRET)}", OK)
G("t con ceros a la izquierda y la firma hecha sobre el entero: se firma el texto de t", f"t={_tz},v0={S0}", MIS)
G("secreto ajeno", f"t={T0},v0={el_sig(str(T0), BODY, OTHER)}", MIS)
G("cuerpo alterado en un carácter", f"t={T0},v0={S0}", MIS, body=BODY.replace('"done"', '"Done"'))
G("cuerpo vuelto a serializar con espacios", f"t={T0},v0={S0}", MIS, body=json.dumps(json.loads(BODY), indent=1))
G("un salto de línea final que no se firmó", f"t={T0},v0={S0}", MIS, body=BODY + "\n")
G("cuerpo con ñ y emoji: se firman los bytes UTF-8", f"t={T0},v0={el_sig(str(T0), BODY_U, SECRET)}", OK, body=BODY_U)
G("cuerpo vacío: se firma «t.»", f"t={T0},v0={el_sig(str(T0), '', SECRET)}", OK, body="")
G("firma calculada sin el prefijo «t.»",
  f"t={T0},v0={hmac.new(SECRET.encode(), BODY.encode(), hashlib.sha256).hexdigest()}", MIS)
G("firma calculada con ':' en vez de '.'",
  f"t={T0},v0={hmac.new(SECRET.encode(), f'{T0}:{BODY}'.encode(), hashlib.sha256).hexdigest()}", MIS)
G("secreto vacío con una cabecera válida: es un fallo de configuración", f"t={T0},v0={S0}", {"throws": "InvalidArgumentException"}, secret="")
G("secreto vacío y cabecera nula: el secreto se mira primero", None, {"throws": "InvalidArgumentException"}, secret="")


def N(desc, raw, t):
    add("elevenlabsEnvelope", "type", desc, {"rawBody": raw}, {"type": t})


N("transcripción", '{"type":"post_call_transcription","data":{"agent_id":"agent_h6fixture_0001"}}', "post_call_transcription")
N("audio", '{"type":"post_call_audio","data":{"full_audio":"AAAA"}}', "post_call_audio")
N("fallo al iniciar la llamada", '{"type":"call_initiation_failure","data":{"failure_reason":"busy"}}', "call_initiation_failure")
N("otro tipo", '{"type":"post_call_summary"}', None)
N("tipo en mayúsculas", '{"type":"POST_CALL_AUDIO"}', None)
N("tipo con un espacio al final", '{"type":"post_call_audio "}', None)
N("sin type", '{"data":{"type":"post_call_audio"}}', None)
N("type numérico", '{"type":1}', None)
N("JSON no válido", '{"type":"post_call_audio"', None)
N("una lista JSON", '["post_call_audio"]', None)
N("null", "null", None)
N("cuerpo vacío", "", None)
N("BOM delante del JSON", "﻿" + '{"type":"post_call_audio"}', None)
