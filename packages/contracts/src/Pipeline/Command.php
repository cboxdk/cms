<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The domain input of a write (GUARDRAILS 2.1, PRD 6.1): its targets, the versions the caller
 * expected and its fields. A command is a final readonly class that implements this marker and
 * declares its name and version with #[Command], for example
 * #[Command('entry.release', version: 1)] from Cbox\Cms\Contracts\Attributes.
 *
 * Everything else about the call, who sends it, for whom, with which idempotency key and wait
 * level, is the Envelope, which the surface builds.
 */
#[Experimental]
interface Command {}
