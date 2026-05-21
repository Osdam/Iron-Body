# Registro de miembros Iron Body

Base local:

```text
http://127.0.0.1:8000/api
```

Base con ngrok:

```text
https://xxxxx.ngrok-free.app/api
```

## 1. Crear miembro

`POST /api/members/register`

Headers:

```text
Accept: application/json
Content-Type: application/json
Authorization: Bearer token-opcional
```

Body:

```json
{
  "full_name": "Maria Perez",
  "email": "maria@example.com",
  "document_number": "1000000001",
  "phone": "3001234567",
  "gender": "female",
  "goal": "strength",
  "training_level": "beginner",
  "injuries": "Ninguna",
  "birth_date": "1998-03-15",
  "is_minor": false
}
```

Respuesta:

```json
{
  "ok": true,
  "member_id": 123,
  "member_uuid": "uuid-publico",
  "access_hash": "hash-estable",
  "message": "Miembro creado correctamente.",
  "status": "pending_registration",
  "registration_status": "pending_registration"
}
```

El `document_number` se normaliza antes de validar y guardar: `trim`, sin espacios, puntos ni guiones. Si llega el mismo documento y el registro esta `pending_registration`, `incomplete` o `failed`, el endpoint es idempotente y devuelve el miembro existente:

```json
{
  "ok": true,
  "member_id": 123,
  "member_uuid": "uuid-publico",
  "access_hash": "hash-estable",
  "message": "Ya existe un registro pendiente o incompleto con este documento. Continua el registro con este member_id.",
  "status": "duplicate_document",
  "registration_status": "pending_registration"
}
```

Si el miembro ya esta `active`, responde `409`:

```json
{
  "ok": false,
  "message": "Ya existe un miembro activo con este documento.",
  "status": "duplicate_document",
  "member_id": 123,
  "member_uuid": "uuid-publico",
  "registration_status": "active"
}
```

## 2. Documento de identidad

`POST /api/members/{member}/identity`

`{member}` puede ser `member_id` o `member_uuid`.

Body `multipart/form-data`:

```text
document_type=CC
document_number=1000000001
birth_date=1998-03-15
ocr_full_name=Maria Perez
ocr_confidence=98.40
identity_status=verified
front=@/ruta/frente.jpg
back=@/ruta/reverso.jpg
```

## 3. Consentimiento legal

`POST /api/members/{member}/legal-consent`

Body:

```json
{
  "accepted_at": "2026-05-18T10:30:00-05:00",
  "contract_version": "2026-05-v1",
  "terms_and_conditions": true,
  "data_processing": true,
  "truthfulness": true,
  "service_contract": true,
  "physical_risk_waiver": true,
  "guardian_authorization": false
}
```

Para menores de edad:

```json
{
  "accepted_at": "2026-05-18T10:30:00-05:00",
  "contract_version": "2026-05-v1",
  "terms_and_conditions": true,
  "data_processing": true,
  "truthfulness": true,
  "service_contract": true,
  "physical_risk_waiver": true,
  "guardian_authorization": true,
  "guardian_full_name": "Carlos Perez",
  "guardian_document_number": "80000001",
  "guardian_phone": "3007654321",
  "guardian_email": "carlos@example.com",
  "guardian_relationship": "Padre",
  "guardian_accepts_responsibility": true
}
```

Tambien se acepta el acudiente anidado como `guardian`.

## 4. Firma

`POST /api/members/{member}/signature`

Body `multipart/form-data`:

```text
kind=drawn
signature=@/ruta/firma.png
```

Valores de `kind`: `drawn`, `uploadedImage`, `uploadedPdf`.

## 5. Biometria facial

`POST /api/members/{member}/biometric`

Body `multipart/form-data`:

```text
captured_at=2026-05-18T10:35:00-05:00
bytes_length=245678
face=@/ruta/rostro.jpg
```

Al guardar biometria facial el registro pasa a `active`.

## Registros incompletos del CRM

`GET /api/members/incomplete`

Devuelve los registros creados desde la app que aun no estan activos:

```json
{
  "data": [
    {
      "id": 123,
      "member_id": 123,
      "member_uuid": "uuid-publico",
      "name": "Maria Perez",
      "email": "maria@example.com",
      "document": "1000000001",
      "phone": "3001234567",
      "status": "pending_registration",
      "registration_status": "pending_registration",
      "created_at": "2026-05-18T10:30:00.000000Z"
    }
  ]
}
```

## ngrok

1. Levantar Laravel:

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

2. Exponer el puerto:

```bash
ngrok http 8000
```

