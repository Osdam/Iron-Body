<?php

namespace Tests\Feature\Outreach\Facts;

use App\Services\Outreach\Core\PolicyLadder;
use App\Services\Outreach\Facts\BatchSnapshot;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Facts\FactNames;
use App\Services\Outreach\Facts\OutreachConsentFacts;
use App\Services\Outreach\Facts\PhoneRef;
use App\Services\Outreach\Facts\SubjectFacts;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Run\Channels\WhatsappTemplateChannel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\Feature\Outreach\Schema\OutreachConsentFixtures;
use Tests\Feature\Outreach\Schema\OutreachSchemaFixtures;
use Tests\TestCase;

/**
 * C9-2 y C9-8 · EL MOTOR LEE EL LIBRO DE AUTORIZACIONES (`outreach_consents`)
 * al decidir, sobre las clases reales: el adaptador {@see OutreachConsentFacts}
 * dentro de la foto de `BatchSnapshotBuilder`, la combinación
 * {@see CoreProjection::consentFor()} y la escalera real (`PolicyLadder`) con
 * la base del fixture de TEST9-a. Las filas del libro, con las de
 * database-specialist (`OutreachConsentFixtures`): pasan sus guardas reales.
 *
 *  - Con el libro vacío (o sin la tabla) nada cambia: todas las claves de C9
 *    salen en false y la combinación es, en todo su dominio, la de antes.
 *  - Gana la negativa: prospecto denegado + socio concedido → denegado; una
 *    retirada posterior → denegado; lo pedido antes de una retirada no revive.
 *  - El número cuenta: cambiado → falta. Cada finalidad por su fila.
 *  - La retirada vale para el NÚMERO (C-2 de la revisión de API): calla a
 *    quien lo comparta mientras siga viva; sin número, solo a su persona.
 *  - La consulta del lector da lo mismo que la de referencia del informe.
 */
class OutreachConsentFactsTest extends TestCase
{
    use FactsWorld;
    use OutreachConsentFixtures;
    use OutreachSchemaFixtures;
    use RefreshDatabase;

    private const NOW = '2026-11-22 16:00:00';

    private const PHONE = '3001110001';

    protected function tearDown(): void
    {
        OutreachConsentFacts::forgetLedger();
        parent::tearDown();
    }

    // ── La combinación, pura ────────────────────────────────────────────────

    /**
     * La regla de ANTES de C9 (CoreProjection::policyInput() en dfcee951, el
     * cierre `$consentimiento` copiado tal cual): es la referencia, no el código
     * que se prueba.
     */
    private static function before(?array $p, string $canal, string $purpose): string
    {
        if ($p === null) {
            return 'missing';
        }
        $negado = ($p['lead.consent_denied'] ?? null) !== false;
        if ($negado) {
            return 'denied';
        }

        return ($p["consent.{$canal}.{$purpose}"] ?? false) === true ? 'granted' : 'missing';
    }

    public function test_sin_c9_la_combinacion_es_la_de_antes_en_todo_su_dominio(): void
    {
        $valores = ['t' => true, 'f' => false, 'x' => null];
        $casos = 0;
        foreach (['whatsapp', 'voice_call'] as $canal) {
            foreach (OutreachConsentFacts::PURPOSES as $p) {
                $this->assertSame(self::before(null, $canal, $p), CoreProjection::consentFor(null, $canal, $p));
                foreach ($valores as $negado) {
                    foreach ($valores as $concede) {
                        foreach ($valores as $dnc) {
                            foreach ($valores as $hermano) {
                                // Las claves de C9 ausentes, o presentes en false (el libro vacío).
                                foreach ([null, false] as $c9) {
                                    $pol = array_filter([
                                        'lead.consent_denied' => $negado, "consent.{$canal}.{$p}" => $concede,
                                        'lead.do_not_contact' => $dnc, 'lead.do_not_contact_sibling' => $hermano,
                                    ], static fn ($v): bool => $v !== null);
                                    if ($c9 === false) {
                                        $pol += array_fill_keys(FactNames::C9_POLICY, false);
                                    }
                                    $this->assertSame(self::before($pol, $canal, $p), CoreProjection::consentFor($pol, $canal, $p), json_encode([$canal, $p, $pol]));
                                    $casos++;
                                }
                            }
                        }
                    }
                }
            }
        }
        $this->assertSame(2 * 3 * 81 * 2, $casos);
    }

