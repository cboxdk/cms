<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Tooling;

final readonly class RecordedCommand
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function __construct(
        public array $command,
        public string $directory,
        public array $environment,
    ) {}
}
