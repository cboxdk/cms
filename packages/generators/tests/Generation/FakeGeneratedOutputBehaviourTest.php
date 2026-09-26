<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Generation;

use Cbox\Cms\Generators\Generation\Domain\GeneratedOutput;
use Cbox\Cms\Generators\Tests\Generation\Fakes\FakeGeneratedOutput;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * GeneratedOutputBehaviour against the fake the generator's action tests use.
 */
final class FakeGeneratedOutputBehaviourTest extends TestCase
{
    use GeneratedOutputBehaviour;

    #[Override]
    protected function generatedOutput(): GeneratedOutput
    {
        return new FakeGeneratedOutput;
    }

    #[Override]
    protected function outputRoot(): string
    {
        return '/srv/app';
    }

    #[Override]
    protected function putFile(GeneratedOutput $output, string $root, string $path, string $contents): void
    {
        $this->fake($output)->put($root.'/'.$path, $contents);
    }

    #[Override]
    protected function blockPath(GeneratedOutput $output, string $root, string $path): void
    {
        $this->fake($output)->block($root.'/'.$path);
    }

    #[Override]
    protected function contentsAt(GeneratedOutput $output, string $root, string $path): ?string
    {
        return $this->fake($output)->contents($root.'/'.$path);
    }

    #[Override]
    protected function filesBelow(GeneratedOutput $output, string $root): array
    {
        return $this->fake($output)->files($root);
    }

    private function fake(GeneratedOutput $output): FakeGeneratedOutput
    {
        return $output instanceof FakeGeneratedOutput ? $output : throw new LogicException('The case uses an output this class did not make.');
    }
}
