# Documentación de Módulos del CRM Administrativo
## Iron Body - Plataforma de Gestión de Gimnasio

**Fecha de Documento:** 29 de Abril de 2026  
**Versión:** 1.0  
**Objetivo:** Estructura completa de módulos para levantamiento de requerimientos

---

## Índice

1. [Módulo de Gestión de Usuarios](#1-módulo-de-gestión-de-usuarios)
2. [Módulo de Planes, Membresías y Pagos](#2-módulo-de-planes-membresías-y-pagos)
3. [Módulo de Rutinas, Ejercicios y Progreso](#3-módulo-de-rutinas-ejercicios-y-progreso)
4. [Módulo de Gestión de Clases y Reservas](#4-módulo-de-gestión-de-clases-y-reservas)
5. [Módulo de Entrenadores](#5-módulo-de-entrenadores)
6. [Módulo de Marketing, Retención y Comunicación](#6-módulo-de-marketing-retención-y-comunicación)
7. [Módulo de Reportes y Analítica](#7-módulo-de-reportes-y-analítica)

---

## 1. Módulo de Gestión de Usuarios

### Descripción General
Este módulo centraliza la administración de todos los usuarios registrados en la aplicación móvil, permitiendo un control completo de perfiles, estados, membresías y actividades.

### Funciones Principales

#### 1.1 Gestión de Perfiles
- **Crear usuarios:** Registro de nuevos miembros con información básica y documentación.
- **Ver perfil completo:** Acceso a toda la información del usuario en una vista detallada.
- **Editar información:** Actualizar datos personales, contacto y preferencias.
- **Descargar datos:** Exportar información del usuario en formato PDF o Excel.

#### 1.2 Control de Estado y Acceso
- **Activar/Desactivar usuarios:** Gestionar acceso a la plataforma.
- **Bloquear usuarios:** Restricción temporal o permanente por incumplimiento.
- **Ver historial de acceso:** Registro de últimas sesiones y actividades.
- **Cambiar estado de membresía:** De activo a inactivo, vencido, bloqueado o nuevo.

#### 1.3 Consultas y Reportes de Usuario
- **Visualizar membresía en tiempo real:** Estado actual y fecha de vencimiento.
- **Historial de pagos:** Todos los pagos realizados con fechas y montos.
- **Rutinas asignadas:** Lista de rutinas vigentes y completadas.
- **Asistencia a clases:** Registro de todas las clases a las que asistió.
- **Progreso físico:** Medidas corporales, peso, IMC, porcentaje graso.

#### 1.4 Filtros y Búsqueda Avanzada
- Filtrar por estado: Activo, Inactivo, Bloqueado, Vencido, Nuevo.
- Filtrar por plan contratado.
- Filtrar por sede.
- Filtrar por rango de fechas de afiliación.
- Búsqueda por nombre, email, teléfono o documento.

#### 1.5 Asociaciones y Asignaciones
- **Asociar a sede:** Asignar usuarios a ubicaciones específicas.
- **Asignar plan:** Vincular plan de membresía.
- **Asignar entrenador personal:** Designar responsable del usuario.
- **Asignar rutina:** Determinar programa de entrenamiento.

### Información que Debe Manejar

#### Datos Personales
- Nombre completo
- Apellidos
- Documento/Cédula de identificación
- Correo electrónico
- Teléfono principal
- Teléfono secundario (opcional)
- Fecha de nacimiento
- Género
- Dirección física
- Ciudad y país

#### Datos de Membresía
- Sede asignada
- Plan contratado
- Estado de membresía (activo, inactivo, vencido, bloqueado)
- Fecha de inicio de membresía
- Fecha de vencimiento
- Renovaciones automáticas (sí/no)

#### Datos de Seguimiento
- Entrenador personal asignado
- Rutina actual asignada
- Historial de rutinas anteriores
- Última fecha de acceso
- Fecha de último pago
- Número total de accesos

### Submódulos

#### 1.5.1 Submódulo de Importación/Exportación
- Importar usuarios en lote desde CSV/Excel
- Exportar listado de usuarios con filtros
- Sincronización con sistemas externos

#### 1.5.2 Submódulo de Auditoría
- Registrar cambios en perfiles
- Quién realizó cambios y cuándo
- Historial completo de modificaciones

---

## 2. Módulo de Planes, Membresías y Pagos

### Descripción General
Este módulo gestiona la estructura comercial del gimnasio, permitiendo crear y administrar planes, procesar pagos, y controlar los ciclos de vida de las membresías.

### Funciones Principales

#### 2.1 Gestión de Planes
- **Crear planes:** Definir nuevas opciones de membresía.
- **Editar planes:** Modificar características sin afectar usuarios actuales.
- **Desactivar planes:** Retirar planes del mercado sin eliminar históricos.
- **Duplicar planes:** Crear variantes basadas en planes existentes.
- **Definir restricciones:** Acceso por nivel, duración, beneficios.

#### 2.2 Configuración de Beneficios y Restricciones
- **Número de clases permitidas:** Ilimitado o límite específico.
- **Acceso a sedes:** Una o múltiples sedes.
- **Acceso a clases específicas:** Por tipo de clase (yoga, crossfit, etc.).
- **Duración del plan:** 1 mes, 3 meses, 6 meses, 1 año personalizado.
- **Precio y opciones de pago:** Monto, frecuencia, métodos aceptados.

#### 2.3 Gestión de Pagos
- **Registrar pagos manuales:** Entrada de pagos en efectivo o transferencia.
- **Procesar pagos online:** Integración con pasarelas de pago.
- **Generar enlaces de pago personalizados:** URLs para que usuario pague desde app.
- **Consultar historial de transacciones:** Por usuario, fecha o estado.
- **Visualizar pagos aprobados, pendientes, rechazados o vencidos.**

#### 2.4 Control de Vencimientos
- **Alertas de vencimiento próximo:** Notificaciones 7, 14 y 30 días antes.
- **Renovación automática:** Configurar débito automático si está habilitado.
- **Marcar como vencido:** Cambiar estado automáticamente en fecha.
- **Historial de renovaciones:** Trazabilidad de cada renovación.

#### 2.5 Gestión de Promociones
- **Crear códigos de descuento:** Porcentaje o monto fijo.
- **Establecer validez:** Fechas de inicio y fin.
- **Limitar usos:** Por código o usuario.
- **Aplicar automáticamente:** Al registrar usuario nuevo o renovar.
- **Registrar uso de códigos:** Quién, cuándo y qué descuento aplicó.

### Información que Debe Manejar

#### Datos del Plan
- Nombre del plan
- Descripción
- Precio
- Duración (en meses)
- Beneficios incluidos (texto descriptivo)
- Número máximo de clases
- Acceso a sedes (lista de sedes)
- Restricciones especiales
- Estado (activo, inactivo)
- Fecha de creación
- Última modificación

#### Datos de Pago
- Referencia de transacción (ID único)
- Usuario asociado
- Monto pagado
- Método de pago (transferencia, tarjeta, efectivo, etc.)
- Fecha de pago
- Fecha de acreditación
- Estado (aprobado, pendiente, rechazado, cancelado)
- Comprobante/Recibo
- Notas adicionales

#### Datos de Descuentos
- Código de descuento
- Tipo (porcentaje, monto fijo)
- Valor
- Planes aplicables
- Fecha inicio
- Fecha fin
- Usos totales permitidos
- Usos realizados
- Usuarios que lo utilizaron

### Submódulos

#### 2.5.1 Submódulo de Reportes Financieros
- Ingresos por período
- Métodos de pago más utilizados
- Tasa de conversión de pagos
- Proyecciones de ingresos

#### 2.5.2 Submódulo de Pasarelas de Pago
- Integración con Stripe
- Integración con PayPal
- Integración con bancos locales
- Manejo de errores y reintentos

#### 2.5.3 Submódulo de Facturación
- Generación automática de facturas
- Envío por email
- Descargables en PDF
- Cumplimiento fiscal

---

## 3. Módulo de Rutinas, Ejercicios y Progreso

### Descripción General
Este módulo administra la biblioteca de ejercicios, creación de rutinas personalizadas y seguimiento del progreso físico de los usuarios.

### Funciones Principales

#### 3.1 Gestión de Ejercicios
- **Crear ejercicio:** Nombre, descripción, grupo muscular.
- **Editar ejercicio:** Actualizar información sin afectar rutinas vigentes.
- **Eliminar ejercicio:** Marcar como obsoleto si no está en uso.
- **Adjuntar recursos:** Imágenes y videos demostrativos.
- **Clasificar ejercicios:** Por grupo muscular, nivel, objetivo, equipo.

#### 3.2 Clasificación de Ejercicios
- **Grupo muscular:** Pecho, espalda, brazos, piernas, core, cardio, etc.
- **Nivel de dificultad:** Principiante, intermedio, avanzado.
- **Objetivo:** Hipertrofia, fuerza, resistencia, cardio, flexibilidad.
- **Tipo de equipo:** Mancuernas, barras, máquinas, peso corporal, cables.
- **Tiempo de ejecución:** Duración estimada.

#### 3.3 Creación y Gestión de Rutinas
- **Crear rutinas base:** Modelos para asignar a usuarios.
- **Personalizar rutinas:** Adaptar para usuarios específicos.
- **Copiar rutinas:** Duplicar para crear variantes.
- **Archivar rutinas:** Mantener histórico sin afectar vigentes.
- **Versionar rutinas:** Controlar cambios en el tiempo.

#### 3.4 Asignación de Rutinas
- **Asignar a usuarios específicos:** Seleccionar uno o múltiples usuarios.
- **Asignar por nivel:** Automáticamente según evaluación del usuario.
- **Asignar por plan:** Beneficios incluidos en la membresía.
- **Fecha de inicio y fin:** Período de vigencia.
- **Notificaciones:** Alertar usuario cuando se asigna rutina nueva.

#### 3.5 Seguimiento de Entrenamientos
- **Registrar entrenamientos completados:** Usuario registra sesiones en app.
- **Ver histórico de sesiones:** Todas las actividades del usuario.
- **Consultar cumplimiento:** Adherencia a la rutina (% de sesiones completadas).
- **Análisis de consistencia:** Frecuencia de entrenamientos.

#### 3.6 Progreso Físico
- **Registrar medidas corporales:** Peso, altura, perímetros (pecho, cintura, cadera).
- **Calcular IMC:** Índice de masa corporal automático.
- **Estimar porcentaje graso:** Según fórmulas antropométricas.
- **Gráficos de progreso:** Visualización de tendencias en el tiempo.
- **Hitos de progreso:** Reconocer logros (perder X kg, ganar músculo, etc.).

#### 3.7 Análisis y Reportes
- **Evolución por ejercicio:** Progresión de peso, series, repeticiones.
- **Comparativas de progreso:** Antes y después.
- **Predicciones de cumplimiento:** Basadas en historial.

### Información que Debe Manejar

#### Datos del Ejercicio
- ID único del ejercicio
- Nombre del ejercicio
- Descripción detallada
- Grupo muscular primario
- Grupo muscular secundario (opcional)
- Nivel de dificultad
- Objetivo de entrenamiento
- Tipo de equipo requerido
- Tiempo de ejecución (segundos)
- URL de imagen demostrativa
- URL de video demostrativo
- Instrucciones de ejecución
- Precauciones/Contraindicaciones
- Fecha de creación
- Última actualización

#### Datos de la Rutina
- ID único de rutina
- Nombre de la rutina
- Objetivo (hipertrofia, fuerza, cardio, etc.)
- Nivel recomendado
- Días de entrenamiento por semana
- Duración total (minutos por sesión)
- Lista de ejercicios (ordenada)
- Para cada ejercicio:
  - Series prescritas
  - Repeticiones prescritas
  - Peso recomendado
  - Tiempo de descanso (segundos)
  - Notas específicas
- Fecha de creación
- Versión

#### Datos de Asignación
- Usuario asignado
- Rutina asignada
- Fecha de inicio
- Fecha de término
- Estado (vigente, completada, pausada)
- Entrenador que asignó
- Razón de asignación

#### Datos de Entrenamientos
- ID de sesión de entrenamiento
- Usuario que entreno
- Fecha y hora
- Rutina seguida
- Ejercicios completados:
  - Ejercicio
  - Series realizadas
  - Repeticiones realizadas
  - Peso utilizado
  - Tiempo de descanso real
  - Notas del usuario (cómo se sintió)
- Duración total
- Observaciones

#### Datos de Medidas
- Usuario
- Fecha de medición
- Peso (kg)
- Altura (cm)
- Pecho (cm)
- Cintura (cm)
- Cadera (cm)
- Brazo (cm)
- Muslo (cm)
- IMC calculado
- % Graso estimado
- Entrenador que tomó medidas

### Submódulos

#### 3.7.1 Submódulo de Videos y Recursos
- Gestor de biblioteca de videos
- Subida y conversión de videos
- Generación de thumbnails
- CDN para reproducción

#### 3.7.2 Submódulo de Analytics de Ejercicios
- Ejercicios más realizados
- Ejercicios con mejor progresión
- Equipos más utilizados
- Recomendaciones automáticas basadas en uso

#### 3.7.3 Submódulo de Plantillas de Rutinas
- Rutinas prediseñadas por objetivo
- Rutinas por nivel de experiencia
- Rutinas populares de la comunidad

---

## 4. Módulo de Gestión de Clases y Reservas

### Descripción General
Este módulo administra todas las clases grupales, permitiendo crear horarios, gestionar reservas, controlar asistencia y optimizar ocupación de espacios.

### Funciones Principales

#### 4.1 Creación y Configuración de Clases
- **Crear clase:** Definir tipo, horario, capacidad.
- **Editar clase:** Modificar información de clase vigente.
- **Cancelar clase:** Notificar usuarios inscritos.
- **Duplicar clase:** Crear recurrencia semanal/mensual.
- **Asignar entrenador:** Designar instructor responsable.

#### 4.2 Configuración de Horarios y Capacidad
- **Definir fecha y hora:** Inicio y duración específicos.
- **Seleccionar sede:** Ubicación donde se realizará.
- **Asignar salón:** Espacio físico específico.
- **Configurar cupo máximo:** Cantidad de lugares disponibles.
- **Configurar lista de espera:** Si aplica para la clase.

#### 4.3 Gestión de Reservas
- **Permitir reservas desde app:** Usuarios se inscriben automáticamente.
- **Reservar desde admin:** Inscribir usuarios manualmente.
- **Visualizar inscritos:** Lista completa de participantes.
- **Cancelar reserva:** Permitir a usuario o admin desistir.
- **Confirmar asistencia:** 24 horas antes como recordatorio.

#### 4.4 Control de Acceso y Validación
- **Validar plan de usuario:** Confirmar que tiene acceso a la clase.
- **Validar disponibilidad:** No exceder cupo máximo.
- **Identificar conflictos de horario:** Alertar si usuario reservó otra clase.
- **Permitir reservas por adelantado:** X días antes de la clase.
- **Límite de reservas activas:** Por usuario, para evitar abuso.

#### 4.5 Asistencia
- **Registrar asistencia:** Marcar presentes/ausentes.
- **Registro QR:** Scanner de código QR para entrada rápida.
- **Confirmación de hora:** Permitir llegadas hasta X minutos después de inicio.
- **Histórico de asistencia:** Por usuario y por clase.
- **Calcular tasa de asistencia:** Por usuario y por clase.

#### 4.6 Notificaciones y Comunicación
- **Recordatorios previos:** Email/SMS 24h y 1h antes.
- **Notificación de cancelación:** Si se cancela la clase.
- **Notificación de cambio de horario:** Si se modifica programación.
- **Confirmación de cancelación:** Si usuario cancela su reserva.

#### 4.7 Reportes y Análisis
- **Clases más reservadas:** Ranking por demanda.
- **Ocupación promedio:** Porcentaje de cupo utilizado.
- **Tasa de inasistencia:** Usuarios que reservan pero no asisten.
- **Clases con bajo asistencia:** Identificar clases poco populares.
- **Entrenadores más solicitados:** Ranking de instructores.

### Información que Debe Manejar

#### Datos de la Clase
- ID único de clase
- Nombre/Tipo de clase
- Descripción
- Categoría (yoga, cardio, fuerza, flexibilidad, etc.)
- Entrenador asignado
- Sede
- Salón/Zona específica
- Fecha y hora de inicio
- Hora de fin
- Duración (minutos)
- Cupo máximo de participantes
- Lista de espera activa (sí/no)
- Usuarios inscritos (lista)
- Usuarios en lista de espera
- Estado (activa, cancelada, finalizada, pospuesta)
- Fecha de creación
- Última modificación

#### Datos de Reserva
- ID de reserva
- Usuario que reserva
- Clase reservada
- Fecha de reserva
- Estado (confirmada, cancelada, asistió, no asistió)
- Modo de cancelación (por usuario, por sistema)
- Fecha de cancelación (si aplica)

#### Datos de Asistencia
- ID de registro de asistencia
- Usuario
- Clase asistida
- Fecha
- Hora de llegada
- Hora de salida (si se registra)
- Estado (asistió, no asistió, llegada tarde)
- Notas

### Submódulos

#### 4.7.1 Submódulo de Recurrencias
- Clases recurrentes (diarias, semanales, mensuales)
- Manejo de excepciones
- Cancelación en serie

#### 4.7.2 Submódulo de Espacio Físico
- Gestión de salones/zonas
- Capacidad máxima por espacio
- Disponibilidad de horarios
- Equipamiento de cada salón

#### 4.7.3 Submódulo de Validación de Acceso
- Reglas de acceso por plan
- Restricciones por nivel de experiencia
- Cuotas de clases permitidas

---

## 5. Módulo de Entrenadores

### Descripción General
Este módulo gestiona los perfiles de entrenadores personales, su disponibilidad y asignaciones tanto para clases como para usuarios individuales.

### Funciones Principales

#### 5.1 Gestión de Perfiles
- **Crear perfil de entrenador:** Registro de nuevo staff.
- **Editar información:** Actualizar datos personales y profesionales.
- **Desactivar entrenador:** Retirarse sin eliminar histórico.
- **Reactivar entrenador:** Volver a activar si es necesario.
- **Ver información completa:** Datos personales, certificaciones, experiencia.

#### 5.2 Información Profesional
- **Especialidad/Certificación:** Áreas de experticia.
- **Experiencia en años:** Trayectoria profesional.
- **Idiomas:** Lenguajes que habla (para atender usuarios).
- **Foto de perfil:** Imagen profesional.
- **Bio:** Breve descripción de entrenador.

#### 5.3 Asignaciones
- **Asignar a usuarios:** Vincular entrenador personal a usuario.
- **Asignar a clases:** Designar como instructor de clase.
- **Cambiar asignación:** Reasignar a otro entrenador si es necesario.
- **Ver usuarios asignados:** Lista de clientes del entrenador.
- **Ver clases asignadas:** Calendario de classes que dicta.

#### 5.4 Gestión de Disponibilidad
- **Horarios disponibles:** Cuándo puede hacer sesiones personales.
- **Días de descanso:** Días libres o no disponibles.
- **Vacaciones:** Períodos de ausencia planificada.
- **Notificaciones de cambio:** Alertar a usuarios de cambios en disponibilidad.

#### 5.5 Carga de Trabajo
- **Número de usuarios asignados:** Actual vs. máximo.
- **Clases por semana:** Horas dedicadas a clases grupales.
- **Horas disponibles:** Horas disponibles para nuevas asignaciones.
- **Eficiencia:** Utilización de tiempo disponible.

#### 5.6 Histórico y Reporte
- **Historial de clases dictadas:** Todas las clases realizadas.
- **Usuarios que ha atendido:** Histórico completo.
- **Evaluación por usuario:** Calificaciones y feedback.
- **Cambios en perfil:** Quién y cuándo modificó información.

### Información que Debe Manejar

#### Datos Personales
- ID único del entrenador
- Nombre completo
- Apellidos
- Documento/Cédula
- Correo electrónico
- Teléfono principal
- Teléfono secundario (opcional)
- Fecha de nacimiento
- Dirección
- Foto de perfil

#### Datos Profesionales
- Especialidad principal
- Certificaciones (lista)
- Institución certificadora
- Año de certificación
- Experiencia en años
- Idiomas que habla
- Bio/Descripción profesional
- Tarifa de sesión personal (si aplica)

#### Datos de Asignación
- Usuarios asignados (lista con ID)
- Clases asignadas (lista)
- Clientes activos
- Clientes anteriores
- Sede principal
- Sedes adicionales

#### Datos de Disponibilidad
- Horarios disponibles (por día)
- Horas de inicio y fin
- Duración de sesiones personales
- Días de descanso
- Período de vacaciones
- Máximo de usuarios simultáneos

#### Datos de Desempeño
- Clases dictadas (total)
- Usuarios activos bajo su cuidado
- Calificación promedio
- Feedback recibido

### Submódulos

#### 5.6.1 Submódulo de Evaluación y Feedback
- Calificaciones de usuarios
- Comentarios sobre desempeño
- Reportes de desempeño
- Reconocimiento y bonificaciones

#### 5.6.2 Submódulo de Capacitación
- Cursos y certificaciones disponibles
- Registro de entrenamientos completados
- Renovación de certificados

#### 5.6.3 Submódulo de Comisiones
- Cálculo de comisiones por sesiones
- Comisiones por nuevos usuarios
- Histórico de pagos

---

## 6. Módulo de Marketing, Retención y Comunicación

### Descripción General
Este módulo implementa estrategias de segmentación, automatización de comunicación y análisis de retención de usuarios para maximizar ciclo de vida del cliente.

### Funciones Principales

#### 6.1 Segmentación de Usuarios
- **Segmentar por sede:** Agrupar usuarios por ubicación.
- **Segmentar por plan:** Clasificar según membresía contratada.
- **Segmentar por estado:** Activos, inactivos, vencidos, nuevos.
- **Segmentar por actividad:** Usuarios frecuentes vs. ocasionales.
- **Segmentar por objetivo:** Usuarios que buscan fuerza, cardio, etc.
- **Segmentar por antigüedad:** Nuevos, 3 meses, 6 meses, 1 año+.
- **Segmentos personalizados:** Crear grupos según criterios específicos.

#### 6.2 Identificación de Riesgos
- **Usuarios inactivos:** Quién no se ha conectado en X días.
- **Membresía próxima a vencer:** Alertas 30, 14, 7 días antes.
- **Pagos vencidos:** Usuarios con deuda.
- **Baja adherencia:** Usuarios que no asisten a clases.
- **Tendencia de abandono:** Análisis predictivo de churn.

#### 6.3 Campañas Automáticas
- **Bienvenida a nuevos usuarios:** Email automático de bienvenida.
- **Recordatorio de vencimiento:** SMS/Email 30 días, 14 días, 7 días.
- **Recordatorio de pago:** Para usuarios con membresía próxima a vencer.
- **Reactivación de inactivos:** Ofertas especiales para usuarios inactivos.
- **Oferta de planes premium:** Para usuarios en planes básicos.

#### 6.4 Canales de Comunicación
- **Email:** Envío masivo con personalización.
- **SMS/WhatsApp:** Mensajes directos al teléfono.
- **Push notifications:** Alertas en app móvil.
- **In-app messages:** Mensajes dentro de la aplicación.
- **Combinadas:** Estrategias multi-canal.

#### 6.5 Gestión de Promociones
- **Crear campañas:** Definir objetivo, audiencia, mensaje.
- **Seleccionar audiencia:** Aplicar segmentos específicos.
- **Personalizar mensaje:** Variables dinámicas (nombre, plan, etc.).
- **Programar envío:** Fecha y hora específicas o automático.
- **Testear antes de enviar:** Vista previa para validar.

#### 6.6 Medición de Efectividad
- **Tasa de apertura:** % de usuarios que abrieron email.
- **Tasa de click:** % que hicieron clic en llamado a acción.
- **Tasa de conversión:** % que completó acción deseada (renovar, pagar).
- **ROI de campaña:** Ingresos generados vs. costo de campaña.
- **A/B testing:** Comparar variantes de mensajes.

### Información que Debe Manejar

#### Datos de Segmento
- ID de segmento
- Nombre descriptivo
- Descripción
- Criterios de segmentación (condiciones)
- Número de usuarios en segmento
- Fecha de creación
- Última actualización

#### Datos de Campaña
- ID de campaña
- Nombre de campaña
- Objetivo de campaña
- Segmento objetivo
- Mensaje principal
- Variables personalizadas
- Canales utilizados
- Fecha de inicio programada
- Fecha de fin
- Estado (borrador, programada, en curso, completada)
- Presupuesto asignado

#### Datos de Envío
- ID de envío
- Campaña asociada
- Usuario destino
- Canal utilizado (email, SMS, push)
- Contenido enviado
- Fecha y hora de envío
- Estado (enviado, rebotado, bloqueado)

#### Datos de Interacción
- ID de interacción
- Envío asociado
- Tipo (abierto, click, conversión)
- Fecha y hora
- Valor de conversión (monto si aplica)

#### Datos de Promoción
- ID de promoción
- Tipo (descuento, acceso, contenido)
- Valor o descripción
- Vigencia (desde-hasta)
- Código relacionado
- Campañas que la incluyen

### Submódulos

#### 6.6.1 Submódulo de Automatización
- Flujos automáticos por evento
- Disparo de mensajes por condiciones
- Secuencias de email
- Manejo de rebotes y desuscripciones

#### 6.6.2 Submódulo de Templates
- Biblioteca de plantillas de email
- Editor visual de mensajes
- Variables dinámicas predefinidas
- Historial de templates

#### 6.6.3 Submódulo de Análisis de Churn
- Predicción de abandono
- Factores de riesgo identificados
- Modelos de retención
- Recomendaciones automáticas

---

## 7. Módulo de Reportes y Analítica

### Descripción General
Este módulo proporciona visibilidad integral de la operación del gimnasio a través de reportes administrativos, comerciales y operativos con capacidades avanzadas de análisis.

### Funciones Principales

#### 7.1 Reportes Financieros
- **Ingresos mensuales:** Total de ingresos por período.
- **Desglose por plan:** Ingresos por tipo de membresía.
- **Desglose por sede:** Ingresos por ubicación.
- **Pagos aprobados vs. pendientes:** Estado de cobranza.
- **Pagos rechazados:** Motivos y acciones correctivas.
- **Proyecciones:** Pronóstico de ingresos futuros.
- **Comparativa período anterior:** Año a año o mes a mes.

#### 7.2 Reportes de Usuarios
- **Usuarios activos:** Total de miembros vigentes.
- **Usuarios vencidos:** Membresías expiradas no renovadas.
- **Usuarios nuevos:** Registrados en período actual.
- **Usuarios inactivos:** Sin acceso en X días.
- **Churn rate:** % de usuarios perdidos.
- **Retención:** % de usuarios que renuevan.
- **Lifetime value:** Valor total que genera cada usuario.

#### 7.3 Reportes de Operación
- **Asistencia por sede:** Ocupación de cada ubicación.
- **Asistencia por clase:** Promedio de personas por clase.
- **Clases más populares:** Ranking por demanda.
- **Ocupación de horarios:** Horas pico vs. horas bajas.
- **Utilización de entrenadores:** Carga de trabajo por trainer.
- **Disponibilidad de cupos:** Clases llenas vs. disponibles.

#### 7.4 Reportes de Progreso
- **Progreso promedio de usuarios:** Mejoras en medidas físicas.
- **Adherencia a rutinas:** % de cumplimiento de planes.
- **Asistencia a clases:** Frecuencia de participación.
- **Mejora por categoría:** Progreso en fuerza, cardio, etc.
- **Top performers:** Usuarios con mejor desempeño.

#### 7.5 Reportes de Marketing
- **Tasa de conversión de campañas:** % que se convirtieron.
- **ROI por campaña:** Retorno de inversión en marketing.
- **Canales más efectivos:** Email vs. SMS vs. Push.
- **Segmentos más responsivos:** Qué grupo responde mejor.
- **Tasa de renovación:** % de usuarios que renuevan.

#### 7.6 Dashboards y Visualizaciones
- **Dashboard ejecutivo:** KPIs principales en una vista.
- **Gráficos interactivos:** Lineas, barras, pie charts.
- **Mapas de calor:** Ocupación por horario/sede.
- **Tendencias:** Visualización de tendencias en el tiempo.
- **Alertas críticas:** Resaltado de KPIs fuera de rango.

### Información que Debe Manejar

#### Métricas Financieras
- Ingreso total
- Ingreso por plan
- Ingreso por sede
- Ingresos por método de pago
- Gastos operativos
- Margen bruto
- Ingresos promedio por usuario
- Costo de adquisición de cliente (CAC)
- Lifetime value (LTV)
- ROI general

#### Métricas de Usuarios
- Usuarios activos (totales)
- Usuarios nuevos en período
- Usuarios vencidos
- Usuarios inactivos
- Usuarios bloqueados
- Tasa de crecimiento
- Tasa de churn
- Tasa de retención
- Tiempo promedio de membresía
- Plan más popular

#### Métricas Operacionales
- Promedio de asistencia por clase
- Ocupación promedio (% de cupo)
- Clases canceladas
- Entrenadores activos
- Entrenador con más clases
- Horas de operación utilizada
- Conflictos de horario resueltos

#### Métricas de Progreso
- Usuarios activos en rutinas
- Cumplimiento promedio de rutinas (%)
- Mejora promedio en peso
- Mejora promedio en medidas
- Ejercicios más realizados
- Progresión en cargas
- Asistencia a clases (frecuencia)

#### Métricas de Marketing
- Campañas enviadas
- Mensajes abiertos
- Tasa de click-through
- Conversiones por campaña
- Código de descuento más utilizado
- Usuarios reactivados
- Nuevos usuarios por fuente

### Submódulos

#### 7.6.1 Submódulo de Exportación
- Exportar a Excel/PDF
- Automatizar reportes diarios/semanales
- Envío por email programado
- Formatos personalizados

#### 7.6.2 Submódulo de Predicción
- Predicción de churn
- Predicción de ingresos
- Recomendaciones automáticas
- Modelado de escenarios

#### 7.6.3 Submódulo de Auditoría
- Cambios en datos
- Accesos a reportes
- Exportaciones realizadas
- Trazabilidad completa

---

## Filtros Recomendados para Todos los Módulos

### Filtros Temporales
- Fecha inicio - Fecha fin
- Período predefinido (hoy, esta semana, este mes, este año)
- Comparación período anterior

### Filtros por Entidad
- Sede
- Plan/Membresía
- Entrenador
- Clase
- Segmento de usuario

### Filtros de Estado
- Estado del usuario
- Estado de pago
- Estado de clase
- Estado de reserva

### Filtros Avanzados
- Rango de valores (precio, edad, IMC)
- Múltiples criterios combinados
- Búsqueda de texto libre
- Guardar filtros frecuentes

---

## Matriz de Relaciones Entre Módulos

```
USUARIOS
├── Vinculado con PLANES (Membresía)
├── Vinculado con PAGOS (Registro de transacciones)
├── Vinculado con RUTINAS (Asignaciones)
├── Vinculado con CLASES (Reservas y asistencia)
├── Vinculado con ENTRENADORES (Personal trainer)
└── Afectado por MARKETING (Campañas dirigidas)

PLANES
├── Define acceso a CLASES
├── Genera PAGOS
└── Utilizado en MARKETING

PAGOS
├── Genera INGRESOS en REPORTES
├── Vinculado con USUARIOS
└── Requerido para PLANES

RUTINAS
├── Asignadas a USUARIOS
├── Genera datos para PROGRESO
└── Analizado en REPORTES

CLASES
├── Reservadas por USUARIOS
├── Asignadas a ENTRENADORES
├── Controlada por PLANES (acceso)
└── Analizada en REPORTES

ENTRENADORES
├── Asignados a USUARIOS
├── Dictan CLASES
└── Analizado en REPORTES

MARKETING
├── Segmenta USUARIOS
├── Genera PAGOS (conversiones)
└── Medido en REPORTES
```

---

## Conclusión

Esta estructura modular proporciona una gestión completa e integrada de todas las operaciones del gimnasio. Cada módulo es independiente pero funciona en armonía con los otros, permitiendo una visión holística del negocio y la capacidad de tomar decisiones basadas en datos.

**Próximos pasos:**
1. Validación con stakeholders
2. Priorización de módulos para desarrollo
3. Especificación técnica detallada
4. Arquitectura de base de datos
5. Diseño de interfaces de usuario