    /** La tabla de verdad de la Enmienda C9 (WhatsApp, comercial), fila a fila. */
    public function test_la_tabla_de_verdad_de_la_combinacion(): void
    {
        $limpia = ['lead.consent_denied' => false, 'lead.do_not_contact' => false, 'lead.do_not_contact_sibling' => false, 'consent.whatsapp.commercial' => false]
            + array_fill_keys(FactNames::C9_POLICY, false);
        $con = static fn (array $o): array => array_replace($limpia, $o);
        $filas = [
            '1 prospecto denegado + C9 concedida' => [['lead.consent_denied' => true, 'c9.whatsapp.commercial.granted' => true], 'denied'],
            '2 prospecto concede + C9 retirada' => [['consent.whatsapp.commercial' => true, 'c9.whatsapp.commercial.revoked' => true], 'denied'],
            '3 solo C9 retirada' => [['c9.whatsapp.commercial.revoked' => true], 'denied'],
            '4 prospecto concede, C9 nada' => [['consent.whatsapp.commercial' => true], 'granted'],
            '5 C9 concedida (número de hoy), sin no contactar' => [['c9.whatsapp.commercial.granted' => true], 'granted'],
            '6 C9 concedida + no contactar' => [['c9.whatsapp.commercial.granted' => true, 'lead.do_not_contact' => true], 'missing'],
            '7 C9 concedida + no contactar de un hermano' => [['c9.whatsapp.commercial.granted' => true, 'lead.do_not_contact_sibling' => true], 'missing'],
            '8 C9 concedida, no contactar desconocido' => [['c9.whatsapp.commercial.granted' => true, 'lead.do_not_contact' => null], 'missing'],
            '9 nada' => [[], 'missing'],
            '10 comercial concedida por C9, se pide cobranza' => [['c9.whatsapp.commercial.granted' => true], 'missing', 'collections'],
            '11 comercial concedida por C9, se pide servicio' => [['c9.whatsapp.commercial.granted' => true], 'missing', 'service'],
            '12 cobranza por su propia fila' => [['c9.whatsapp.collections.granted' => true], 'granted', 'collections'],
            '13 C9 en WhatsApp no toca la voz' => [['c9.whatsapp.commercial.granted' => true], 'missing', 'commercial', 'voice_call'],
            '14 una retirada de C9 en WhatsApp no niega la voz' => [['c9.whatsapp.commercial.revoked' => true, 'consent.voice_call.commercial' => true], 'granted', 'commercial', 'voice_call'],
        ];
        foreach ($filas as $nombre => $f) {
            $pol = array_filter($con($f[0]), static fn ($v): bool => $v !== null);
            $this->assertSame($f[1], CoreProjection::consentFor($pol, $f[3] ?? 'whatsapp', $f[2] ?? 'commercial'), $nombre);
        }
    }

    // ── Con el libro vacío, nada cambia ─────────────────────────────────────

