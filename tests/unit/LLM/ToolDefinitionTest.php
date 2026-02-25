<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit\LLM;

use Attractor\LLM\Tools\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class ToolDefinitionTest extends TestCase
{
    public function testToolNameValidationRejectsInvalidCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ToolDefinition('bad-name', 'x', ['type' => 'object']);
    }

    public function testToolSchemaRootMustBeObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ToolDefinition('okname', 'x', ['type' => 'array']);
    }

    public function testValidToolDefinitionIsActiveWhenExecuteProvided(): void
    {
        $tool = new ToolDefinition('sum', 'desc', ['type' => 'object'], fn (): array => ['ok' => true]);
        $this->assertTrue($tool->isActive());
    }
}
