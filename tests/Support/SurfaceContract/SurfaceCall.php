<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\CommandName;

/**
 * One call of a command through a surface, as its caller makes it: the command and version, its
 * JSON document, the idempotency key, whether it is a dry run, the wait level and the credential.
 */
final readonly class SurfaceCall
{
    public function __construct(
        public CommandName $command,
        public int $version,
        public string $document,
        public string $key,
        public bool $dryRun,
        public WaitLevel $waitLevel,
        public TransportCredential $credential,
    ) {}
}
