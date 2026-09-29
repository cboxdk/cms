<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Cbox\Cms\Contracts\Hooks\AuthorizeHook;
use Cbox\Cms\Contracts\Hooks\TransformHook;
use Cbox\Cms\Contracts\Hooks\ValidateHook;

/**
 * The pipeline phase a hook runs in (PRD 6.2 and 6.3).
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

    /**
     * The interface a hook of the phase implements.
     *
     * @return class-string<AuthorizeHook|TransformHook|ValidateHook>
     */
    public function hookInterface(): string
    {
        return match ($this) {
            self::Authorize => AuthorizeHook::class,
            self::Transform => TransformHook::class,
            self::Validate => ValidateHook::class,
        };
    }
}