    public function test_con_el_libro_vacio_las_claves_de_c9_salen_en_false_y_la_decision_es_la_de_antes(): void
    {
        $claves = $this->mundoVariado();
        $foto = $this->snap($claves, self::NOW, CoreProjection::selectionFor([]));
        foreach ($claves as $clave) {
            $s = $foto->subject($clave);
            $this->assertNotNull($s->policy, $clave);
            foreach (FactNames::C9_POLICY as $k) {
                $this->assertFalse($s->policy[$k], "{$clave} {$k}");
            }
            foreach (OutreachConsentFacts::PURPOSES as $p) {
                $entrada = CoreProjection::policyInput($s, $p, $foto->global);
                $this->assertSame(self::before($s->policy, 'whatsapp', $p), $entrada['consent']['whatsapp'], "{$clave} {$p}");
                $this->assertSame(self::before($s->policy, 'voice_call', $p), $entrada['consent']['voice'], "{$clave} {$p}");
            }
        }
        // Lo que lee el adaptador con el libro vacío: UNA sentencia (la tanda, con la regla de los hermanos dentro), ninguna a las
        // conversaciones. Los números de cada persona (C-2) son filas que la pasada COMPARTE (`FactPass`): en la foto ya las leyó
        // `LeadFacts`, que corre antes; en una pasada en frío, el adaptador las pide una vez y solo esas.
        $pase = $this->pass($claves, self::NOW);
        (new OutreachConsentFacts)->hasLedger();
        $frio = $this->capture(fn () => (new OutreachConsentFacts)->facts($pase));
        $this->assertCount(3, $frio, implode("\n", $frio));
        $this->assertStringContainsString('"marketing_leads"', $frio[0]);
        $this->assertStringContainsString('"members"', $frio[1]);
        $pase = $this->pass($claves, self::NOW);
        $pase->leadRows();
        $pase->memberPhones();
        $sql = $this->capture(fn () => (new OutreachConsentFacts)->facts($pase));
        $this->assertCount(1, $sql, implode("\n", $sql));
        $this->assertStringContainsString('outreach_consents', $sql[0]);
        $this->assertStringContainsString('UNION ALL', $sql[0], 'la regla de los hermanos va en la misma sentencia que la tanda');
        $this->assertSame($sql[0], $frio[2]);
        // La foto con C9 se serializa y se vuelve a leer igual (las claves nuevas son de política y booleanas).
        $this->assertSame($foto->hash(), BatchSnapshot::fromArray(json_decode($foto->canonicalJson(), true))->hash());
    }

    public function test_sin_la_tabla_del_libro_tampoco_cambia_nada_y_no_falla(): void
    {
        $claves = $this->mundoVariado();
        $con = $this->snap($claves, self::NOW, CoreProjection::selectionFor([]));
        Schema::drop('outreach_consents');
        OutreachConsentFacts::forgetLedger();
        $sin = $this->snap($claves, self::NOW, CoreProjection::selectionFor([]));
        $this->assertSame($con->canonicalJson(), $sin->canonicalJson());
    }

    // ── C9-8: las filas del plan, con la escalera real ──────────────────────

    public function test_un_socio_con_c9_concedida_pasa_n1_y_sin_ella_no(): void
    {
        $p = $this->socioConWhatsapp();
        $this->assertSame('missing', $this->consent($p['key']));
        $this->assertContains('no_consent_for_purpose_channel', $this->decide($p['key']));

        $this->resolveConsent($this->requestConsent(['member_id' => $p['member'], 'phone_ref' => $p['ref']]));
        $this->assertSame('granted', $this->consent($p['key']));
        $this->assertSame([], array_values(array_intersect(['consent_denied', 'no_consent_for_purpose_channel', 'unknown_subject', 'no_whatsapp_identity', 'do_not_contact'], $this->decide($p['key']))));
        $this->assertSame(['level' => 'N1', 'status' => 'pass'], $this->decision($p['key'])['levels'][0], 'pasa N1');
        // Por la clave del prospecto es la misma persona (socio manda): también.
        $this->assertSame('granted', $this->consent(SubjectKey::lead($p['lead'])));
    }

    public function test_una_retirada_posterior_niega(): void
    {
        $p = $this->socioConWhatsapp();
        $this->resolveConsent($this->requestConsent(['member_id' => $p['member'], 'phone_ref' => $p['ref']]));
        $this->revokeConsent(['member_id' => $p['member']]);
        $this->assertSame('denied', $this->consent($p['key']));
        $this->assertContains('consent_denied', $this->decide($p['key']));

        // Y vuelve a valer con una petición y una confirmación NUEVAS, posteriores a la retirada.
        $this->resolveConsent($this->requestConsent(['member_id' => $p['member'], 'phone_ref' => $p['ref']]));
        $this->assertSame('granted', $this->consent($p['key']));
    }

