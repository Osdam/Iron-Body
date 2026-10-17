# Transición de Google Calendar a la Agenda de valoraciones del CRM

Hoy las valoraciones físicas se agendan en Google Calendar. El CRM no tiene integración con Google: no
hay credenciales ni código que lo lea o lo escriba. Esta guía mueve la operación al CRM **sin borrar ni
modificar nada en Google** y sin escribir citas reales hasta que una persona apruebe el informe de la
importación.

## 1. Qué hay que averiguar antes (lo hace quien administra el calendario)

| Pregunta | Por qué importa |
|---|---|
| ¿Un calendario por entrenador o uno compartido? | Hay que saber a qué entrenador pertenece cada evento. |
| ¿Cómo se escribe el socio en el evento (nombre, documento, teléfono)? | La importación necesita el **documento** para no confundir socios. |
| ¿Hay citas futuras? ¿Cuántas? | Solo las futuras tienen que pasar al CRM. Las pasadas pueden quedarse en Google como histórico. |
| ¿Hay eventos recurrentes? | Una valoración no debería repetirse. Si las hay, se revisan a mano. |
| ¿Hay duplicados (el mismo socio dos veces)? | La importación los detecta, pero conviene limpiarlos en origen. |

## 2. Fecha de corte recomendada

1. **Día C − 7:**
   - Configurar en el CRM la disponibilidad de cada entrenador (Entrenadores › Calendario de citas ›
     Disponibilidad) y los ajustes (duración, márgenes).
   - Permisos por rol en Configuración › Usuarios y roles › «Agenda de valoraciones».
2. **Día C − 1, al cierre:** exportar de Google **solo las citas futuras** al CSV del §3 y ejecutar la
   simulación (§4).
3. **Día C:**
   - Revisar el informe.
   - Aplicar la importación (§4), con aprobación del responsable.
   - **Desde ese día, las citas nuevas se agendan solo en el CRM.**
4. **Día C a C + 30:** Google queda en solo lectura, como consulta. No se borra nada.

Mientras convivan los dos sistemas, **no se agenda en los dos**: la regla es «a partir del día C,
solo el CRM».

## 3. Formato del CSV

Codificación UTF-8, separador coma y cabecera:

```
member_document,trainer_document,date,time,duration_minutes,type,notes
1075123456,1075999888,2026-10-24,09:00,60,initial,Primera valoración
```

| Columna | Valor |
|---|---|
| `member_document` | Documento del socio tal como está en el CRM. |
| `trainer_document` | Documento del entrenador, o su nombre completo exacto si no hay documento. |
| `date` | `AAAA-MM-DD`. |
| `time` | `HH:MM` en **hora de Bogotá**, 24 h. |
| `duration_minutes` | Vacío = la duración por defecto de los ajustes. |
| `type` | `initial`, `follow_up` u `other`. Vacío = `initial`. |
| `notes` | Opcional. **Solo datos administrativos**: nada de diagnósticos ni medidas. |

## 4. Importar (en el servidor, por una persona autorizada)

```bash
# Simulación: no escribe nada. Informe por fila.
php artisan valuations:import-csv /ruta/valoraciones.csv

# Solo tras revisar y aprobar el informe:
php artisan valuations:import-csv /ruta/valoraciones.csv --apply
```

**El informe clasifica cada fila como:**
- válida;
- socio no encontrado;
- entrenador no encontrado o ambiguo;
- conflicto (fuera de la disponibilidad, bloqueo, clase u otra cita);
- duplicado (mismo socio, entrenador y hora).

**`--apply`:**
- Escribe en una sola transacción las filas válidas, con origen `import`, y deja la traza en el
  historial de cada cita.
- No envía avisos al socio por las citas importadas, para no repetir lo que ya se le dijo por Google.
- Los recordatorios de las citas futuras siguen las reglas de los ajustes. Solo salen si el canal de
  la app está activado.

Las filas rechazadas se corrigen en el CSV y se vuelve a simular. Una fila ya importada aparece como
**duplicado** y no se escribe dos veces.

## 5. Qué NO se hace

- No se borra ni se modifica ningún evento ni calendario de Google.
- No hay sincronización bidireccional ni permanente.
- No se importan citas pasadas, salvo que se pida expresamente.
