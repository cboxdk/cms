<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Points;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Generators\Codec\Domain\Dto\PhpLocation;
use Cbox\Cms\Generators\Generation\Boundary\TypeScriptRuntime;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Generators\Protocol\Domain\Dto\ValueBinding;
use Cbox\Cms\Panel\Tests\Points\Fixtures\NoteAuthor;
use Cbox\Cms\Panel\Tests\Points\Fixtures\NoteCardV1;
use Cbox\Cms\Panel\Tests\Points\Fixtures\NoteCardV2;
use Cbox\Cms\Panel\Tests\Points\Fixtures\NoteToolbarV1;
use Cbox\Cms\Tooling\Protocol\Adapter\ProtocolGeneration;
use Cbox\Cms\Tooling\Protocol\Boundary\PointsLock;
use Cbox\Cms\Tooling\Protocol\Domain\Dto\PointSchema;
use Cbox\Cms\Tooling\Protocol\Domain\PanelPointSchemas;

/**
 * The fixture points of the props pipeline (PRD 13.4): three points in Fixtures, bound as the
 * panel's own points are bound in PanelPointSchemas, whose generated codecs, TypeScript, sample
 * props and lock are committed golden files below Fixtures, held to what composer
 * generate:protocol's code writes for them by PanelPointGenerationTest.
 *
 * notes.detail.card@2 holds one member of each kind of value, notes.detail.card@1 is its older
 * version with a downcast, and notes.list.toolbar@1 is stable, so the lock holds it.
 */
final class PanelPointFixtures
{
    /** The fixture's directory, relative to the root of cboxdk/cms. */
    public const string DIRECTORY = 'packages/panel/tests/Points/Fixtures';

    public const string SCHEMAS = self::DIRECTORY.'/schemas';

    public const string PHP_DIRECTORY = self::DIRECTORY.'/Generated';

    public const string PHP_NAMESPACE = 'Cbox\Cms\Panel\Tests\Points\Fixtures\Generated';

    public const string TYPESCRIPT_DIRECTORY = self::DIRECTORY.'/typescript/generated';

    public const string LOCK = self::DIRECTORY.'/points.lock.json';

    /**
     * The fixture's bindings, sorted by file.
     *
     * @return list<SchemaBinding>
     */
    public static function bindings(): array
    {
        $id = ValueBinding::id(ActorId::class);

        return [
            PanelPointSchemas::point('notes.detail.card.v1.json', 'NoteCardCodecV1', 1, ['#' => NoteCardV1::class], ['#/properties/owner' => $id], directory: self::SCHEMAS),
            PanelPointSchemas::point('notes.detail.card.v2.json', 'NoteCardCodecV2', 2, ['#' => NoteCardV2::class, '#/$defs/author' => NoteAuthor::class], [
                '#/properties/access' => ValueBinding::enum(ClassificationAccess::class),
                '#/properties/locale' => ValueBinding::value(Locale::class),
                '#/properties/owner' => $id,
                '#/$defs/author/properties/actor_class' => ValueBinding::enum(ActorClass::class),
            ], directory: self::SCHEMAS),
            PanelPointSchemas::point('notes.list.toolbar.v1.json', 'NoteToolbarCodecV1', 1, ['#' => NoteToolbarV1::class], directory: self::SCHEMAS),
        ];
    }

    /**
     * The fixture's point schemas, read from the repository.
     *
     * @return list<PointSchema>
     *
     * @throws GenerationFailed
     */
    public static function points(): array
    {
        /** @var list<GenerationProblem> $problems */
        $problems = [];
        $points = ProtocolGeneration::points(self::root(), self::bindings(), $problems);

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        return $points;
    }

    /**
     * What composer generate:protocol's code writes for the fixture: the codecs, the TypeScript and
     * the lock, which records the stable point.
     *
     * @throws GenerationFailed
     */
    public static function generated(): GenerationResult
    {
        $points = self::points();
        $result = PanelPointSchemas::result($points, new TypeScriptRuntime()->source(), new PhpLocation(self::PHP_DIRECTORY, self::PHP_NAMESPACE), self::TYPESCRIPT_DIRECTORY, self::SCHEMAS);

        return new GenerationResult([...$result->files, PointsLock::file($points, null, self::LOCK)], $result->directories);
    }

    public static function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