    public function test_prospecto_denegado_y_socio_concedido_gana_la_negativa(): void
    {
        $p = $this->socioConWhatsapp(['consent_status' => 'denied']);
        $this->resolveConsent($this->requestConsent(['member_id' => $p['member'], 'phone_ref' => $p['ref']]));
        $s = $this->foto([$p['key']])->subject($p['key']);
        $this->assertTrue($s->policy['c9.whatsapp.commercial.granted'], 'el libro sí la concede');
        $this->assertSame('denied', CoreProjection::policyInput($s, 'commercial', null)['consent']['whatsapp']);
        $this->assertContains('consent_denied', $this->decide($p['key']));
    }

    public function test_el_prospecto_concede_y_una_retirada_de_c9_niega(): void
    {
        $p = $this->socioConWhatsapp(['consent_status' => 'granted']);
        $this->assertSame('granted', $this->consent($p['key']));
        $this->revokeConsent(['member_id' => $p['member']]);
        $this->assertSame('denied', $this->consent($p['key']));
        $this->assertContains('consent_denied', $this->decide($p['key']));
    }

    public function test_el_telefono_cambiado_deja_la_autorizacion_sin_efecto(): void
    {
        $p = $this->socioConWhatsapp();
        $this->resolveConsent($this->requestConsent(['member_id' => $p['member'], 'phone_ref' => $p['ref']]));
        $this->assertSame('granted', $this->consent($p['key']));
        DB::table('marketing_leads')->where('id', $p['lead'])->update(['phone' => '3009990009']);
        $s = $this->foto([$p['key']])->subject($p['key']);
        $this->assertFalse($s->policy['c9.whatsapp.commercial.granted']);
        $this->assertFalse($s->policy['c9.whatsapp.commercial.revoked'], 'ni concedida ni retirada: falta');
        $this->assertSame('missing', CoreProjection::policyInput($s, 'commercial', null)['consent']['whatsapp']);
        $this->assertContains('no_consent_for_purpose_channel', $this->decide($p['key']));
    }

    public function test_la_comercial_no_vale_para_cobranza_ni_para_servicio(): void
    {
        $p = $this->socioConWhatsapp();
        $this->resolveConsent($this->requestConsent(['member_id' => $p['member'], 'phone_ref' => $p['ref']]));
        $this->assertSame('granted', $this->consent($p['key'], 'commercial'));
        $this->assertSame('missing', $this->consent($p['key'], 'collections'));
        $this->assertSame('missing', $this->consent($p['key'], 'service'));
        $this->assertContains('no_consent_for_purpose_channel', $this->decide($p['key'], 'collections'));
        // La de cobranza, por su propia fila.
        $this->resolveConsent($this->requestConsent(['member_id' => $p['member'], 'phone_ref' => $p['ref'], 'purpose' => 'collections']));
        $this->assertSame('granted', $this->consent($p['key'], 'collections'));
    }

    public function test_lo_pedido_antes_de_una_retirada_en_la_otra_clave_no_revive_al_confirmarse(): void
    {
        $p = $this->socioConWhatsapp();
        // La petición, por la clave del prospecto (antes de enlazarlo al socio); la retirada, por la del socio.
        $peticion = $this->requestConsent(['member_id' => null, 'lead_id' => $p['lead'], 'phone_ref' => $p['ref']]);
        $this->revokeConsent(['member_id' => $p['member']]);
        // La base no puede verlo (otra clave): la confirmación entra.
        $this->resolveConsent($peticion);
        $this->assertSame('denied', $this->consent($p['key']), 'la confirmación cuenta en el lugar de su petición, anterior a la retirada');
    }

    public function test_lo_redactado_no_casa(): void
    {
        $p = $this->socioConWhatsapp();
        $this->resolveConsent($this->requestConsent(['member_id' => $p['member'], 'phone_ref' => $p['ref']]));
        $this->revokeConsent(['member_id' => $p['member'], 'purpose' => 'service']);
        $this->assertSame('granted', $this->consent($p['key']));
        $this->assertSame(3, $this->redactSubject($p['key']));
        $s = $this->foto([$p['key']])->subject($p['key']);
        foreach (FactNames::C9_POLICY as $k) {
            $this->assertFalse($s->policy[$k], $k);
        }
    }

