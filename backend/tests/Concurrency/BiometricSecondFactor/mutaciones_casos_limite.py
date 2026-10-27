"""Mutaciones sobre la COPIA aislada del backend: cada una rompe un
comportamiento y debe hacer fallar la prueba que lo cubre. Restaura siempre.

Uso: python3 -I mutate.py <copia_app> <fichero_de_pruebas_relativo>
"""
import subprocess
import sys

APP, TESTFILE = sys.argv[1], sys.argv[2]
PHP = '/opt/homebrew/bin/php'

MUTS = [
    ('lock15-exacto', 'app/Models/WorkforcePin.php',
     "$this->locked_until->isFuture()", "! $this->locked_until->isPast()", 1,
     'test_el_primer_bloqueo_dura_15_minutos_justos'),
    ('tope-escalado', 'app/Services/Workforce/WorkforcePins.php',
     "min($model->lock_level + 1, count($steps) - 1)", "min($model->lock_level + 1, count($steps) - 2)", 1,
     'test_los_bloqueos_escalan_15_60_240_1440_minutos_y_se_quedan_en_24_horas'),
    ('ventana-24h-exacta', 'app/Services/Workforce/WorkforcePins.php',
     "->lte(now()->subHours($windowHours))", "->lt(now()->subHours($windowHours))", 1,
     'test_a_las_24_horas_justas_la_ventana_de_fallos_caduca'),
    ('ventana-24h-menos-1s', 'app/Services/Workforce/WorkforcePins.php',
     "->lte(now()->subHours($windowHours))", "->lte(now()->subHours($windowHours)->addSecond())", 1,
     'test_a_las_24_horas_menos_un_segundo_los_fallos_de_la_ventana_aun_cuentan'),
    ('olvido-7d-exacto', 'app/Services/Workforce/WorkforcePins.php',
     "->gt(now()->subDays($decayDays))", "->gte(now()->subDays($decayDays))", 1,
     'test_a_los_7_dias_justos_sin_bloqueo_el_escalado_vuelve_a_15_minutos'),
    ('olvido-7d-menos-1s', 'app/Services/Workforce/WorkforcePins.php',
     "->gt(now()->subDays($decayDays))", "->gt(now()->subDays($decayDays)->addSecond())", 1,
     'test_un_bloqueo_a_7_dias_menos_un_segundo_del_anterior_escala'),
    ('locked_until-en-respuesta', 'app/Exceptions/WorkforceException.php',
     "'locked_until' => $lockedUntil?->format(DATE_ATOM),", "'locked_until' => null,", 1,
     'test_sin_pin_y_con_el_pin_bloqueado_la_respuesta_lo_dice_con_su_hora'),
    ('attempts_left-de-vuelta', 'app/Exceptions/WorkforceException.php',
     "return new self('El PIN no es correcto.', 'pin_invalid', 422);",
     "return new self('El PIN no es correcto.', 'pin_invalid', 422, ['second_factor' => ['attempts_left' => 1]]);", 1,
     'test_pin_invalid_nunca_dice_cuantos_intentos_quedan'),
    ('setup-sin-plantilla-validada', 'app/Services/Workforce/FaceReferenceService.php',
     "if ($ref === null || ! $this->verifiable($ref)) {", "if ($ref === null) {", 1,
     'test_crear_el_pin_exige_una_plantilla_validada_y_sin_ella_no_gasta_el_codigo'),
    ('regla-de-fechas', 'app/Services/Workforce/WorkforcePins.php',
     "$v >= 1 && $v <= 31", "$v >= 1 && $v <= 32", 1,
     'test_el_borde_de_la_regla_de_fechas_31_12_si_32_12_no'),
    ('cara-antes-que-codigo', 'app/Http/Controllers/Api/Kiosk/KioskPinController.php',
     "        $this->codes->assertLive($identity, WorkforceAuthorizationCode::PURPOSE_PIN_SETUP);\n        $this->assertOwnFace($identity, $data['embedding'], $request);",
     "        $this->assertOwnFace($identity, $data['embedding'], $request);\n        $this->codes->assertLive($identity, WorkforceAuthorizationCode::PURPOSE_PIN_SETUP);", 1,
     'test_sin_codigo_de_pin_vivo_la_cara_ni_se_compara'),
    ('formato-de-pin-libre', 'app/Http/Controllers/Api/Kiosk/KioskVerificationController.php',
     "'pin' => ['nullable', 'string', 'regex:/^\\d{6}$/'],", "'pin' => ['nullable'],", 1,
     'test_un_pin_con_formato_invalido_es_un_422_de_validacion_que_no_gasta_intentos'),
    ('ambiguo-limpia-contador', 'app/Services/Workforce/FaceReferenceService.php',
     "        $ambiguity = $this->ambiguity($ref, $embedding, $distance);",
     "        RateLimiter::clear($limiterKey);\n        $ambiguity = $this->ambiguity($ref, $embedding, $distance);", 1,
     'test_cinco_intentos_ambiguos_sin_pin_agotan_el_rostro_aunque_despues_llegue_el_pin_correcto'),
    ('exito-no-limpia', 'app/Services/Workforce/FaceReferenceService.php',
     "        RateLimiter::clear($limiterKey);\n        $ref->forceFill(['last_verified_at' => now()])->saveQuietly();",
     "        $ref->forceFill(['last_verified_at' => now()])->saveQuietly();", 1,
     'test_el_exito_con_pin_limpia_el_contador_del_rostro'),
    ('pin-sin-cara', 'app/Services/Workforce/FaceReferenceService.php',
     "        [$ref, $distance, $limiterKey] = $this->matchOwn($ref, $embedding, $attemptKey, $request);\n\n        // El rostro coincide;",
     "        try {\n            [$ref, $distance, $limiterKey] = $this->matchOwn($ref, $embedding, $attemptKey, $request);\n        } catch (WorkforceException $e) {\n            if ($pin !== null && $ref?->identity_id !== null) {\n                $this->pins->check((int) $ref->identity_id, $pin);\n            }\n            throw $e;\n        }\n\n        // El rostro coincide;", 1,
     'test_un_rostro_que_no_coincide_nunca_llega_a_gastar_intentos_del_pin'),
    ('ignora-heredadas', 'app/Services/Workforce/FaceReferenceService.php',
     "            ->whereKeyNot($ref->getKey())\n            ->when($ref->identity_id !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('identity_id')->orWhere('identity_id', '!=', $ref->identity_id)))",
     "            ->whereKeyNot($ref->getKey())->whereNotNull('consent_version')\n            ->when($ref->identity_id !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('identity_id')->orWhere('identity_id', '!=', $ref->identity_id)))", 1,
     'test_una_plantilla_heredada_sin_identidad_de_otra_persona_tambien_vuelve_ambigua_la_captura'),
    ('login-publico-explica', 'app/Http/Controllers/Api/TrainerAuthController.php',
     "return $e->code_ === 'face_attempts_exceeded' ? $e->render() : $genericFail;", "return $e->render();", 1,
     'test_el_login_facial_publico_ante_un_rostro_ambiguo_da_el_mismo_rechazo_generico_que_un_rostro_ajeno'),
    ('kiosko-sin-estado-pin', 'app/Http/Controllers/Api/Kiosk/KioskEmployeeController.php',
     "            'pin' => ['status' => $this->pins->status((int) $profile->identity->getKey())],\n", "", 1,
     'test_identify_y_roster_dicen_el_estado_del_pin_y_nada_mas'),
    ('historial-motivo-a-todos', 'app/Http/Controllers/Api/Admin/BiometricAdminController.php',
     "$l->event === FaceReferenceService::EVENT_LOOKALIKE_EXCEPTION_GRANTED && ! $canManage", "false", 1,
     'test_el_historial_del_crm_cuenta_el_segundo_factor_sin_decir_con_quien'),
    ('jornada-metodo-face', 'app/Http/Controllers/Api/Kiosk/KioskVerificationController.php',
     "$match->method(), $match->distance", "'face', $match->distance", 1,
     'test_entrar_con_rostro_y_pin_deja_la_jornada_y_su_evento_como_face_pin|test_el_acceso_de_entrenador_en_la_tablet_con_pin_queda_en_la_traza_como_face_pin'),
    ('clase-metodo-face', 'app/Services/Workforce/ClassIdentityVerifier.php',
     "'method' => $match->method()", "'method' => 'face'", 1,
     'test_finalizar_la_clase_tambien_exige_el_pin_y_queda_como_face_pin'),
    ('excepcion-en-ambas-direcciones', 'app/Services/Workforce/LookalikeRegistry.php',
     "            ->where('identity_id', $subjectId)\n            ->whereNull('consumed_at')\n            ->whereNull('revoked_at')\n            ->where('expires_at', '>', now())\n            ->orderByDesc('id')\n            ->get();\n        foreach ($rows as $row) {\n            if (! isset($out[(int) $row->other_identity_id]) && $this->arePaired($subjectId, (int) $row->other_identity_id)) {\n                $out[(int) $row->other_identity_id] = (int) $row->getKey();\n            }\n        }",
     "            ->where(fn ($q) => $q->where('identity_id', $subjectId)->orWhere('other_identity_id', $subjectId))\n            ->whereNull('consumed_at')\n            ->whereNull('revoked_at')\n            ->where('expires_at', '>', now())\n            ->orderByDesc('id')\n            ->get();\n        foreach ($rows as $row) {\n            $o = (int) $row->identity_id === $subjectId ? (int) $row->other_identity_id : (int) $row->identity_id;\n            if (! isset($out[$o]) && $this->arePaired($subjectId, $o)) {\n                $out[$o] = (int) $row->getKey();\n            }\n        }", 1,
     'test_la_excepcion_solo_sirve_a_quien_se_registra_no_a_la_otra_persona_del_par'),
    ('nueva-excepcion-no-anula', 'app/Services/Workforce/LookalikeRegistry.php',
     "->update(['revoked_at' => now()]);", "->get();", 1,
     'test_conceder_otra_excepcion_al_mismo_par_anula_la_anterior'),
    ('caducidad-excepcion-incluye-30', 'app/Services/Workforce/LookalikeRegistry.php',
     "->where('expires_at', '>', now())", "->where('expires_at', '>=', now())", 3,
     'test_a_los_30_minutos_exactos_la_excepcion_ya_no_vale'),
    ('caducidad-excepcion-1s-antes', 'app/Services/Workforce/LookalikeRegistry.php',
     "->where('expires_at', '>', now())", "->where('expires_at', '>', now()->addSecond())", 3,
     'test_la_excepcion_vale_hasta_un_segundo_antes_de_los_30_minutos'),
    ('excepcion-sin-suelo', 'app/Services/Workforce/FaceReferenceService.php',
     "isset($exceptions[$owner]) && $d >= $floor", "isset($exceptions[$owner])", 1,
     'test_el_suelo_de_la_excepcion_entre_069_y_071'),
    ('personal-no-anula-pin', 'app/Services/Workforce/BiometricRetention.php',
     "        $this->revokePin($identity, 'Perfil de personal desactivado');\n", "", 1,
     'test_desactivar_el_perfil_de_personal_anula_su_pin'),
    ('traza-con-distancia-ajena', 'app/Services/Workforce/FaceReferenceService.php',
     "['distance' => round($distance, 4), 'cause' => $ambiguity]", "['distance' => round($distance, 4), 'cause' => $ambiguity, 'nearest' => 0.91]", 1,
     'test_ningun_pin_ni_dato_de_otra_plantilla_sale_en_respuestas_trazas_ni_logs'),
    ('confirmacion-accepted', 'app/Http/Controllers/Api/Admin/BiometricAdminController.php',
     "'subject_verified_in_person' => ['required', $strictTrue],", "'subject_verified_in_person' => ['required', 'accepted'],", 1,
     'test_las_confirmaciones_presenciales_no_aceptan_cadenas_que_parecen_verdaderas'),
]

results = []
for name, rel, old, new, count, target in MUTS:
    path = f'{APP}/{rel}'
    original = open(path, encoding='utf-8').read()
    found = original.count(old)
    if found != count:
        results.append((name, 'MUTACIÓN NO APLICABLE', f'{found} coincidencias (esperaba {count})', target))
        continue
    try:
        open(path, 'w', encoding='utf-8').write(original.replace(old, new))
        proc = subprocess.run(
            [PHP, 'vendor/bin/phpunit', '--no-logging', '--filter', f'/{target}/', TESTFILE],
            cwd=APP, capture_output=True, text=True, env={'APP_BASE_PATH': APP, 'PATH': '/opt/homebrew/bin:/usr/bin:/bin'},
            timeout=600,
        )
        tail = [l for l in proc.stdout.splitlines() if l.startswith(('OK', 'Tests:', 'FAILURES', 'ERRORS'))]
        verdict = 'DETECTADA' if proc.returncode != 0 else 'NO DETECTADA'
        results.append((name, verdict, ' '.join(tail), target))
    finally:
        open(path, 'w', encoding='utf-8').write(original)

for name, verdict, detail, target in results:
    print(f'{verdict:22} {name:32} {detail}')
