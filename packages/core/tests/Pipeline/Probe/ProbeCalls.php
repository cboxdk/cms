<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

/**
 * What the pipeline handed RenameProbeAction: the arguments of each call of resolve() and plan(),
 * in order.
 */
final class ProbeCalls
{
    /** @var list<array{string, list<mixed>}> */
    public array $calls = [];

    /**
     * @param  list<mixed>  $arguments
     */
    public function record(string $method, array $arguments): void
    {
        $this->calls[] = [$method, $arguments];
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->calls);
    }
}
