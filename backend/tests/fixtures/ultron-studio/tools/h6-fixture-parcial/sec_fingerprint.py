# fingerprint · PhoneFingerprint::withKey() y ::of() (contrato §I4.3)
# k = HMAC-SHA256(clave = APP_KEY cruda, mensaje = 'ultron-canary-phone/1') en bruto;
# fp = HMAC-SHA256(clave = k, mensaje = E.164) en hex minúsculas.
# of(): config('app.key'); con 'base64:' se decodifica el resto; vacía → RuntimeException.


def FV(desc, app_key, e164):
    add("fingerprint", "vector", desc,
        {"appKey": app_key, "appKeyRawHex": key_bytes(app_key).hex(), "e164": e164},
        {"subkeyHex": phone_subkey(app_key).hex(), "fingerprint": phone_fp(e164, app_key)})


def FI(desc, e164):
    add("fingerprint", "invalidE164", desc,
        {"appKey": B64KEY, "appKeyRawHex": key_bytes(B64KEY).hex(), "e164": e164},
        {"throws": "InvalidArgumentException"})


def FK(desc, app_key):
    add("fingerprint", "emptyKey", desc, {"appKey": app_key, "e164": "+573000000001"},
        {"throws": "RuntimeException"})


FV("clave base64: · móvil +573000000001", B64KEY, "+573000000001")
FV("clave base64: · el móvil siguiente (+573000000002)", B64KEY, "+573000000002")
FV("clave base64: · +573000004821", B64KEY, "+573000004821")
FV("clave base64: · fijo de voz +576010000001", B64KEY, "+576010000001")
FV("clave base64: · EE. UU. +12025550101", B64KEY, "+12025550101")
FV("clave en crudo · el mismo móvil con otra clave", RAWKEY, "+573000000001")
FV("clave en crudo · fijo de voz", RAWKEY, "+576010000001")
FV("clave en crudo · 8 dígitos (mínimo de E.164)", RAWKEY, "+12345678")
FV("clave en crudo · 15 dígitos (máximo de E.164)", RAWKEY, "+123456789012345")

FI("sin +", "573000000001")
FI("con espacios: la huella no normaliza", "+57 300 000 0001")
FI("salto de línea al final ($ de PCRE sin D lo dejaría pasar)", "+573000000001\n")
FI("7 dígitos", "+1234567")
FI("16 dígitos", "+1234567890123456")
FI("un 0 tras el +", "+0573000000001")
FI("vacío", "")

FK("APP_KEY «base64:» sin nada detrás", "base64:")
FK("APP_KEY vacía", "")