    public function test_sin_identidad_de_whatsapp_la_autorizacion_no_concede_nada(): void
    {
        $socio = $this->socio([], ['phone' => self::PHONE]);
        $clave = SubjectKey::member($socio['member']);
        $this->resolveConsent($this->requestConsent(['member_id' => $socio['member'], 'phone_ref' => PhoneRef::fromRaw(self::PHONE)]));
        $s = $this->foto([$clave])->subject($clave);
        $this->assertFalse($s->fact('subject.has_whatsapp_identity'));
        $this->assertFalse($s->policy['c9.whatsapp.commercial.granted'], 'sin número de destino por WhatsApp no hay con qué comparar');
        $this->assertSame('missing', $this->consent($clave));
        // La escalera corta en el primer nivel que niega (N1): sin autorización, antes de mirar la identidad (N4).
        $this->assertContains('no_consent_for_purpose_channel', $this->decide($clave));
    }

    public function test_no_contactar_gana_a_una_autorizacion_de_c9(): void
    {
        $p = $this->socioConWhatsapp(['do_not_contact' => true]);
        $this->resolveConsent($this->requestConsent(['member_id' => $p['member'], 'phone_ref' => $p['ref']]));
        $this->assertSame('missing', $this->consent($p['key']));
        $this->assertContains('do_not_contact', $this->decide($p['key']));
    }

    // ── C-2: la retirada vale para el NÚMERO (la regla de los hermanos) ──────

    /**
     * La sonda P10 de la revisión de API, en el motor: un socio con su
     * prospecto de WhatsApp (número X) y un prospecto SUELTO con X en otro
     * formato, su conversación y su propia concesión. Retirar la comercial del
     * socio calla también al suelto; deja de callarlo cuando la persona de la
     * retirada vuelve a autorizar (petición y confirmación posteriores).
     */
    public function test_una_retirada_calla_tambien_a_quien_comparte_el_numero_mientras_siga_viva(): void
    {
        $p = $this->socioConWhatsapp();
        $suelto = $this->lead(['phone' => '+57 300 111 0001', 'consent_status' => 'granted']);
        $this->conversation($suelto, ['last_inbound_at' => self::utc('2026-11-01 15:00:00')]);
        $clave = SubjectKey::lead($suelto);
        $this->assertSame('granted', $this->consent($clave), 'antes, el suelto concede por sí mismo');

        $this->revokeConsent(['member_id' => $p['member'], 'phone_ref' => $p['ref'], 'actor_type' => 'admin', 'recorded_by_admin_id' => 1, 'recorded_by_name' => 'Ana']);
        $this->assertSame('denied', $this->consent($p['key']));
        $s = $this->foto([$clave])->subject($clave);
        $this->assertTrue($s->policy['c9.whatsapp.commercial.revoked'], 'por el número');
        $this->assertFalse($s->policy['c9.whatsapp.commercial.granted']);
        $this->assertFalse($s->policy['c9.whatsapp.service.revoked'], 'solo la finalidad retirada');
        $this->assertSame('denied', $this->consent($clave));
        $this->assertContains('consent_denied', $this->decide($clave));
        // En el mismo lote, los dos: la retirada propia del socio no vuelve a entrar por su número.
        $lote = $this->foto([$p['key'], $clave]);
        $this->assertSame(['denied', 'denied'], [CoreProjection::policyInput($lote->subject($p['key']), 'commercial', null)['consent']['whatsapp'],
            CoreProjection::policyInput($lote->subject($clave), 'commercial', null)['consent']['whatsapp']]);

        // La persona de la retirada autoriza OTRO número (el suyo cambió): para ella vale, pero el número retirado sigue callado.
        $this->resolveConsent($this->requestConsent(['member_id' => $p['member'], 'phone_ref' => PhoneRef::fromRaw('3001110008')]));
        DB::table('marketing_leads')->where('id', $p['lead'])->update(['phone' => '3001110008']);
        $this->assertSame('granted', $this->consent($p['key']));
        $this->assertSame('denied', $this->consent($clave), 'autorizar otro número no levanta la retirada de este');
        // Vuelve a autorizar ESE número: la retirada ya no está viva y el suelto vuelve a su propia concesión.
        DB::table('marketing_leads')->where('id', $p['lead'])->update(['phone' => self::PHONE]);
        $this->resolveConsent($this->requestConsent(['member_id' => $p['member'], 'phone_ref' => $p['ref']]));
        $this->assertSame('granted', $this->consent($p['key']));
        $this->assertSame('granted', $this->consent($clave));
    }

