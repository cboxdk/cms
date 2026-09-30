<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;

/**
 * The generated codec of a command's contract version as the kernel writes a command it holds
 * (GUARDRAILS 2.2, PRD 6.1): its canonical JSON with every field, which the idempotency content
 * hash is taken over. A CommandCodec holds its codec as a JsonCodec of some command, so the kernel
 * writes through this, which refuses a command of another class.
 */
#[Experimental]
interface CommandEncoder
{
    /**
     * The command's canonical JSON at the highest classification access.
     *
     * @throws EncodingFailed when the command is not of the codec's class or holds a value that has no form in its contract
     */
    public function encodeCommand(Command $command): string;
}
