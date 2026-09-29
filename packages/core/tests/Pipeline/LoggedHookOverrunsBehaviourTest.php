<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Adapter\LoggedHookOverruns;
use Cbox\Cms\Core\Pipeline\Domain\Dto\HookOverrun;
use Cbox\Cms\Core\Pipeline\Domain\HookOverruns;
use Cbox\Cms\Core\Pipeline\Domain\OverrunKind;
use Cbox\Cms\Core\Tests\Postgres\Strictness\RecordingLogger;
use Override;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * HookOverrunsBehaviour against the log the application records overruns in: each record is a
 * warning named hook_budget_exceeded, and its context holds the overrun, which the test reads back.
 */
final class LoggedHookOverrunsBehaviourTest extends TestCase
{
    use HookOverrunsBehaviour;

    private ?RecordingLogger $logger = null;

    #[Override]
    protected function overruns(): HookOverruns
    {
        $this->logger = new RecordingLogger;

        return new LoggedHookOverruns($this->logger);
    }

    #[Override]
    protected function recorded(HookOverruns $overruns): array
    {
        return array_map(static function (array $record): HookOverrun {
            [$level, $message, $context] = $record;
            Assert::assertSame(LogLevel::WARNING, $level);
            Assert::assertSame('hook_budget_exceeded', $message);
            Assert::assertIsString($context['command']);
            Assert::assertIsInt($context['version']);
            Assert::assertIsString($context['hook']);
            Assert::assertTrue(class_exists($context['hook']));
            Assert::assertIsString($context['package']);
            Assert::assertIsString($context['phase']);
            Assert::assertIsString($context['budget']);
            Assert::assertIsInt($context['budget_ms']);
            Assert::assertIsInt($context['elapsed_ns']);
            Assert::assertIsInt($context['spent_ns']);

            $overrun = new HookOverrun(
                new CommandName($context['command']),
                $context['version'],
                $context['hook'],
                $context['package'],
                Phase::from($context['phase']),
                OverrunKind::from($context['budget']),
                $context['budget_ms'],
                $context['elapsed_ns'],
                $context['spent_ns'],
            );
            Assert::assertSame($overrun->describe(), $context['description']);

            return $overrun;
        }, $this->logger->records ?? []);
    }
}