    public function test_tambien_al_reves_y_tambien_a_una_autorizacion_de_c9(): void
    {
        $p = $this->socioConWhatsapp();
        $this->resolveConsent($this->requestConsent(['member_id' => $p['member'], 'phone_ref' => $p['ref']]));
        $this->assertSame('granted', $this->consent($p['key']));
        // Un prospecto suelto con el mismo número retira (su retirada, por su clave `l:`): el socio deja de poder.
        $suelto = $this->lead(['phone' => self::PHONE, 'consent_status' => 'unknown']);
        $this->revokeConsent(['member_id' => null, 'lead_id' => $suelto, 'phone_ref' => $p['ref']]);
        $s = $this->foto([$p['key']])->subject($p['key']);
        $this->assertSame([true, false], [$s->policy['c9.whatsapp.commercial.revoked'], $s->policy['c9.whatsapp.commercial.granted']], 'gana la negativa, nunca los dos');
        $this->assertSame('denied', $this->consent($p['key']));
        // Por el número de su ficha de socio también (la regla de los hermanos mira todos sus números, como «no contactar»).
        $otro = $this->socio([], ['phone' => '3001110009']);
        $this->revokeConsent(['member_id' => null, 'lead_id' => $this->lead(['phone' => '3001110009']), 'phone_ref' => PhoneRef::fromRaw('3001110009')]);
        $this->assertTrue($this->foto([SubjectKey::member($otro['member'])])->subject(SubjectKey::member($otro['member']))->policy['c9.whatsapp.commercial.revoked']);
    }

    public function test_una_retirada_sin_numero_no_calla_a_nadie_mas(): void
    {
        // La persona no tenía número al retirar; después tiene un prospecto con X, que comparte con un suelto.
        $socio = $this->socio([], ['phone' => null]);
        $this->revokeConsent(['member_id' => $socio['member']]);
        $suyo = $this->lead(['member_id' => $socio['member'], 'phone' => self::PHONE, 'consent_status' => 'granted']);
        $this->conversation($suyo, ['last_inbound_at' => self::utc('2026-11-01 15:00:00')]);
        $suelto = $this->lead(['phone' => self::PHONE, 'consent_status' => 'granted']);
        $this->conversation($suelto, ['last_inbound_at' => self::utc('2026-11-01 15:00:00')]);
        $this->assertSame('denied', $this->consent(SubjectKey::member($socio['member'])), 'a ella sí');
        $this->assertSame('granted', $this->consent(SubjectKey::lead($suelto)), 'la retirada es de la persona, no de un número que no tenía');
    }

    // ── La consulta y el canal ──────────────────────────────────────────────

