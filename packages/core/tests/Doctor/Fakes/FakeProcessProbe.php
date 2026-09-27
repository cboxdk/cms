<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Core\Doctor\Domain\Probes\ProcessProbe;

/**
 * A console process with the connections the test lists; none until the test adds one.
 */
final class FakeProcessProbe implements ProcessProbe
{
    /**
     * @param  list<string>  $connections
     */
    public function __construct(
        public array $connections = [],
        public bool $http = false,
    ) {}

    public function connectionConfigured(string $name): bool
    {
        return in_array($name, $this->connections, true);
    }

    public function servesHttp(): bool
    {
        return $this->http;
    }
}
