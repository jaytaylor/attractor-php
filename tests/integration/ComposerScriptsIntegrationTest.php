<?php

declare(strict_types=1);

namespace Attractor\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class ComposerScriptsIntegrationTest extends TestCase
{
    public function testComposerWiresProviderE2eGroupToTestE2e(): void
    {
        $composerPath = dirname(__DIR__, 2) . '/composer.json';
        $raw = file_get_contents($composerPath);
        $this->assertIsString($raw);

        /** @var array<string, mixed> $composer */
        $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('scripts', $composer);

        $this->assertArrayHasKey('test', $composer['scripts']);
        $this->assertArrayHasKey('test:e2e', $composer['scripts']);
        $this->assertArrayHasKey('test:e2e:provider', $composer['scripts']);

        $this->assertStringContainsString('--exclude-group provider-e2e', (string) $composer['scripts']['test']);
        $this->assertStringNotContainsString('--exclude-group provider-e2e', (string) $composer['scripts']['test:e2e']);
        $this->assertStringContainsString('--group provider-e2e', (string) $composer['scripts']['test:e2e:provider']);
    }
}
