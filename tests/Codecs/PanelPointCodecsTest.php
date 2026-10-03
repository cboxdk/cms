<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Codecs;

use Cbox\Cms\Contracts\Codecs\JsonCodec;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Tests\Codecs\KernelSchema;
use Cbox\Cms\Generators\Protocol\Boundary\SampleProps;
use Cbox\Cms\Generators\Protocol\Domain\Dto\SchemaBinding;
use Cbox\Cms\Panel\Tests\Points\Fixtures\Generated\NoteCardCodecV1;
use Cbox\Cms\Panel\Tests\Points\Fixtures\Generated\NoteCardCodecV2;
use Cbox\Cms\Panel\Tests\Points\Fixtures\NoteCardV1;
use Cbox\Cms\Panel\Tests\Points\Fixtures\NoteCardV2;
use Cbox\Cms\Panel\Tests\Points\PanelPointFixtures;
use Cbox\Cms\Tooling\Protocol\Domain\PanelPointSchemas;
use LogicException;

/*
 * The codecs of the panel points' props (GUARDRAILS 2.2, PRD 13.4): every point PanelPointSchemas
 * binds, and the fixture points of PanelPointFixtures, which hold one member of each kind of value.
 * Each point's sample props, made from its JSON Schema alone, validate against the schema with an
 * independent validator, opis/json-schema, and read back through the point's generated PHP codec to
 * the same JSON; what the codec writes validates against the schema too. A document that breaks the
 * schema is refused by both. The older version of a fixture point gets its props from the newest
 * version's through its downcast, and they validate against its own schema.
 */

/**
 * Every point binding with the namespace of its generated codec, by point schema.
 *
 * @return array<string, array{SchemaBinding, string}>
 */
function panelPointBindings(): array
{
    $bindings = [];

    foreach (PanelPointSchemas::all() as $binding) {
        $bindings[$binding->path()] = [$binding, PanelPointSchemas::PHP_NAMESPACE];
    }

    foreach (PanelPointFixtures::bindings() as $binding) {
        $bindings[$binding->path()] = [$binding, PanelPointFixtures::PHP_NAMESPACE];
    }

    return $bindings;
}

/**
 * The generated codec of a point binding.
 *
 * @return JsonCodec<object>
 */
function panelPointCodec(SchemaBinding $binding, string $namespace): JsonCodec
{
    $class = $namespace.'\\'.$binding->codecClass;
    $codec = class_exists($class) ? new $class : null;

    return $codec instanceof JsonCodec ? $codec : throw new LogicException(sprintf('No generated codec %s for %s. Run composer generate:protocol.', $class, $binding->path()));
}

function panelPointSchema(SchemaBinding $binding): string
{
    $json = file_get_contents(PanelPointFixtures::root().'/'.$binding->path());

    return is_string($json) ? $json : throw new LogicException('Cannot read '.$binding->path().'.');
}

it('reads the sample props of every point back through its codec to the same JSON, valid against its schema', function (SchemaBinding $binding, string $namespace): void {
    $sample = SampleProps::json(SampleProps::of(panelPointSchema($binding), $binding->path()));
    $codec = panelPointCodec($binding, $namespace);

    expect(KernelSchema::errors($binding->schema, $sample, PanelPointFixtures::root().'/'.$binding->directory))->toBe([]);

    foreach (ClassificationAccess::cases() as $access) {
        $written = $codec->encode($codec->decode($sample, $access), $access);

        expect(json_decode($written, true, 64, JSON_THROW_ON_ERROR))->toBe(json_decode($sample, true, 64, JSON_THROW_ON_ERROR))
            ->and(KernelSchema::errors($binding->schema, $written, PanelPointFixtures::root().'/'.$binding->directory))->toBe([]);
    }
})->with(panelPointBindings());

it('refuses, as the schema does, props with a member left out or a key the schema does not have', function (SchemaBinding $binding, string $namespace): void {
    $sample = SampleProps::of(panelPointSchema($binding), $binding->path());
    $codec = panelPointCodec($binding, $namespace);
    $directory = PanelPointFixtures::root().'/'.$binding->directory;
    $extra = clone $sample;
    $extra->planted = true;
    $cases = ['planted' => $extra];
    $schema = json_decode(panelPointSchema($binding), true, 64, JSON_THROW_ON_ERROR);
    $required = is_array($schema) && is_array($schema['required'] ?? null) ? $schema['required'] : [];

    foreach (array_filter($required, is_string(...)) as $key) {
        $missing = clone $sample;
        unset($missing->{$key});
        $cases[$key] = $missing;
    }

    foreach ($cases as $path => $document) {
        $json = SampleProps::json($document);

        expect(KernelSchema::errors($binding->schema, $json, $directory))->not->toBe([], $path);
        expect(static fn (): object => $codec->decode($json, ClassificationAccess::Sensitive))->toThrow(DecodingFailed::class, null, $path);
    }
})->with(panelPointBindings());

it('builds the props of the older version of a point from the newest version\'s, valid against its own schema', function (): void {
    [$v1] = panelPointBindings()[PanelPointFixtures::SCHEMAS.'/notes.detail.card.v1.json'];
    [$v2] = panelPointBindings()[PanelPointFixtures::SCHEMAS.'/notes.detail.card.v2.json'];
    $newest = new NoteCardCodecV2()->decode(SampleProps::json(SampleProps::of(panelPointSchema($v2), $v2->path())), ClassificationAccess::Public);

    expect($newest)->toBeInstanceOf(NoteCardV2::class);

    $older = NoteCardV1::downcast($newest);
    $json = new NoteCardCodecV1()->encode($older, ClassificationAccess::Public);

    expect(KernelSchema::errors($v1->schema, $json, PanelPointFixtures::root().'/'.$v1->directory))->toBe([])
        ->and(json_decode($json, true, 64, JSON_THROW_ON_ERROR))->toBe(['owner' => '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01', 'title' => 'sample']);
});
