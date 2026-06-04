<?php

namespace Tests\Feature;

use App\Services\OtpService;
use App\Services\Sms\DevSmsSender;
use App\Services\Sms\SmsSenderFactory;
use App\Services\Sms\TwilioSmsSender;
use Tests\TestCase;

/**
 * Endurecimiento OTP para producción (Bloque 7): el driver `dev` no se permite
 * en producción y el código nunca se expone en producción.
 */
class OtpProductionGuardTest extends TestCase
{
    public function test_dev_driver_forbidden_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['otp.driver' => 'dev']);

        $this->expectException(\RuntimeException::class);
        SmsSenderFactory::make();
    }

    public function test_unknown_driver_forbidden_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['otp.driver' => 'cualquier-cosa']);

        $this->expectException(\RuntimeException::class);
        SmsSenderFactory::make();
    }

    public function test_twilio_driver_allowed_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['otp.driver' => 'twilio']);

        $this->assertInstanceOf(TwilioSmsSender::class, SmsSenderFactory::make());
    }

    public function test_dev_driver_allowed_outside_production(): void
    {
        config(['otp.driver' => 'dev']); // entorno testing

        $this->assertInstanceOf(DevSmsSender::class, SmsSenderFactory::make());
    }

    public function test_expose_code_disabled_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['otp.expose_code' => true]);

        $this->assertFalse(app(OtpService::class)->exposeCode());
    }
}
