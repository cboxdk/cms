<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Override;

/**
 * What entry.revise version 2 read (PRD 6.2 phase 1): the entry with the head of each variant the
 * command revises, or null when the entry does not exist or the actor's regions do not reach it,
 * and its type. The entry is read at its version and only locked for share at commit, so a revise
 * waits for a change of the entry itself; the variants are the aggregates the revise changes.
 */
#[Internal]
final readonly class ReviseVariantsAggregates implements Aggregates
{
    /**
     * @param  non-empty-list<VariantRef>  $variants  the variants the command revises
     */
    public function __construct(
        public EntryId $entry,
        public array $variants,
        public ?StoredVariants $stored,
        public ?TypeDefinition $type = null,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(
            new ReadVersion($this->entry, $this->stored?->version),
            ...array_map(fn (VariantRef $variant): ReadVersion => new ReadVersion($variant, $this->stored?->head($variant->variant)?->version), $this->variants),
        );
    }

    /**
     * The entry's home in the locale of each language's variant, and in every locale for the
     * shared variant (PRD 5.10, 10.3), so a grant limited to one language reaches that language
     * alone; anywhere when the entry read as absent.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        if (! $this->stored instanceof StoredVariants) {
            return AuthorizationScope::anywhere();
        }

        $home = $this->stored->home;

        return AuthorizationScope::on(...array_map(
            static fn (VariantRef $variant): AuthorizationTarget => new AuthorizationTarget($home, $variant->variant->locale),
            $this->variants,
        ));
    }
}
