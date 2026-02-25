<?php

declare(strict_types=1);

namespace Attractor\Tests\Unit\Pipeline;

use Attractor\Pipeline\Engine\ConditionEvaluator;
use Attractor\Pipeline\Runtime\Context;
use Attractor\Pipeline\Runtime\Outcome;
use PHPUnit\Framework\TestCase;

final class ConditionEvaluatorTest extends TestCase
{
    public function testOperatorsAndVariables(): void
    {
        $ctx = new Context(['stage' => 'review']);
        $outcome = Outcome::success('ok', 'approve');

        $this->assertTrue(ConditionEvaluator::evaluate('', $outcome, $ctx));
        $this->assertTrue(ConditionEvaluator::evaluate('outcome = SUCCESS', $outcome, $ctx));
        $this->assertTrue(ConditionEvaluator::evaluate('preferred_label = approve && context.stage = review', $outcome, $ctx));
        $this->assertTrue(ConditionEvaluator::evaluate('context.missing = ""', $outcome, $ctx));
        $this->assertFalse(ConditionEvaluator::evaluate('outcome != SUCCESS', $outcome, $ctx));
    }
}
