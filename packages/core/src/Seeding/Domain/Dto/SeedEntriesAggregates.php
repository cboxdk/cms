<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What seed.entries read: the entries of the chunk that do not exist yet, each with its shared
 * variant, both absent, and the home nodes of those entries at their versions, null for a node
 * absent or out of the actor's reach.
 */
#[Internal]
final readonly class SeedEntriesAggregates implements Aggregates
{
    /**
     * @param  list<EntryId>  $absent  the entries to create, in the chunk's order
     * @param  array<string, ?AggregateVersion>  $nodes  the home nodes' versions by node id
     */
    public function __construct(
        public array $absent,
        public array $nodes,
    ) {}

    public function isAbsent(EntryId $entry): bool
    {
        return array_any($this->absent, static fn (EntryId $each): bool => $each->equals($entry));
    }

    #[Override]
    public function versions(): ReadVersions
    {
        $reads = [];

        foreach ($this->absent as $entry) {
            $reads[] = ReadVersion::absent($entry);
            $reads[] = ReadVersion::absent(new VariantRef($entry, VariantKey::shared()));
        }

        foreach ($this->nodes as $node => $version) {
            $reads[] = new ReadVersion(NodeId::fromString($node), $version);
        }

        return new ReadVersions(...$reads);
    }

    /**
     * The home nodes that were read, in every locale; anywhere when none was.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        $targets = [];

        foreach ($this->nodes as $node => $version) {
            if ($version instanceof AggregateVersion) {
                $targets[] = new AuthorizationTarget(NodeId::fromString((string) $node));
            }
        }

        return $targets === [] ? AuthorizationScope::anywhere() : AuthorizationScope::on(...$targets);
    }
}
