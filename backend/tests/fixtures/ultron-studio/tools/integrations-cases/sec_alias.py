# alias · StudioCanaryPhone::aliasError() (contrato §I4.3)
# null si vale; 'invalid_alias' si no tiene 3–40 CARACTERES de
# ^[A-Za-z0-9ÁÉÍÓÚÜÑáéíóúüñ .\-_()]+$, si tiene \d{4,} o si tiene '@'. Sin recorte.
INV = "invalid_alias"


def A(desc, alias, err):
    add("alias", "aliasError", desc, {"alias": alias}, {"error": err})


A40 = "Movil de pruebas de la recepcion norte 1"
A40T = "Móvil de pruebas de la recepción norte 1"
A40M = "ÁÉÍÓÚÜÑáéíóúüñ ÁÉÍÓÚÜÑáéíóúüñ ÁÉÍÓÚÜÑáéí"
assert len(A40) == 40 and len(A40T) == 40 and len(A40T.encode()) == 42
assert len(A40M) == 40 and len(A40M.encode()) == 78

A("el ejemplo del contrato", "Móvil de pruebas 1", None)
A("3 caracteres: el mínimo", "abc", None)
A("2 caracteres", "ab", INV)
A("vacío", "", INV)
A("40 caracteres ASCII: el máximo", A40, None)
A("41 caracteres ASCII", A40 + ".", INV)
A("40 caracteres con tildes (42 bytes): se cuentan caracteres, no bytes", A40T, None)
A("41 caracteres con tildes", A40T + ".", INV)
A("40 caracteres casi todos de dos bytes (78 bytes)", A40M, None)
A("ñu: 2 caracteres y 3 bytes", "ñu", INV)
A("ñúü: 3 caracteres y 6 bytes", "ñúü", None)
A("una serie de 4 dígitos", "Móvil 1234", INV)
A("una serie de 3 dígitos", "Móvil 123", None)
A("dígitos en grupos de 2: ninguna serie llega a 4 (literal)", "Caja 12 34 56", None)
A("un teléfono en grupos de 2 no tiene serie de 4 (literal; ver O-1 del README)", "30 00 00 00 01", None)
A("un teléfono con guiones sí la tiene (0001)", "300-000-0001", INV)
A("una serie de 4 entre letras", "Caja A1234B", INV)
A("solo cuatro ceros", "0000", INV)
A("arroba", "pruebas@ironbody", INV)
A("barra", "Móvil/2", INV)
A("coma", "Móvil, 2", INV)
A("almohadilla", "Móvil #2", INV)
A("apóstrofo", "D'Artagnan", INV)
A("tabulador", "Móvil\t2", INV)
A("salto de línea al final ($ de PCRE sin D lo dejaría pasar)", "Móvil de pruebas\n", INV)
A("espacio duro U+00A0", "Móvil de pruebas", INV)
A("emoji", "Móvil \U0001F4F1", INV)
A("ç no está en la lista", "Garçon", INV)
A("Å no está en la lista", "Ålesund", INV)
A("tilde combinada (NFD, U+0301) no está en la lista", "Móvil de pruebas", INV)
A("todos los signos admitidos a la vez", "Recepción (sede 2) - turno_mañana.", None)
A("mayúsculas con tilde y diéresis", "MÓVIL ÜÑ", None)
A("sin recorte: un espacio delante cuenta como carácter (3)", " ab", None)
A("dígitos arábigo-índicos: no están en la lista", "Caja ١٢", INV)
A("punto, guion y guion bajo", "a.b-c_d", None)
A("solo signos admitidos, sin letras (literal)", "(.)", None)
