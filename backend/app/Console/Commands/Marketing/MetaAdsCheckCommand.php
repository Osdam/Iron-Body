<?php

namespace App\Console\Commands\Marketing;

use App\Services\Marketing\Meta\MetaAdsApiClient;
use App\Services\Marketing\Meta\MetaAdsApiException;
use Illuminate\Console\Command;

/**
 * Antes de sincronizar nada: ¿el token configurado lee la cuenta que debe?
 *
 * Pregunta a Meta de quién es el token y qué permisos tiene concedidos
 * (`/me`, `/me/permissions`, con el token solo en la cabecera) y qué cuenta
 * publicitaria ve (id, nombre, moneda, zona horaria, estado), y lo compara con
 * lo esperado. Solo lee; no escribe en Meta ni en la base. El token no se
 * imprime nunca: solo sus permisos.
 *
 * Falla (código distinto de 0) si el token no vale, si no tiene `ads_read`, si
 * la cuenta no es la esperada o si parte los días en otra zona que el CRM: en
 * cualquiera de esos casos sincronizar daría cifras de otra cuenta o corridas
 * un día. Falla también si el token tiene CUALQUIER otro permiso: la regla es un
 * token solo con `ads_read`, y uno que además envía mensajes de WhatsApp o
 * administra el negocio no debe vivir como credencial de lectura de anuncios.
 * Una moneda distinta de COP o una cuenta no activa se avisan.
 */
class MetaAdsCheckCommand extends Command
{
    protected $signature = 'marketing:meta-ads-check
        {--expect-account= : Id esperado de la cuenta publicitaria (sin act_). Por defecto, META_AD_ACCOUNT_ID}
        {--expect-name= : Nombre esperado de la cuenta, como en el Administrador de anuncios}';

    protected $description = 'Comprueba sin sincronizar que el token de Meta Ads lee la cuenta esperada: id, nombre, moneda, zona horaria, estado y permisos.';

    /** Estados de cuenta de Meta (`account_status`). */
    private const ACCOUNT_STATUSES = [
        1 => 'ACTIVE', 2 => 'DISABLED', 3 => 'UNSETTLED', 7 => 'PENDING_RISK_REVIEW', 8 => 'PENDING_SETTLEMENT',
        9 => 'IN_GRACE_PERIOD', 100 => 'PENDING_CLOSURE', 101 => 'CLOSED', 201 => 'ANY_ACTIVE', 202 => 'ANY_CLOSED',
    ];

    /**
     * Lo único que puede tener el token: `ads_read`, y `public_profile`, que Meta
     * concede a todo token y no da acceso a nada del negocio. Es una lista
     * blanca: cualquier otro permiso sobra, también los que no sabemos nombrar.
     */
    private const ALLOWED_SCOPES = ['ads_read', 'public_profile'];

    public function handle(MetaAdsApiClient $client): int
    {
        $problem = $client->configurationProblem();
        if ($problem !== null) {
            $this->error(MetaAdsApiClient::problemMessage($problem));
            $this->line('META_TOKEN_REQUIRED='.($problem === 'missing_access_token' ? 'YES' : 'NO'));

            return self::FAILURE;
        }

        $expectedAccount = trim((string) ($this->option('expect-account') ?: $client->accountId()));
        $expectedAccount = str_starts_with($expectedAccount, 'act_') ? substr($expectedAccount, 4) : $expectedAccount;
        $expectedName = trim((string) $this->option('expect-name'));

        try {
            $token = $client->tokenInfo();
            $account = $client->account();
        } catch (MetaAdsApiException $e) {
            // El mensaje ya viene saneado del cliente: sin token ni ids completos.
            $this->error("Meta no contestó a la comprobación ({$e->category}): {$e->getMessage()}");

            return self::FAILURE;
        }

        $scopes = $token['scopes'];
        // Solo cuenta `ads_read`: `ads_management` también lee, pero escribe.
        $adsRead = in_array('ads_read', $scopes, true);
        $extra = array_values(array_diff($scopes, self::ALLOWED_SCOPES));

        $id = (string) ($account['account_id'] ?? preg_replace('/^act_/', '', (string) ($account['id'] ?? '')));
        $name = trim((string) ($account['name'] ?? ''));
        $tz = (string) ($account['timezone_name'] ?? '');
        $crmTz = (string) config('caja.timezone', 'America/Bogota');
        $statusCode = is_numeric($account['account_status'] ?? null) ? (int) $account['account_status'] : null;
        $status = $statusCode !== null ? (self::ACCOUNT_STATUSES[$statusCode] ?? (string) $statusCode) : '—';
        $currency = strtoupper((string) ($account['currency'] ?? ''));

        $checks = [
            // Un token inválido o caducado no llega hasta aquí: Meta contesta 190 y sale arriba como fallo.
            ['Token válido', $token['id'] !== null, 'usuario: '.($token['name'] ?? '—').' ('.($token['id'] ?? '—').')', true],
            ['Permiso ads_read', $adsRead, 'permisos: '.($scopes === [] ? '—' : implode(', ', $scopes)), true],
            ['Solo ads_read', $extra === [], $extra === [] ? 'solo lectura' : 'tiene además: '.implode(', ', $extra).' (quítalos del usuario de sistema)', true],
            ['Cuenta esperada', $id === $expectedAccount, "Meta: {$id} · esperada: {$expectedAccount}", true],
            ['Nombre esperado', $expectedName === '' || mb_strtolower($name) === mb_strtolower($expectedName), "Meta: «{$name}»".($expectedName !== '' ? " · esperado: «{$expectedName}»" : ' (sin nombre esperado)'), true],
            ['Zona horaria del CRM', strcasecmp($tz, $crmTz) === 0, "cuenta: {$tz} (UTC".($account['timezone_offset_hours_utc'] ?? '?').") · CRM: {$crmTz}", true],
            ['Zona configurada', strcasecmp($tz, $client->timezone()) === 0, 'META_AD_ACCOUNT_TIMEZONE: '.$client->timezone(), true],
            ['Moneda COP', $currency === 'COP', "moneda: {$currency}", false],
            ['Cuenta activa', $statusCode === 1, "estado: {$status}", false],
        ];

        $this->table(
            ['comprobación', 'resultado', 'detalle'],
            array_map(fn (array $c): array => [$c[0], $c[1] ? 'OK' : ($c[3] ? 'FALLA' : 'AVISO'), $c[2]], $checks),
        );

        $this->line('META_ACCOUNT_ID='.$id);
        $this->line('META_ACCOUNT_NAME='.$name);
        $this->line('META_ACCOUNT_TIMEZONE='.$tz);
        $this->line('META_ACCOUNT_CURRENCY='.$currency);
        $this->line('TOKEN_SCOPES_OK='.($adsRead && $extra === [] ? 'YES' : 'NO'.($extra !== [] ? ' (sobran: '.implode(', ', $extra).')' : '')));
        $this->line('ADS_READ='.($adsRead ? 'YES' : 'NO'));

        $blocking = array_filter($checks, fn (array $c): bool => $c[3] && ! $c[1]);
        if ($blocking !== []) {
            $this->error('No se debe sincronizar: '.implode(', ', array_map(fn (array $c): string => $c[0], $blocking)).'.');

            return self::FAILURE;
        }

        $this->info('La cuenta es la esperada y el token puede leerla.');

        return self::SUCCESS;
    }
}
