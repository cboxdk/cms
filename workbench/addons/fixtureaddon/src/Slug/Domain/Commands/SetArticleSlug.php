<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Slug\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;
use Workbench\FixtureAddon\Slug\Domain\ArticleSlug;

/**
 * The fixture addon's own command, fixtureaddon.slug.set version 1 (PRD 13.4): sets the addon's
 * slug, ext.fixtureaddon.fixture_slug, of an article of the type it extends, app:fixture_article,
 * in a new revision of the entry's shared variant that keeps every other field as the head holds
 * it, and moves the head to it. It is an addon's command through the kernel's pipeline, with the
 * kernel's authorization (a role whose permissions name fixtureaddon.slug.set), field validation
 * and commit, exposed on REST and in the panel, where the generic command form renders it and the
 * addon's replacement fixtureaddon.slug-input takes the place of the input of `slug`, the member
 * bound to the addon's own value class ArticleSlug.
 *
 * The version is the shared variant's, as entry.revise takes it: a variant at another version, or
 * an entry that does not exist or the caller cannot reach, is version_conflict (invariant 11).
 */
#[Command('fixtureaddon.slug.set', version: 1)]
final readonly class SetArticleSlug implements ExpectsVersions
{
    public function __construct(
        public EntryId $entry,
        public AggregateVersion $version,
        public ArticleSlug $slug,
    ) {}

    /**
     * The shared variant this command revises.
     */
    public function variant(): VariantRef
    {
        return new VariantRef($this->entry, VariantKey::shared());
    }

    /**
     * The shared variant, at the version the caller saw.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::at($this->variant(), $this->version));
    }
}
