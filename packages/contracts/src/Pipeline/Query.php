<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The input of a read (GUARDRAILS 2.1, PRD 6.2): a final readonly class that implements this
 * marker. The actor is never a field of a query; the query pipeline takes it from the transport's
 * authentication.
 */
#[Experimental]
interface Query {}
