<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Core\Doctor\Domain\Probes\RuntimeProbe;

/**
 * PHP and Laravel versions as the test sets them.
 */
final class FakeRuntimeProbe implements RuntimeProbe
{
    public function __construct(
        public string $php = '8.5.10',
        public string $laravel = '13.4.0',
    ) {}

    public function phpVersion(): string
    {
        return $this->php;
    }

    public function laravelVersion(): string
    {
        return $this->laravel;
    }
}
