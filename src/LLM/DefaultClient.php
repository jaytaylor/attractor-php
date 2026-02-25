<?php

declare(strict_types=1);

namespace Attractor\LLM;

final class DefaultClient
{
    private static ?Client $client = null;

    public static function set(Client $client): void
    {
        self::$client = $client;
    }

    public static function get(): Client
    {
        self::$client ??= Client::fromEnv();

        return self::$client;
    }
}
