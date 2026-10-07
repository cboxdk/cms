<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Slug\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use LogicException;
use Override;
use Workbench\FixtureAddon\FixtureArticle;
use Workbench\FixtureAddon\Slug\Domain\ArticleHeads;
use Workbench\FixtureAddon\Slug\Domain\ArticleSlug;
use Workbench\FixtureAddon\Slug\Domain\Commands\SetArticleSlug;
use Workbench\FixtureAddon\Slug\Domain\Dto\ArticleHead;
use Workbench\FixtureAddon\Slug\Domain\Dto\SetArticleSlugAggregates;

/**
 * The write action of fixtureaddon.slug.set (PRD 6.2, 13.4), exposed on REST and Inertia, so the
 * panel's generic command form renders the command and REST runs the same document. resolve()
 * reads the head of the entry's shared variant through the addon's port; plan() writes the next
 * revision after the variant's highest number with the head's fields and the addon's slug in
 * place of the one the head holds, if any, and moves the head to it, as entry.revise does with a
 * caller's whole snapshot. It writes nothing: the kernel validates the fields against the type's
 * schema, runs the hooks and commits the plan.
 *
 * The command expects the variant at a version, so the kernel rejects it with version_conflict
 * before plan() when the entry or its head is absent or at another version; plan() is only ever
 * called with the head the caller saw.
 *
 * @implements WriteAction<SetArticleSlug, SetArticleSlugAggregates>
 */
#[Action(handles: SetArticleSlug::class, surfaces: [Surface::Rest, Surface::Inertia])]
final readonly class SetArticleSlugAction implements WriteAction
{
    public function __construct(private ArticleHeads $heads) {}

    /**
     * @param  SetArticleSlug  $command
     */
    #[Override]
    public function resolve(Command $command): SetArticleSlugAggregates
    {
        return new SetArticleSlugAggregates($command->entry, $this->heads->head($command->entry));
    }

    /**
     * @param  SetArticleSlug  $command
     * @param  SetArticleSlugAggregates  $aggregates
     *
     * @throws LogicException when the head was read as absent, which the kernel's check of the expected version rules out
     */
    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $head = $aggregates->head;

        if (! $head instanceof ArticleHead) {
            throw new LogicException(sprintf(
                'fixtureaddon.slug.set planned a revision of the entry %s, whose shared variant was read as absent; the kernel rejects such a call with version_conflict before it plans.',
                $command->entry->toString(),
            ));
        }

        $next = $head->latest->next();
        $shared = VariantKey::shared();

        return new Plan(
            new RevisionCreated($command->entry, $head->type, $shared, $next, self::withSlug($head->fields, $command->slug)),
            new HeadMoved($command->entry, $shared, $head->revision, $next),
        );
    }

    /**
     * The fields with the addon's slug set, every other field as given: the owner's fields and the
     * other extenders' untouched, the addon's other fields kept.
     */
    public static function withSlug(FieldValues $fields, ArticleSlug $slug): FieldValues
    {
        $namespace = FixtureArticle::namespace();
        $own = $fields->extension($namespace);
        $kept = $own instanceof FieldMap
            ? array_values(array_filter($own->fields, static fn (NamedValue $field): bool => $field->handle->value !== FixtureArticle::SLUG))
            : [];
        $others = array_values(array_filter($fields->extensions, static fn (ExtensionFields $extension): bool => ! $extension->namespace->equals($namespace)));

        $own = new ExtensionFields($namespace, new FieldMap(...[...$kept, new NamedValue(FixtureArticle::slug(), new TextValue($slug->value))]));

        return new FieldValues($fields->own, ...[...$others, $own]);
    }
}
