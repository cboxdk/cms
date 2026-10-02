<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Override;

/**
 * A site's handle (PRD 5.9, 11.14), as an aggregate a command reads so two registrations of one
 * handle commit one after the other: it exists, at version 1, when a site has the handle, and is
 * absent otherwise. site.register reads it as absent, and the commit, which locks an aggregate read
 * as absent with an advisory lock first, finds it taken when another registration took the handle
 * meanwhile, which is version_conflict instead of a unique violation.
 */
#[Internal]
final readonly class SiteHandleRef implements AggregateRef
{
    public const string KIND = 'site_handle';

    public function __construct(public SiteHandle $handle) {}

    /**
     * "site_handle:" and the handle, which holds no colon.
     */
    #[Override]
    public function aggregateKey(): string
    {
        return self::KIND.':'.$this->handle->value;
    }
}
