<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

/**
 * The pipeline phase a hook runs in (PRD 6.2 and 6.3). One case per hook interface:
 * AuthorizeHook, TransformHook and ValidateHook.
 */
#[Experimental]
enum Phase: string
{
    /** Phase 2. The hook may reject with a reason, never grant access. */
    case Authorize = 'authorize';

    /** Phase 4. The hook may change declared fields in the plan, without IO. */
    case Transform = 'transform';

    /** Phase 5. The hook may add errors, never remove the core's errors. */
    case Validate = 'validate';
}
