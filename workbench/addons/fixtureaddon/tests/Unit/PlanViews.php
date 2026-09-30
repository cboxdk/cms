<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Tests\Unit;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ReleasedRevision;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;
use Workbench\FixtureAddon\FixtureArticle;

/**
 * The views of a plan the kernel gives the fixture addon's hooks, built by hand as a hook's test
 * builds them: what an addon that reads public fields sees of entry.create and variant.release.
 * The ids come from the testkit's seeded FakeIdGenerator on a FakeClock, so every run has the
 * same ones.
 */
final readonly class PlanViews
{
    private FakeIdGenerator $ids;

    public function __construct()
    {
        $this->ids = new FakeIdGenerator(seed: 45, clock: new FakeClock(new DateTimeImmutable('2026-09-30T12:00:00+00:00')));
    }

    public function entry(): EntryId
    {
        return new EntryId($this->ids->next());
    }

    public function otherType(): TypeId
    {
        return new TypeId($this->ids->next());
    }

    public static function article(): TypeId
    {
        return TypeId::fromString(FixtureArticle::TYPE_ID);
    }

    /**
     * The owner's fields with the title, when given, and the addon's slug, when given.
     */
    public static function fields(?string $title, ?string $slug = null): FieldValues
    {
        $own = $title === null ? new FieldMap : new FieldMap(new NamedValue(FixtureArticle::title(), new TextValue($title)));

        return $slug === null
            ? new FieldValues($own)
            : new FieldValues($own, new ExtensionFields(FixtureArticle::namespace(), new FieldMap(new NamedValue(FixtureArticle::slug(), new TextValue($slug)))));
    }

    /**
     * The view of entry.create writing the first revision of each entry.
     */
    public function create(RevisionCreated ...$revisions): PlanView
    {
        return new PlanView(new CommandName('entry.create'), 1, $this->principal(), ClassificationAccess::Public, ...$revisions);
    }

    public function revision(EntryId $entry, TypeId $type, FieldValues $fields): RevisionCreated
    {
        return new RevisionCreated($entry, $type, VariantKey::shared(), RevisionNumber::first(), $fields);
    }

    /**
     * The view of variant.release releasing a revision of the entry that holds the fields.
     */
    public function release(EntryId $entry, TypeId $type, int $revision, FieldValues $fields): PlanView
    {
        $release = new VariantReleased($entry, $type, VariantKey::shared(), new RevisionNumber($revision));

        return new PlanView(new CommandName('variant.release'), 1, $this->principal(), ClassificationAccess::Public, $release)
            ->withReleases(new ReleasedRevision($release, $fields));
    }

    private function principal(): ActorPrincipal
    {
        return new ActorPrincipal(new ActorId($this->ids->next()), [], IssuerKind::Service, ClassificationAccess::Internal);
    }
}
