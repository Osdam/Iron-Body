<?php

namespace App\Services\UltronStudio\Stream;

use App\Support\SseStream;

/** El reloj real: el de {@see SseStream}, pieza a pieza. */
final class SystemStreamClock implements StreamClock
{
    public function now(): float
    {
        return microtime(true);
    }

    public function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    public function aborted(): bool
    {
        return connection_aborted() !== 0;
    }

    public function prepare(int $maxSeconds): void
    {
        @set_time_limit($maxSeconds + 10);
        @ignore_user_abort(false);
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
    }
}
