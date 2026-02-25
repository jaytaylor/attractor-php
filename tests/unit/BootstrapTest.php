<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit;

use Attractor\Bootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Bootstrap::class)]
final class BootstrapTest extends TestCase
{
    public function testVersionReturnsSemverLikeString(): void
    {
        $this->assertMatchesRegularExpression('/^\\d+\\.\\d+\\.\\d+$/', Bootstrap::version());
    }
}
