<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * CommandHooksBehaviour against the fake the pipeline's action tests use.
 */
final class FakeCommandHooksBehaviourTest extends TestCase
{
    use CommandHooksBehaviour;

    #[Override]
    protected function commandHooks(array $hooks): CommandHooks
    {
        $fake = new FakeCommandHooks;

        foreach ($hooks as [$command, $version, $hook]) {
            $fake->add($command, $version, $hook);
        }

        return $fake;
    }
}
