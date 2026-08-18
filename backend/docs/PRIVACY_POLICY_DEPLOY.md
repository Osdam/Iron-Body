# Política de privacidad — publicación y despliegue

## Por qué existe este documento

Google Play rechazó la publicación de **Iron Body Workout**
(`com.ironbodyneiva.workout`) por la sección *Política de Privacidad* de la
política de **Datos de Usuario**, con dos motivos textuales:

> 1. «La política de privacidad no identifica claramente la aplicación, el
>    nombre del desarrollador o la entidad legal asociada a tu ficha de Google
>    Play Store.»
> 2. «La política de privacidad de tu aplicación no revela sus prácticas de
>    conservación de datos. Indica tus prácticas de conservación de datos en la
>    política o declara explícitamente en ella que no almacenas ni conservas
>    datos de usuario.»

Los dos eran ciertos. La URL declarada en Play,
`https://api.ironbodyneiva.cloud/privacy-policy.html`, servía un fichero
estático creado a mano en el servidor el 29 de junio de 2026, **fuera de git**,
que hablaba del CRM y de WhatsApp: no nombraba la app, ni el paquete, ni al
desarrollador, y no tenía una sola línea sobre conservación de datos.

Mientras tanto la app enlazaba a `/api/legal/privacy`, un documento **distinto**
y mucho más fiel. Había dos políticas vivas y Google leyó la mala.

## Arquitectura después de la corrección

Un solo origen, dos direcciones:

```
LegalController::privacy()          ← único texto, en git
        │
        ├── GET /privacy-policy.html   (routes/web.php)  ← URL CANÓNICA (Play Console)
        └── GET /api/legal/privacy     (routes/api.php)  ← compatibilidad
```

La identidad (app, paquete, desarrollador, contacto, URL canónica, fecha) vive
en `config/legal.php`, no incrustada en el HTML. `PrivacyPolicyTest` comprueba
que ambas rutas devuelven **byte a byte el mismo documento**, que aparecen el
nombre de la app, el paquete, el desarrollador y el correo de contacto, y que la
sección de conservación cita los plazos reales que ejecuta el *scheduler*.

## El paso que no se puede olvidar

nginx resuelve `try_files $uri $uri/ /index.php?$query_string`. Mientras exista
`public/privacy-policy.html` en el servidor, **gana el fichero** y la ruta nueva
no llega a ejecutarse: Google seguiría viendo el texto viejo y volvería a
rechazar por lo mismo. Hay que borrarlo.

No está en git (nunca lo estuvo), así que `git pull` no lo toca ni lo avisa.

## Despliegue

```bash
cd /ruta/al/backend

# 0) Copia del fichero viejo, por si alguien quiere consultarlo después.
cp public/privacy-policy.html /root/iron-body-backups/privacy-policy.html.old-$(date +%Y%m%d-%H%M%S) 2>/dev/null || true

# 1) Retirar el estático que tapa la ruta. ESTE es el paso crítico.
rm -f public/privacy-policy.html

# 2) Código.
git pull --ff-only

# 3) Sin migraciones: este cambio no toca la base de datos.
#    (Si el despliegue arrastra otros commits, revisar con `php artisan migrate --pretend`.)

# 4) Recachear config y rutas — obligatorio: se añadió config/legal.php y una
#    ruta web nueva. Sin esto, la ruta canónica devuelve 404 en producción.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

### Servidor

Producción: `root@2.25.167.216` (hostname `srv1728633`). El backend vive en
`/var/www/api/backend` y nginx sirve `/var/www/api/backend/public`
(`/etc/nginx/sites-enabled/api`). El `104.131.124.203` que aparece en documentos
antiguos está **obsoleto y no responde**.

### Nombre del desarrollador

Son **dos identidades distintas** y el documento las imprime por separado:

| `config/legal.php` | valor | por qué |
|---|---|---|
| `developer_name` | `CardenCode` | El «Desarrollador» que muestra la ficha de Play (cuenta personal). Google compara este nombre; si no lo encuentra, rechaza. |
| `controller_name` | `IRONBODY — Fredy Alberto Pajoy Medina` | El responsable del tratamiento ante la SIC (Ley 1581 de 2012). Es a quien se dirigen las solicitudes de privacidad. |

Fundirlos incumple uno de los dos requisitos, siempre. Si algún día cambian, se
ajustan por entorno sin tocar código:

```bash
echo 'LEGAL_DEVELOPER_NAME="<nombre exacto de la ficha de Play>"' >> .env
php artisan config:cache
```

## Verificación posterior

```bash
curl -sSI https://api.ironbodyneiva.cloud/privacy-policy.html   # 200 · text/html
curl -sS  https://api.ironbodyneiva.cloud/privacy-policy.html | grep -c "com.ironbodyneiva.workout"
curl -sS  https://api.ironbodyneiva.cloud/privacy-policy.html | grep -c "Conservación de los datos"

# Las dos direcciones deben servir el mismo documento:
diff <(curl -sS https://api.ironbodyneiva.cloud/privacy-policy.html) \
     <(curl -sS https://api.ironbodyneiva.cloud/api/legal/privacy) && echo "IDÉNTICAS"
```

Si el `Server:` responde con `Last-Modified` y `ETag`, sigue sirviéndose el
fichero estático: el `rm` del paso 1 no llegó a ejecutarse. La respuesta de
Laravel no lleva esas cabeceras.

## Reversión

El fichero viejo está en la copia del paso 0. Restaurarlo devuelve el estado
anterior de inmediato (y el rechazo con él). La corrección de código es aditiva:
no borra rutas ni cambia contratos existentes.
