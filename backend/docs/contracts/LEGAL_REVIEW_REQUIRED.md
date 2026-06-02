# Contratos, consentimiento y firma electrónica — Nota técnica y revisión legal

> **AVISO IMPORTANTE:** Este módulo implementa **buenas prácticas técnicas** de
> consentimiento informado, trazabilidad y firma electrónica. **No constituye
> asesoría legal** ni garantiza por sí solo el cumplimiento normativo. El flujo,
> los textos y las cláusulas deben ser **revisados y aprobados por el abogado /
> contador del gimnasio** antes de operar en producción.

## 1. Qué hace (y qué NO hace) este módulo

- **Usa los documentos oficiales tal cual** como fondo PDF y estampa únicamente
  los datos del usuario y la firma por coordenadas controladas
  (`config/contracts.php`). No reescribe cláusulas, no cambia logos, no rediseña.
- Anexa al final una **"Constancia de firma electrónica y auditoría"** sin
  alterar las páginas originales.
- Si falta una plantilla oficial, **falla con error claro** (no genera un PDF
  falso ni "parecido").

## 2. Instalación de plantillas (NO se versionan en git)

Los PDFs fuente viven en disco **privado** y **no se commitean** (contienen la
identidad legal del titular del negocio). En el despliegue, copiarlos a:

```
storage/app/private/contract_templates/source/
  ├── basic_registration.pdf      (INSCRIPCION — convertido 1 vez de DOCX a PDF)
  ├── workout_registration.pdf    (FORMATO DE INSCRIPCION IRONBODY WORKOUT)
  └── minor_release.pdf            (LIBERACIÓN DE RESPONSABILIDADES MENOR)
```

Conversión del DOCX a PDF (una sola vez, fiel, con LibreOffice headless):

```bash
libreoffice --headless --convert-to pdf --outdir <destino> "INSCRIPCION (1).docx"
# renombrar el resultado a basic_registration.pdf
```

Registrar/verificar (calcula y guarda el checksum SHA-256 de cada fuente):

```bash
php artisan contracts:install-templates
```

> Si una plantilla oficial cambia, **subir su `version`** en `config/contracts.php`
> y volver a ejecutar el comando. Nunca se reescribe el contenido legal.

## 3. Privacidad / Habeas Data (Ley 1581 de 2012, Decreto 1377, SIC) — a nivel técnico

Implementado técnicamente; **pendiente de validación legal**:

- **Autorización previa, expresa e informada:** checkboxes explícitos con texto
  exacto, marca de tiempo, versión de plantilla, versión de app, locale, IP y
  user-agent → `acceptance_snapshot` (inmutable).
- **Finalidad del tratamiento:** gestión de inscripción, prestación del servicio,
  seguimiento físico/deportivo, comunicación operativa, seguridad del usuario y
  facturación. Marketing/uso de imagen **solo si el usuario lo autoriza** (el
  checkbox de imagen es **opcional** y se refleja tal cual en el snapshot).
- **Datos sensibles (salud/lesiones):** se guardan en `medical_snapshot`, se
  estampan solo en los campos que el documento oficial ya exige, **no se usan
  para marketing** y **no se vuelcan a logs**.
- **Verificabilidad / auditoría:** `contract_audit_logs` registra creación,
  aceptación, firma, generación de PDF, descargas y anulación, con IP/UA.
- **Documento descargable:** el titular descarga su PDF firmado desde la app por
  endpoint **autenticado** (nunca URL pública).
- **Política de privacidad accesible:** `PRIVACY_POLICY_URL`, `TERMS_URL`,
  `SUPPORT_CONTACT` (configurables por entorno; servidos en el endpoint de
  estado para mostrarse antes de firmar).
- **Menores de edad:** se exige `minor_release` firmado por el acudiente; los
  datos del menor se tratan bajo responsabilidad del acudiente.

### Pendiente de revisión legal (lista para el abogado)
1. Texto y suficiencia de la **autorización de tratamiento de datos** y de datos
   sensibles de salud.
2. Validez de la **firma electrónica simple** para estos documentos (Ley 527 de
   1999) y necesidad —o no— de firma/estampado adicional.
3. Política de **retención y supresión** de datos y de PDFs firmados.
4. Texto y URL de la **política de privacidad** y **términos** definitivos.
5. Manejo del **uso de imagen** (opcional vs. obligatorio) según la operación.
6. Procedimiento de **anulación (void)** y sus efectos legales/comerciales.

## 4. App Store / Apple Privacy (App Privacy "Nutrition Label")

La app permite ver términos/privacidad **antes** de firmar y descargar el PDF.
En App Store Connect debe declararse correctamente (no "Data Not Collected"):

- **Contact Info:** nombre, email, teléfono, dirección.
- **Identifiers:** documento de identidad, IDs internos.
- **Health & Fitness:** observaciones médicas, lesiones, datos de progreso.
- **User Content:** firma; fotos/videos solo si el usuario autoriza imagen.
- **Usage Data / Diagnostics:** según telemetría real.
- **Purchases / Financial:** pagos (gestionados por la pasarela ePayco; el backend
  no almacena números de tarjeta).

## 5. Seguridad técnica aplicada

- Firma almacenada como **archivo PNG en disco privado** (no base64 en BD).
- PDF firmado en disco privado + **checksum SHA-256** en BD.
- **No se almacenan** datos biométricos / Face ID, contraseñas, tokens ni números
  de tarjeta en el contrato.
- Contratos **firmados/anulados son inmutables** (no se re-firman ni editan).
- Endpoints de descarga **autenticados** + auditoría por descarga.

> **Nota sobre endpoints admin:** los endpoints `admin/contracts/*` siguen el
> patrón del resto del CRM del proyecto. Deben quedar detrás de la capa de
> autenticación/segmentación de red del CRM; revisar antes de exponer a Internet.
