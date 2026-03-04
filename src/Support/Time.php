<?php

declare(strict_types=1);

namespace App\Support;

final class Time
{
    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
