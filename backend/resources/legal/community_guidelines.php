<?php

/*
|--------------------------------------------------------------------------
| Lineamientos de Comunidad — documento versionado
|--------------------------------------------------------------------------
|
| Fuente única del texto. Lo sirve `GET /api/app/moderation/guidelines` y lo
| pinta la app de forma nativa: NO hay página web que mantener, ni enlace que
| se pueda romper.
|
| REGLA AL EDITAR: aquí sólo pueden declararse facultades que el sistema
| ejerce de verdad. A día de hoy, verificadas en el código:
|   · reportar una publicación            POST /app/stories/{id}/report
|   · reportar a una persona              POST /app/members/{id}/report
|   · bloquear / desbloquear              POST|DELETE /app/members/{id}/block
|   · retirar una publicación             StoriesController::destroyAsAdmin
|     (borrado lógico: la fila se conserva como evidencia)
|   · restringir por ámbitos              content_only · story_posting ·
|     story_interaction · social_features · full_app_access
|   · apelar una sanción                  POST /app/moderation/actions/{id}/appeal
|
| Lo que NO se declara, porque no se hace: no verificamos la edad de forma
| automática (`ugc.posting_age_enforced` está apagado), no revisamos el
| contenido antes de publicarse, y no analizamos las publicaciones con
| sistemas automáticos.
|
| Al cambiar el texto de forma sustantiva hay que subir `ugc.guidelines_version`.
| Eso hace que el backend vuelva a exigir la aceptación a todo el mundo.
*/

