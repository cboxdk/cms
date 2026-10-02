<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Pipeline\Query;

/**
 * An Inertia page visit that reads, as the profile read it: the query its codec read from the
 * visit's query parameter, and the credential the visit carried, or null.
 */
#[Internal]
final readonly class InertiaQueryInput
{
    public function __construct(
        public Query $query,
        public ?TransportCredential $credential,
    ) {}
}
