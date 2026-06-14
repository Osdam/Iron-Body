<?php

use App\Http\Middleware\AuthenticateMember;
use App\Http\Middleware\AuthenticateTrainer;
use App\Http\Middleware\EnsureMemberRegistrationToken;
use App\Http\Middleware\EnsureTrainerFeature;
use App\Http\Middleware\EnsureTrainerPermission;
use App\Http\Middleware\VerifyInternalAutomationSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'member.registration.token' => EnsureMemberRegistrationToken::class,
            'auth.member'               => AuthenticateMember::class,
            'auth.trainer'              => AuthenticateTrainer::class,
            'automation.internal'       => VerifyInternalAutomationSignature::class,
            'trainer.can'               => EnsureTrainerPermission::class,
            'trainer.feature'           => EnsureTrainerFeature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Cuerpo de request demasiado grande (p.ej. imagen OCR pesada). En rutas
        // API SIEMPRE respondemos JSON controlado — nunca el HTML 413 de nginx
        // ni un stack trace. El cliente ya comprime; esto es la última defensa.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'ok'      => false,
                    'code'    => 'ocr_image_too_large',
                    'message' => 'La imagen es demasiado pesada. Intenta con una foto más cercana o más clara.',
                ], 413);
            }
            return null;
        });
    })->create();
