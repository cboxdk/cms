<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Override;

/**
 * What variant.release version 2 read (PRD 6.2 phase 1): the entry with the head of the variant
 * the command releases, or null when the entry does not exist or the actor's regions do not reach
 * it, and its type. The entry is read at its version and only locked for share at commit, so a
 * release waits for a change of the entry itself; the variant is the aggregate the release changes.
 */
#[Internal]
final readonly class ReleaseEntryVariantAggregates implements Aggregates
{
    public function __construct(
        public VariantRef $variant,
        public ?StoredEntry $stored,
        public ?TypeDefinition $type = null,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(
            new ReadVersion($this->variant->entry, $this->stored?->version),
            new ReadVersion($this->variant, $this->stored?->head?->version),
        );
    }

    /**
     * The entry's home in the locale of a language's variant, and in every locale for the shared
     * variant (PRD 5.10, 10.3); anywhere when the entry read as absent.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->stored instanceof StoredEntry
            ? AuthorizationScope::on(new AuthorizationTarget($this->stored->home, $this->variant->variant->locale))
            : AuthorizationScope::anywhere();
    }
}
