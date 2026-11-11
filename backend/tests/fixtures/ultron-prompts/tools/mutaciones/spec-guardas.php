<?php

// Mutaciones contra las enmiendas de la tarea 1 (listas H9/MODELS, zona neutra, llave de prompts, contrato /3).
$OSG = ['sqlite', ['tests/Feature/Outreach/Guards/OutreachStaticGuardsTest.php'], null];
$ISO = ['sqlite', ['tests/Feature/UltronStudio/UltronEngineIsolationTest.php'], null];
$CFG = ['sqlite', ['tests/Unit/Marketing/UltronPublishedConfigTest.php', 'tests/Feature/Marketing/UltronCommitStudioStampTest.php', 'tests/Unit/UltronStudio/StudioPublicationParityTest.php'], null];

$SMT = 'app/Services/UltronStudio/StudioMetaTemplates.php';
$CTL = 'app/Http/Controllers/Crm/UltronStudio/UltronStudioMetaTemplateController.php';
$SYN = 'app/Console/Commands/UltronStudio/MetaTemplatesSyncCommand.php';
$MOD = 'app/Models/UltronStudio/StudioMetaTemplateSubmission.php';
$http = "\n\nuse Illuminate\\Support\\Facades\\Http;";

return [
    'G1' => ['StudioMetaTemplates importa Http (red directa)', [[$SMT, 'namespace App\\Services\\UltronStudio;', 'namespace App\\Services\\UltronStudio;'.$http]], [$OSG]],
    'G2' => ['El controlador de plantillas importa Http', [[$CTL, 'namespace App\\Http\\Controllers\\Crm\\UltronStudio;', 'namespace App\\Http\\Controllers\\Crm\\UltronStudio;'.$http]], [$OSG]],
    'G3' => ['La orden de sincronización importa Http', [[$SYN, 'namespace App\\Console\\Commands\\UltronStudio;', 'namespace App\\Console\\Commands\\UltronStudio;'.$http]], [$OSG]],
    'G4' => ['El modelo de envíos importa Http', [[$MOD, 'namespace App\\Models\\UltronStudio;', 'namespace App\\Models\\UltronStudio;'.$http]], [$OSG]],
    'G5' => ['El modelo de envíos sin $guarded = [\'*\']', [[$MOD, "protected \$guarded = ['*'];", 'protected $guarded = [];']], [$OSG]],
    'G6' => ['Un Log:: de StudioMetaTemplates con la clave «phone»', [[$SMT, "Log::error('ultron_studio.meta_templates.payload_refused', ['submission_uuid'", "Log::error('ultron_studio.meta_templates.payload_refused', ['phone' => 'x', 'submission_uuid'"]], [$OSG]],
    'G7' => ['El cliente neutro de Meta nombra una ruta del Studio', [['app/Services/Meta/Templates/MetaTemplate.php', "final class MetaTemplate\n{\n", "final class MetaTemplate\n{\n    public const STUDIO_PATH = '/api/admin/ultron-studio/templates';\n\n"]], [$ISO]],
    'G7b' => ['G7 SIN la entrada nueva de NEUTRAL_ZONES (contrafactual: ¿la añade algo?)', [
        ['app/Services/Meta/Templates/MetaTemplate.php', "final class MetaTemplate\n{\n", "final class MetaTemplate\n{\n    public const STUDIO_PATH = '/api/admin/ultron-studio/templates';\n\n"],
        ['tests/Feature/UltronStudio/UltronEngineIsolationTest.php', ", 'app/Services/Meta/Templates'];", '];'],
    ], [$ISO]],
    'G8' => ['La llave de los prompts nace en canary', [['config/marketing.php', "env('MARKETING_ULTRON_STUDIO_PROMPTS', 'off')", "env('MARKETING_ULTRON_STUDIO_PROMPTS', 'canary')"]], [$ISO]],
    'G9' => ['El lector acepta también /3', [['app/Services/Marketing/Ultron/UltronPublishedConfig.php', 'public const CONTRACTS = [self::CONTRACT, self::CONTRACT_V2];', "public const CONTRACTS = [self::CONTRACT, self::CONTRACT_V2, 'ultron-engine-config/3'];"]], [$CFG]],
    'G10' => ['Otro fichero del motor nombra al lector (UltronDecideService)', [['app/Services/Marketing/Ultron/UltronDecideService.php', 'namespace App\\Services\\Marketing\\Ultron;', "namespace App\\Services\\Marketing\\Ultron;\n\nuse App\\Services\\Marketing\\Ultron\\UltronPublishedConfig as LectorDelMotor;"]], [$ISO]],
];
