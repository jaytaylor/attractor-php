<?php

declare(strict_types=1);

namespace Attractor\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SmokeIntegrationTest extends TestCase
{
    public function testComposerDefinesProviderSmokeGroupCommand(): void
    {
        $composerPath = dirname(__DIR__, 2) . '/composer.json';
        $raw = file_get_contents($composerPath);
        $this->assertIsString($raw);

        /** @var array<string, mixed> $composer */
        $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('scripts', $composer);
        $this->assertArrayHasKey('test:e2e:provider-smoke', $composer['scripts']);
        $this->assertStringContainsString('--group provider-smoke', (string) $composer['scripts']['test:e2e:provider-smoke']);
    }
}