    public function test_la_consulta_del_lector_da_lo_mismo_que_la_de_referencia(): void
    {
        $refA = $this->phoneRefOf('A');
        // Persona 1 (m:7001 y l:9001): retirada en la otra clave posterior a la petición; servicio concedido.
        $pet = $this->requestConsent(['member_id' => null, 'lead_id' => 9001, 'phone_ref' => $refA]);
        $this->revokeConsent(['member_id' => 7001]);
        $this->resolveConsent($pet);
        $this->resolveConsent($this->requestConsent(['member_id' => 7001, 'purpose' => 'service', 'phone_ref' => $refA]));
        // Persona 2 (m:7002): concedida, retirada y concedida otra vez; cobranza rechazada (no cuenta).
        $this->resolveConsent($this->requestConsent(['member_id' => 7002, 'phone_ref' => $refA]));
        $this->revokeConsent(['member_id' => 7002]);
        $this->resolveConsent($this->requestConsent(['member_id' => 7002, 'phone_ref' => $this->phoneRefOf('B')]));
        $this->resolveConsent($this->requestConsent(['member_id' => 7002, 'purpose' => 'collections']), 'rejected');
        // Persona 3 (m:7003): solo una petición pendiente (no cuenta) y una retirada del sistema.
        $this->requestConsent(['member_id' => 7003]);
        $this->revokeConsent(['member_id' => 7003, 'purpose' => 'collections']);
        // Persona 4 (m:7004): redactada.
        $this->resolveConsent($this->requestConsent(['member_id' => 7004, 'phone_ref' => $refA]));
        $this->redactSubject('m:7004');

        $personas = ['p1' => ['m:7001', 'l:9001'], 'p2' => ['m:7002'], 'p3' => ['m:7003'], 'p4' => ['m:7004'], 'p5' => ['m:7999']];
        $referencia = $this->consentBatch($personas);
        $lector = OutreachConsentFacts::batch($personas);
        $this->assertSame($referencia, $lector);
        $this->assertSame(['commercial' => 'revoked', 'service' => 'granted'], array_map(fn ($g) => $g['kind'], $lector['p1']));
        $this->assertSame(['collections' => 'revoked'], array_map(fn ($g) => $g['kind'], $lector['p3']));
        $this->assertSame('granted', $lector['p2']['commercial']['kind']);
        $this->assertSame($this->phoneRefOf('B'), $lector['p2']['commercial']['phone_ref']);
        $this->assertArrayNotHasKey('p4', $lector);
        $this->assertArrayNotHasKey('p5', $lector);

        // En tramos (más claves que el tope por sentencia), lo mismo.
        $muchas = [];
        for ($i = 1; $i <= OutreachConsentFacts::MAX_KEYS_PER_QUERY + 5; $i++) {
            $muchas['q'.$i] = ['m:'.(100000 + $i)];
        }
        $muchas['p2'] = ['m:7002'];
        $sql = $this->capture(fn () => $this->assertSame(['p2' => $lector['p2']], OutreachConsentFacts::batch($muchas)));
        $this->assertCount(2, $sql);
        // Con los números (C-2), el tramo cuenta claves Y números: 600 personas con una clave y un número son dos sentencias, y
        // la retirada viva de m:7003 (con su número) llega a quien lo comparte, esté en el tramo que esté.
        $this->revokeConsent(['member_id' => 7003, 'phone_ref' => $refA]);
        $pocas = [];
        $numeros = [];
        for ($i = 1; $i <= 600; $i++) {
            $pocas['q'.$i] = ['l:'.(200000 + $i)];
            $numeros['q'.$i] = [$this->phoneRefOf('Q'.$i)];
        }
        $numeros['q600'] = [$refA];
        $sql = $this->capture(function () use ($pocas, $numeros): void {
            $this->assertSame(['own' => [], 'byNumber' => ['q600' => ['commercial' => true]]], OutreachConsentFacts::read($pocas, $numeros));
        });
        $this->assertCount(2, $sql);
        // La propia retirada de una persona (m:7003, con el número A) no vuelve a entrar por su número: la juzga la tanda.
        $propia = OutreachConsentFacts::read(['p3' => ['m:7003']], ['p3' => [$refA]]);
        $this->assertSame(['collections' => 'revoked', 'commercial' => 'revoked'], array_map(fn ($g) => $g['kind'], $propia['own']['p3']));
        $this->assertSame([], $propia['byNumber']);
    }

