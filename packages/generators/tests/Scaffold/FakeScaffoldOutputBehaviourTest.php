<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Scaffold;

use Cbox\Cms\Generators\Scaffold\Domain\ScaffoldOutput;
use Cbox\Cms\Generators\Tests\Scaffold\Fakes\FakeScaffoldOutput;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * ScaffoldOutputBehaviour against the fake the scaffold actions' tests use.
 */
final class FakeScaffoldOutputBehaviourTest extends TestCase
{
    use ScaffoldOutputBehaviour;

    #[Override]
    protected function scaffoldOutput(): ScaffoldOutput
    {
        return new FakeScaffoldOutput;
    }

    #[Override]
    protected function scaffoldRoot(): string
    {
        return '/srv/addons/acme/cms-tally';
    }

    #[Override]
    protected function putFile(ScaffoldOutput $output, string $root, string $path, string $contents): void
    {
        $this->fake($output)->put($root.'/'.$path, $contents);
    }

    #[Override]
    protected function blockPath(ScaffoldOutput $output, string $root, string $path): void
    {
        $this->fake($output)->block($root.'/'.$path);
    }

    #[Override]
    protected function contentsAt(ScaffoldOutput $output, string $root, string $path): ?string
    {
        return $this->fake($output)->contents($root.'/'.$path);
    }

    private function fake(ScaffoldOutput $output): FakeScaffoldOutput
    {
        return $output instanceof FakeScaffoldOutput ? $output : throw new LogicException('The case uses an output this class did not make.');
    }
}
