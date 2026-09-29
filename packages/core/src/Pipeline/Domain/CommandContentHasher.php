<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Command;

/**
 * The content hash a command's idempotency key is stored with (PRD 6.1): the same key with the
 * same content replays, with other content it is idempotency_conflict. The hash covers the
 * command's name, its version and its input, never the envelope, so the same input under another
 * version of the command is other content. Equal input gives equal hashes in every process and on
 * every node, because the hash is taken over the command's canonical form, which the command's
 * generated codec writes (GUARDRAILS 2.2).
 */
#[Internal]
interface CommandContentHasher
{
    public function hash(CommandName $command, int $version, Command $input): ContentHash;
}