3. Ejecutar Flutter apuntando al tunel:

```bash
flutter run --dart-define=BACKEND_BASE_URL=https://xxxxx.ngrok-free.app
```

La app debe concatenar `/api` y luego usar los endpoints anteriores.

## Token opcional

Por defecto `MEMBER_REGISTRATION_TOKEN` queda vacio y los endpoints de registro estan abiertos para pruebas. Para protegerlos con Bearer token simple:

```env
MEMBER_REGISTRATION_TOKEN=un-token-largo-y-secreto
```

Luego enviar en Flutter/Postman:

```text
Authorization: Bearer un-token-largo-y-secreto
```

## Seguridad

Los archivos se guardan en el disco `local`, configurado en este proyecto como `storage/app/private`. Las respuestas no devuelven rutas de archivos ni imagenes crudas.

# Planes de membresia para Flutter

`GET /api/membership-plans`

Devuelve solo planes activos creados en el CRM, ordenados por prioridad (`sort_order`) y precio.

Respuesta:

```json
{
  "ok": true,
  "data": [
    {
      "id": 1,
      "name": "Mensual",
      "period": "1 mes",
      "months": 1,
      "price": 120000,
      "original_price": null,
      "benefits": [
        "Acceso ilimitado al gimnasio",
        "Evaluacion fisica inicial"
      ],
      "is_recommended": false,
      "badge": null,
      "status": "active"
    }
  ]
}
```

Si no hay planes activos:

```json
{
  "ok": true,
  "data": []
}
```

Detalle opcional:

```text
GET /api/membership-plans/{id}
```

Los precios vienen de la tabla `plans.price` y deben administrarse en COP desde el CRM. `benefits` puede guardarse como texto separado por comas/lineas o como JSON; la API siempre lo devuelve como `array<string>`.

# Pagos ePayco asociados a miembros

Los endpoints in-app de ePayco aceptan `member_id` para el flujo Flutter y conservan `user_id` para compatibilidad con el CRM:

```text
POST /api/payments/epayco/pay-card
POST /api/payments/epayco/pay-pse
POST /api/payments/epayco/pay-nequi
POST /api/payments/epayco/pay-daviplata
```

Payload base:

```json
{
  "amount": 80000,
  "currency": "COP",
  "description": "Membresia Mensual · Iron Body",
  "idempotency_key": "uuid-del-intento",
  "plan_id": 4,
  "member_id": 123,
  "customer": {
    "name": "Maria Perez",
    "email": "maria@example.com",
    "phone": "3001234567",
    "doc_number": "1000000001",
    "doc_type": "CC",
    "city": "Neiva",
    "address": "Neiva, Huila",
    "country": "CO"
  }
}
```

Para tarjeta agregar:

```json
{
  "card": {
    "number": "4111111111111111",
    "exp_month": "12",
    "exp_year": "2028",
    "cvc": "123"
  },
  "dues": 1
}
```

Respuesta publica:

```json
{
  "ok": true,
  "transaction_id": "123",
  "reference": "IRON-20260518-ABC123-45678",
  "status": "approved",
  "member_id": 123,
  "user_id": 5,
  "plan_id": 4
}
```

Cuando el pago queda `approved`, el backend crea el registro en `payments`, extiende la membresia del usuario CRM enlazado al `member_id`, marca el usuario como `active` y guarda el plan comprado.

# Ranking de entrenadores

Flutter consume el ranking publico:

```text
GET /api/trainers
GET /api/trainers?limit=20&page=1
GET /api/trainers?specialty=Hipertrofia
GET /api/trainers?search=Carlos
```

Respuesta:

```json
{
  "ok": true,
  "data": [
    {
      "id": 1,
      "full_name": "Carlos Perez",
      "specialty": "Hipertrofia",
      "bio": "Entrenador especializado en fuerza e hipertrofia.",
      "photo_url": null,
      "rating": 4.8,
      "reviews_count": 32,
      "years_experience": 5,
      "certifications": ["Entrenamiento funcional"],
      "is_active": true,
      "rank_position": 1
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

Detalle:

```text
GET /api/trainers/{id}
```

Incluye `reviews`.

Calificar o actualizar calificacion:

```text
POST /api/trainers/{id}/reviews
```

```json
{
  "member_id": 5,
  "rating": 5,
  "comment": "Excelente entrenador."
}
```

Un miembro solo tiene una reseña por entrenador: si ya existe, se actualiza. El CRM sigue usando `GET /api/trainers?admin=1` para administrar todos los entrenadores, incluidos inactivos.
