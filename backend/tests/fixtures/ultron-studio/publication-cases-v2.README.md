# `publication-cases-v2.json`: la declaración `ultron-engine-config/2` (Enmienda M-1)

**Procedencia.** Lo genera el tester con `tools/derivar-publication-cases-v2.cjs`, **sin leer `StudioPublicationDeclaration.php`**, desde los contratos:

- **H5:**
  - §A4.1 (firma) y §A4.2 (entrada);
  - Q-1 (subclave) y Q-4 (`previewHash`);
  - M1-5 y M1-12 (Enmienda M-1).
- **Este mismo directorio:** `publication-cases.README.md` (Enmienda 1): E-1 (bytes), E-2 (`issuedAt`) y S-1 a S-4 (firma).
- **Contrato F §307:** `StudioContentHasher` = JSON canónico (claves ordenadas, listas en su orden, sin escapar `/` ni Unicode, sin decimales) + sha256.
- **Plan de motores §B.4:**
  - `/2` = la entrada de `/1` con `governed` y `prompts` exactos y tipados en cada parte no nula;
  - «Firma, huella, decodificación estricta y tope de 16 KB: los de `/1`»;
  - Legacy siempre en `/1`;
  - con la llave, los cuatro gobernados salen de `no_effect`.
- **Huellas de paquete:** `tests/fixtures/ultron-prompts/compile-cases.json` (la derivación independiente del compilador).

**Antes de generar, el guion se comprueba a sí mismo:**

1. **Los contratos dicen lo que implementa.** Cita literalmente la fórmula de la firma, Q-1, Q-4, la forma de `governed` y de `prompts`, R10 y la clase `prompt`. Si un contrato cambia ese texto, el guion falla.
2. **Reproduce el fixture congelado del H5.** Con su canonicalizador, su `issuedAt` y su firma, saca **los 11 casos `effectiveJson` y las 5 firmas `/1`** de `publication-cases.json`. Si no los reprodujera, nada de lo demás valdría.

```bash
node backend/tests/fixtures/ultron-studio/tools/derivar-publication-cases-v2.cjs              # regenera
node backend/tests/fixtures/ultron-studio/tools/derivar-publication-cases-v2.cjs --comprobar  # ¿al día?
```

Lo usa `tests/Unit/UltronStudio/StudioPublicationV2ParityTest.php`, que exige Node en el PATH para el `--comprobar`. **No toca `publication-cases.json` ni su prueba.**

## Secciones

- **`effectiveJson`** (9). Lo que SÍ se escribe, con `expected.effectiveJson`, `effectiveHash`, `signature` (la del motor, con la clave de prueba de S-1) y `signatureWithOwnContractPrefix` (informativo: la firma que saldría con el prefijo `/2`, que NO es la buena).
  - **En `/2` (7):**
    - canaria sola (el cambio de aceptación G8);
    - canaria + resto (dos paquetes);
    - resto arrastrado (la línea base compilada desde una versión `/1`, con huella Legacy, M1-12);
    - promover `{B, B}`;
    - revertir `{R, R}` con la línea base;
    - `{null, X}` (E-5);
    - `issuedAt` de noche en Bogotá con fracciones.
  - **En `/1` (2):** Legacy `{null, null}` y una parte `/1` con la llave apagada.
  - Las partes de entrada llevan las claves desordenadas a propósito: el orden lo pone la canonicalización.
- **`invalid`** (29). Lo que NO se escribe ni se firma (`InvalidArgumentException`, y el firmante no llega a llamarse):
  - Legacy en `/2`;
  - un contrato desconocido;
  - partes de un contrato en una entrada del otro;
  - `governed` y `prompts` ausentes, con claves de más o de menos, con otra grafía, con tipos equivocados, nulos o como lista;
  - `bundleHash` de 63 caracteres, no hexadecimal o nulo;
  - el resto roto con la canaria bien.
- **`previewHash`** (12). Q-4 **sin paquete**, sobre las clasificaciones de los 12 primeros casos `classify` del H5, con cuatro estados de la puerta del laboratorio.
- **`classifyWithPrompts`** (34). Para cada caso `classify` del H5 (las entradas se toman de allí, por nombre), lo esperado con la llave de prompts:
  - `noEffect` sin los cuatro gobernados;
  - `mustMatch`, `matches`, `mismatches` y `engineReadingUnavailable` iguales;
  - `governedOutOfNoEffect`: los que salen de `noEffect`, que tienen que seguir en la clasificación.

## Lecturas del contrato que fija este fichero

1. **La firma de una fila `/2` lleva el prefijo `ultron-engine-config/1\n`**. Es la lectura literal de «Firma … los de `/1`» (§B.4) y de A4.1, que nombra el prefijo. Es también lo que acordaron el motor y el Studio (informe de B-M §2.4): una fila `/2` firmada con el prefijo `/2` es `signature_invalid`. `signatureWithOwnContractPrefix` deja escrita la otra lectura para que se vea que no es la buena.
2. **Solo la FORMA de `governed` y `prompts` se exige al escribir**: claves exactas, tipos y `bundleHash` de 64 caracteres hexadecimales. Que el hexadecimal tenga que ir en minúscula no lo fija el plan (sí la S-4 para la firma), así que no hay caso con mayúsculas. Que los VALORES compilen (un tono del catálogo, `base` y `compiler` de este código, la huella del paquete de esos valores) lo comprueban otras capas: la puerta `prompts_compile_failed` al publicar y la oferta de `decide`, que cae a Legacy. Aquí no se afirma ni que el escritor los rechace ni que los acepte.
3. **`previewHash` CON paquete no tiene fórmula en ningún contrato.** Solo consta que incluye la huella del paquete y la de la parte arrastrada (M1-12; DTOs de B-S). Por eso aquí solo va Q-4 sin paquete, byte a byte. Con paquete se prueba por relación, en `tests/Feature/UltronStudio/UltronStudioPublicationV2Test.php`:
   - cambia frente a la de la llave apagada con la misma clasificación;
   - cambia con la parte arrastrada;
   - no cambia con la llave apagada (Q-4);
   - es estable;
   - publicar con la vieja da `preview_stale`.

   Si el propietario quiere congelarla byte a byte, primero tiene que escribirse en el contrato.