return [
    'version' => '1.0',
    'effective_date' => '2026-09-01',

    'title' => 'Lineamientos de Comunidad',
    'subtitle' => 'Una comunidad segura para entrenar y compartir.',

    'intro' => 'Los estados de Iron Body son un espacio para compartir tu '
        .'entrenamiento con el resto de socios del gimnasio. Estas normas '
        .'aplican a lo que publicas y a cómo tratas a los demás. Son '
        .'independientes de tu contrato de membresía, de la política de '
        .'privacidad y de los consentimientos médicos: aceptarlas no cambia '
        .'nada de eso, y no aceptarlas sólo afecta a tu posibilidad de '
        .'publicar estados.',

    /*
    | Bloques escaneables. `icon` es un nombre lógico; el cliente lo traduce a
    | su propio icono para no depender de assets externos.
    */
    'sections' => [
        [
            'icon' => 'respect',
            'title' => 'Respeto, primero',
            'summary' => 'Trata a los demás como querrías que te trataran en el gimnasio.',
            'body' => 'No se permite el acoso, las amenazas, la intimidación ni '
                .'los insultos. Tampoco el contenido que ataque o denigre a '
                .'alguien por su origen, etnia, nacionalidad, religión, '
                .'discapacidad, edad, sexo, orientación sexual, identidad de '
                .'género, apariencia física o condición social. Las críticas a '
                .'un entrenamiento o a un servicio son bienvenidas; los ataques '
                .'a una persona, no.',
        ],
        [
            'icon' => 'content',
            'title' => 'Contenido apropiado',
            'summary' => 'Estamos en un gimnasio, y se publica como en un gimnasio.',
            'body' => 'No publiques desnudos ni contenido sexual explícito, ni '
                .'contenido que sexualice a otras personas. No publiques '
                .'violencia gráfica, autolesiones, contenido que promueva '
                .'trastornos alimentarios, ni la venta o promoción de sustancias '
                .'ilegales o de uso restringido, incluidas las sustancias '
                .'dopantes. Tampoco contenido que infrinja la ley colombiana.',
        ],
        [
            'icon' => 'privacy',
            'title' => 'Privacidad de los demás',
            'summary' => 'En el gimnasio hay más gente. Pregunta antes de grabar.',
            'body' => 'No publiques a otras personas sin su permiso, y menos en '
                .'vestuarios, baños o zonas de cambio. No difundas datos '
                .'personales de nadie —teléfono, dirección, documento, historia '
                .'clínica— ni siquiera si los conoces por ser socio. Si alguien '
                .'te pide que retires una publicación en la que aparece, '
                .'retírala.',
        ],
        [
            'icon' => 'authentic',
            'title' => 'Sé tú mismo',
            'summary' => 'Nada de suplantar a otros ni de llenar el muro de publicidad.',
            'body' => 'No te hagas pasar por otra persona, por un entrenador ni '
                .'por Iron Body. No publiques spam, cadenas, estafas, ni '
                .'promoción comercial ajena al gimnasio. Publica contenido '
                .'propio: si usas material de terceros, asegúrate de tener '
                .'derecho a compartirlo.',
        ],
        [
            'icon' => 'moderation',
            'title' => 'Reportes y moderación',
            'summary' => 'Puedes reportar y bloquear. Nosotros revisamos y actuamos.',
            'body' => 'Desde la app puedes reportar una publicación o a una '
                .'persona, y bloquear a quien no quieras que interactúe '
                .'contigo. Iron Body revisa los reportes y puede retirar una '
                .'publicación o restringir el acceso a las funciones sociales. '
                .'Si te sancionamos, te decimos el motivo y puedes apelar desde '
                .'la propia app.',
        ],
    ],

    /*
    | Texto íntegro. Es lo que se muestra en «Leer lineamientos completos» y lo
    | que rige en caso de duda: los bloques de arriba son un resumen.
    */
    'full_text' => <<<'TXT'
LINEAMIENTOS DE COMUNIDAD DE IRON BODY WORKOUT
Versión 1.0 · vigente desde el 1 de septiembre de 2026

1. A QUÉ APLICAN ESTOS LINEAMIENTOS

Estos lineamientos rigen los estados (fotos y videos con texto) que publicas
en la aplicación de Iron Body Workout y la forma en que interactúas con otros
socios dentro de ella.

Son un documento aparte. No sustituyen ni modifican tu contrato de membresía,
la política de privacidad ni los consentimientos médicos que hayas firmado.
Aceptarlos es requisito únicamente para publicar estados. Si decides no
aceptarlos, conservas el acceso completo al resto de la aplicación:
entrenamientos, rutinas, nutrición, clases, pagos y membresía.

2. QUIÉN PUEDE PUBLICAR

Para publicar debes ser socio con cuenta activa y haber aceptado estos
lineamientos en su versión vigente. Iron Body establece una edad mínima de 13
años para publicar estados.

3. CONTENIDO QUE NO SE PERMITE

3.1 Ataques a las personas. Acoso, amenazas, intimidación, insultos o
    campañas dirigidas contra alguien.

3.2 Discriminación. Contenido que ataque o denigre a una persona o a un grupo
    por su origen, etnia, nacionalidad, religión, discapacidad, edad, sexo,
    orientación sexual, identidad de género, apariencia física o condición
    social.

3.3 Contenido sexual. Desnudos, contenido sexual explícito o contenido que
    sexualice a otras personas.

3.4 Violencia. Violencia gráfica, amenazas de violencia, apología de actos
    violentos, autolesiones o contenido que promueva trastornos alimentarios.

3.5 Contenido ilegal. Cualquier contenido que infrinja la ley colombiana,
    incluida la promoción o venta de sustancias ilegales, medicamentos de uso
    restringido o sustancias dopantes.

3.6 Spam y engaño. Publicaciones repetitivas, cadenas, estafas, esquemas
    piramidales o promoción comercial ajena a Iron Body.

3.7 Suplantación. Hacerse pasar por otra persona, por un entrenador o por
    Iron Body.

4. PRIVACIDAD DE TERCEROS

4.1 No publiques imágenes o videos de otras personas sin su consentimiento.

4.2 Está prohibido grabar o fotografiar en vestuarios, baños y zonas de
    cambio, sin excepción.

4.3 No difundas datos personales de terceros: teléfono, dirección, número de
    documento, información médica o cualquier dato que permita identificarlos
    o contactarlos.

4.4 Si una persona que aparece en tu publicación te pide retirarla, debes
    hacerlo. También puedes solicitar a Iron Body que la retire.

5. CONTENIDO DE TERCEROS

Publica contenido del que seas autor o sobre el que tengas derechos. Si
compartes material de terceros —música, video, imágenes—, eres responsable de
contar con la autorización necesaria. Iron Body puede retirar contenido ante
una reclamación fundada de derechos de autor.

6. REPORTES

Dentro de la aplicación puedes:

6.1 Reportar una publicación que consideres que incumple estos lineamientos.

6.2 Reportar a una persona.

6.3 Bloquear a otro socio, de modo que no pueda interactuar contigo.

Los reportes se revisan de forma manual por el equipo de Iron Body. No
analizamos las publicaciones con sistemas automáticos ni revisamos el
contenido antes de que se publique.

7. QUÉ PUEDE HACER IRON BODY

Cuando una publicación o una conducta incumple estos lineamientos, Iron Body
puede, según la gravedad y la reincidencia:

7.1 Retirar la publicación. El contenido deja de ser visible para los demás
    socios. El registro se conserva internamente como evidencia del caso.

7.2 Restringir tu cuenta de forma graduada:
    · limitar únicamente el contenido afectado;
    · impedirte publicar estados;
    · impedirte interactuar con los estados de otros;
    · suspender las funciones sociales;
    · suspender el acceso a la aplicación.

7.3 Las restricciones pueden ser temporales o permanentes. Se te informará del
    motivo y, cuando aplique, de la fecha de finalización.

Estas medidas afectan al uso de la aplicación. No modifican por sí solas tu
contrato de membresía ni tu acceso físico a las instalaciones, que se rigen
por el reglamento del gimnasio.

8. APELACIONES

Si consideras que una medida fue injusta, puedes apelarla desde la propia
aplicación, en la sección de estado de tu cuenta. Revisaremos el caso y te
comunicaremos la decisión.

9. CAMBIOS EN ESTOS LINEAMIENTOS

Podemos actualizar estos lineamientos. Cada versión lleva un número. Cuando
publiquemos una versión nueva, se te pedirá aceptarla antes de volver a
publicar un estado. Podrás consultar siempre la versión vigente desde tu
perfil, en la sección legal.

10. CONTACTO

Para cualquier duda sobre estos lineamientos, o para solicitar la retirada de
contenido en el que apareces, escríbenos desde la sección de soporte de la
aplicación.
TXT,
];
