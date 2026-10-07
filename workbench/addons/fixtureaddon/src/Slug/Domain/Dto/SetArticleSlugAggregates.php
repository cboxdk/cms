<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Slug\Domain\Dto;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What SetArticleSlugAction::resolve() read: the head of the entry's shared variant, or null when
 * it was read as absent. versions() names the shared variant at the version it was read at, the
 * one aggregate the plan changes, which the kernel checks against the version the command expects
 * and locks at commit; authorizationScope() is the entry's home node in every locale, because the
 * command acts on the shared variant (PRD 5.10), and anywhere when the entry read as absent.
 */
final readonly class SetArticleSlugAggregates implements Aggregates
{
    public function __construct(
        public EntryId $entry,
        public ?ArticleHead $head,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(new ReadVersion(new VariantRef($this->entry, VariantKey::shared()), $this->head?->version));
    }

    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->head instanceof ArticleHead ? AuthorizationScope::on(new AuthorizationTarget($this->head->home)) : AuthorizationScope::anywhere();
    }
}
