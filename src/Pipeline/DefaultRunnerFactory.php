<?php

declare(strict_types=1);

namespace Attractor\Pipeline;

use Attractor\Pipeline\Backends\EchoCodergenBackend;
use Attractor\Pipeline\Handlers\CodergenHandler;
use Attractor\Pipeline\Handlers\ConditionalHandler;
use Attractor\Pipeline\Handlers\ExitHandler;
use Attractor\Pipeline\Handlers\FanInHandler;
use Attractor\Pipeline\Handlers\ManagerLoopHandler;
use Attractor\Pipeline\Handlers\ParallelHandler;
use Attractor\Pipeline\Handlers\StartHandler;
use Attractor\Pipeline\Handlers\ToolHandler;
use Attractor\Pipeline\Handlers\WaitHumanHandler;
use Attractor\Pipeline\Human\AutoApproveInterviewer;
use Attractor\Pipeline\Transforms\VariableExpansionTransform;
use Attractor\Pipeline\Validation\Validator;

final class DefaultRunnerFactory
{
    public static function make(?CodergenBackend $backend = null, ?Interviewer $interviewer = null): Runner
    {
        $backend ??= new EchoCodergenBackend();
        $interviewer ??= new AutoApproveInterviewer();

        $handlers = new HandlerRegistry();
        $handlers->register('start', new StartHandler());
        $handlers->register('exit', new ExitHandler());
        $handlers->register('codergen', new CodergenHandler($backend));
        $handlers->register('wait.human', new WaitHumanHandler($interviewer));
        $handlers->register('conditional', new ConditionalHandler());
        $handlers->register('parallel', new ParallelHandler());
        $handlers->register('fan.in', new FanInHandler());
        $handlers->register('tool', new ToolHandler());
        $handlers->register('manager.loop', new ManagerLoopHandler());

        $transforms = new TransformRegistry();
        $transforms->register(new VariableExpansionTransform());

        return new Runner($handlers, $transforms, new Validator(), $interviewer);
    }
}
