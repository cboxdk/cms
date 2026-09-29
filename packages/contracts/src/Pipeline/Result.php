<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Pipeline;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The result of a read (GUARDRAILS 2.1): a final readonly DTO that implements this marker,
 * typically a named selection of fields (PRD 8.9). A write's result is WriteResult instead.
 */
#[Experimental]
interface Result {}
