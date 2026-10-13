<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Override;

/**
 * What entry.create version 2 read (PRD 6.2 phase 1): the entry, which the command expects not to
 * exist, each variant the command creates, read as absent or at the head the entry has, the home
 * node at its version, or null when the node does not exist or the actor's regions do not reach
 * it, and the type the command names, as the TypeCatalog has it, or null when the installation has
 * no such type. The kernel checks at commit that the entry and its variants are still absent and
 * the node still at its version, so two creates of one id commit once.
 */
#[Internal]
final readonly class CreateLocalizedEntryAggregates implements Aggregates
{
    /**
     * @param  non-empty-list<VariantRef>  $variants  the variants the command creates
     */
    public function __construct(
        public EntryId $entry,
        public array $variants,
        public ?StoredVariants $existing,
        public NodeId $home,
        public ?AggregateVersion $homeVersion,
        public ?TypeDefinition $type = null,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(
            new ReadVersion($this->entry, $this->existing?->version),
            ...array_map(fn (VariantRef $variant): ReadVersion => new ReadVersion($variant, $this->existing?->head($variant->variant)?->version), $this->variants),
            ...[new ReadVersion($this->home, $this->homeVersion)],
        );
    }

    /**
     * The home node in every locale, because the command writes the shared variant, when the
     * node was read; anywhere when it read as absent, which the kernel rejects after the
     * authorization.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->homeVersion instanceof AggregateVersion ? AuthorizationScope::on(new AuthorizationTarget($this->home)) : AuthorizationScope::anywhere();
    }
}