    public function test_el_canal_de_plantillas_comprueba_con_la_misma_combinacion_al_despachar(): void
    {
        $estado = fn (string $clave, string $finalidad = 'commercial'): ?string => (new ReflectionMethod(WhatsappTemplateChannel::class, 'personState'))
            ->invoke(new WhatsappTemplateChannel, $clave, $finalidad, self::at(self::NOW));

        $solo = $this->socioConWhatsapp();
        $this->assertSame('no_consent_for_purpose_channel', $estado($solo['key']));
        $this->resolveConsent($this->requestConsent(['member_id' => $solo['member'], 'phone_ref' => $solo['ref']]));
        $this->assertNull($estado($solo['key']), 'C9 concede: el canal escribe');
        $this->assertSame('no_consent_for_purpose_channel', $estado($solo['key'], 'collections'));

        $concede = $this->socioConWhatsapp(['consent_status' => 'granted'], '3001110002');
        $this->assertNull($estado($concede['key']));
        $this->revokeConsent(['member_id' => $concede['member']]);
        $this->assertSame('no_consent_for_purpose_channel', $estado($concede['key']), 'una retirada de C9 gana también al despachar');
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * Un socio enlazado a un prospecto con conversación de WhatsApp (el único
     * caso al que C9 ayuda hoy, sin la identidad de socios de C9-5).
     *
     * @param  array<string, mixed>  $lead
     * @return array{member: int, lead: int, key: string, ref: string}
     */
    private function socioConWhatsapp(array $lead = [], string $phone = self::PHONE): array
    {
        $s = $this->socio();
        $l = $this->lead(array_merge(['member_id' => $s['member'], 'phone' => $phone, 'consent_status' => 'unknown'], $lead));
        $this->conversation($l, ['last_inbound_at' => self::utc('2026-11-01 15:00:00')]);

        return ['member' => $s['member'], 'lead' => $l, 'key' => SubjectKey::member($s['member']), 'ref' => (string) PhoneRef::fromRaw($phone)];
    }

    /** @return list<string> */
    private function mundoVariado(): array
    {
        $claves = [];
        $claves[] = $this->socioConWhatsapp()['key'];
        $claves[] = $this->socioConWhatsapp(['consent_status' => 'granted'], '3001110003')['key'];
        $claves[] = $this->socioConWhatsapp(['consent_status' => 'denied'], '3001110004')['key'];
        $claves[] = $this->socioConWhatsapp(['do_not_contact' => true], '3001110005')['key'];
        $claves[] = SubjectKey::member($this->socio([], ['phone' => '3001110006'])['member']);
        $suelto = $this->lead(['phone' => '3001110007']);
        $this->conversation($suelto, ['last_inbound_at' => self::utc('2026-11-22 15:00:00')]);
        $claves[] = SubjectKey::lead($suelto);
        $claves[] = 'm:999999';

        return $claves;
    }

    /** @param  list<string>  $claves */
    private function foto(array $claves): BatchSnapshot
    {
        return $this->snap($claves, self::NOW, CoreProjection::selectionFor([]));
    }

    private function consent(string $clave, string $finalidad = 'commercial'): string
    {
        $s = $this->foto([$clave])->subject($clave);

        return CoreProjection::policyInput($s, $finalidad, null)['consent']['whatsapp'];
    }

    /** @return list<string> los motivos de la escalera real para esta persona, por WhatsApp asistido */
    private function decide(string $clave, string $finalidad = 'commercial'): array
    {
        return $this->decision($clave, $finalidad)['reasons'];
    }

    /** @return array<string, mixed> la decisión entera de la escalera real (la base del fixture de TEST9-a + la persona) */
    private function decision(string $clave, string $finalidad = 'commercial'): array
    {
        $base = json_decode((string) file_get_contents(base_path('tests/fixtures/outreach/policy-ladder-cases.json')), true)['baseInput'];
        $foto = $this->foto([$clave]);
        $s = $foto->subject($clave);
        $this->assertInstanceOf(SubjectFacts::class, $s);

        return PolicyLadder::decide(array_replace_recursive($base, ['purpose' => $finalidad], CoreProjection::policyInput($s, $finalidad, $foto->global)));
    }

    /** @return list<string> */
    private function capture(\Closure $correr): array
    {
        $sql = [];
        DB::listen(function (QueryExecuted $q) use (&$sql): void {
            $sql[] = $q->sql;
        });
        $correr();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $sql;
    }
}
