<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La conexión de WhatsApp Business hecha desde el CRM (Embedded Signup de Meta).
 *
 * Hasta ahora los identificadores de Meta —WABA, número, negocio— vivían SOLO en
 * el `.env` del servidor. Eso obligaba a que conectar el canal fuese una tarea de
 * consola: entrar por SSH, pegar valores copiados del panel de Meta y recachear.
 * Nadie del negocio podía hacerlo, no quedaba constancia de quién lo hizo, y un
 * valor mal pegado no se descubría hasta que un mensaje no salía.
 *
 * Esta tabla es lo que devuelve el onboarding oficial cuando el dueño del número
 * autoriza a la app desde dentro del CRM. No sustituye al `.env`: lo antecede.
 * Mientras no haya una fila `connected`, todo el canal sigue leyendo exactamente
 * las mismas variables de entorno de siempre, así que instalar esta migración no
 * cambia el comportamiento de nada.
 *
 * Lo que esta tabla NO hace: encender el canal. `META_ENABLED` sigue siendo el
 * único interruptor que autoriza salir a la red. Guardar credenciales y permitir
 * enviar mensajes son dos decisiones distintas y deben poder tomarse por
 * separado —conectar para verificar, sin que empiece a escribirle a nadie—.
 *
 * El par (waba_id, phone_number_id) es único para que reconectar el mismo número
 * ACTUALICE la fila en vez de acumular una nueva cada vez. Sin eso, tras tres
 * reintentos habría tres filas «conectadas» y ninguna consulta sabría cuál rige.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_business_integrations', function (Blueprint $table): void {
            $table->id();

            // ── Identidad en Meta ────────────────────────────────────────────
            /** App de Meta que ejecutó el onboarding. Se guarda para que rotar
             *  de app deje rastro: una fila creada por otra app no es válida. */
            $table->string('meta_app_id')->nullable();
            /** Business Manager dueño de la cuenta (business_id). */
            $table->string('business_id')->nullable();
            /** WhatsApp Business Account (WABA). */
            $table->string('waba_id');
            /** ID del número en Cloud API. NO es el teléfono visible. */
            $table->string('phone_number_id');

            // ── Lo que se le enseña a un humano ──────────────────────────────
            /** Teléfono tal y como lo ve un cliente (+57 314 ...). Informativo. */
            $table->string('display_phone_number')->nullable();
            /** Nombre comercial verificado que Meta muestra al destinatario. */
            $table->string('verified_name')->nullable();
            /** Nombre del Business Manager, para confirmar que se eligió el bueno. */
            $table->string('business_name')->nullable();
            /** Calidad y estado del número según Meta (GREEN/YELLOW/RED, ...). */
            $table->string('quality_rating')->nullable();
            $table->string('platform_type')->nullable();

            // ── Estado de la conexión ────────────────────────────────────────
            /** pending | connected | disconnected | error */
            $table->string('status')->default('pending');

            /**
             * Token de negocio que devolvió el intercambio del código.
             *
             * Cifrado en la aplicación (cast `encrypted` del modelo), no en
             * texto plano: un volcado de la base de datos es lo primero que se
             * comparte cuando hay que depurar algo, y un token de Cloud API
             * permite escribirle a los clientes en nombre del negocio. La
             * columna es `text` porque el cifrado de Laravel infla el valor muy
             * por encima de los 255 caracteres de un `string`.
             */
            $table->text('access_token')->nullable();
            $table->string('token_type')->nullable();
            /** Nulo = token de larga duración sin caducidad declarada. */
            $table->timestamp('token_expires_at')->nullable();
            /** Permisos que Meta concedió de verdad, que no siempre son los pedidos. */
            $table->json('granted_scopes')->nullable();

            // ── Trazabilidad ─────────────────────────────────────────────────
            /** Admin del CRM que pulsó conectar. Sin FK dura: si esa cuenta se
             *  borra, el hecho histórico de quién conectó no debe desaparecer. */
            $table->unsignedBigInteger('connected_by')->nullable();
            $table->unsignedBigInteger('disconnected_by')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            /** Última vez que se refrescaron los datos contra Graph API. */
            $table->timestamp('last_synced_at')->nullable();

            /**
             * Por qué falló el último intento. Se guarda el código y el mensaje
             * de Meta porque «no se pudo conectar» no le sirve a nadie: la
             * diferencia entre un token caducado y un permiso no concedido
             * decide si hay que reintentar o entrar al panel de Meta.
             */
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();

            $table->timestamps();

            $table->unique(['waba_id', 'phone_number_id'], 'wa_integrations_waba_phone_unique');
            $table->index('status', 'wa_integrations_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_business_integrations');
    }
};
