---
title: Addon field types
weight: 37
description: "Contribute a field type <namespace>:<handle> from an addon: its contributor, the JSON Schema of its options, and the shape every generator writes a field of it as."
---

# Addon field types

<!-- extension-point: Cbox\Cms\Contracts\FieldTypes\FieldTypeContributor -->
<!-- extension-point: Cbox\Cms\Contracts\FieldTypes\FieldTypeContribution -->
<!-- extension-point: Cbox\Cms\Contracts\FieldTypes\FieldShape -->

An addon can contribute field types to the blueprint schema, named `<namespace>:<handle>` in its own namespace, such as `reviews:stars` (PRD 13.1, 11.12). A blueprint file then uses one as a field's `type`, with the field's choices under `options` ([Blueprint schema v1](blueprint-v1.md)). `cms:generate` reads such a field and writes it in every generator: the descriptor, the DDL of the type table, the PHP record, the query builder, the DTO and its codec, the validators and the TypeScript. The kernel reads and writes its values at run time.

All the types on this page live in `Cbox\Cms\Contracts\FieldTypes` and are `#[Experimental]`.

## How an addon registers its field types

1. The addon's manifest lists the field types in `SchemaContributions` as `ContributedFieldType`s, and names the class of their contributor in `fieldTypeContributor` ([Addon manifest](manifest.md)). An addon has a contributor exactly when it lists a field type; the manifest refuses one without the other.
2. `cms:build` checks that the class implements `FieldTypeContributor`, or refuses the manifest with `registry_invalid_manifest`. It writes the field types and the class to `schema.php`.
3. `cms:generate` reads `schema.php`, makes each addon's contributor through the container, and registers the field types it returns in the addon's namespace. It stops with `generate_invalid_config` (exit 78) in these cases:
   - the registry cannot be read (run `cms:build`);
   - the class cannot be made or does not implement `FieldTypeContributor`;
   - the contributor returns other field types than the manifest lists;
   - an options schema cannot be used.

A field type of a namespace that no installed addon registers, such as `paints:colour` without the paints addon, is still refused with `generate_unknown_field_type`. The core's own field types go through the same registry.

## A field type

`FieldTypeContribution` has three methods:

| Method | Returns |
|---|---|
| `name()` | The field type's `ContributedFieldType`, in the addon's namespace. |
| `optionsSchema()` | The absolute path of a JSON Schema (draft 2020-12) file for the field's `options`, built from `__DIR__`. |
| `shape(FieldTypeOptions $options)` | A `FieldShape`: the core field type a value of the field takes the form of, and that type's options. |

`cms:generate` checks the `options` of every field of the type against the schema. A field without `options` is checked as `{}`. Each violation is `generate_schema_invalid` at its JSON pointer, such as `/fields/0/options/max`. The options that pass reach `shape()` as `FieldTypeOptions`, which reads them by key:

- `string()`, `integer()`, `number()` and `boolean()` read a single value;
- `object()` reads a nested object of options;
- `strings()` and `objects()` read a list.

Each accessor returns null for a key the options lack and throws `InvalidFieldTypeOptions` for a value of another kind. A list inside a list is refused with `generate_schema_invalid`.

## Shapes

A shape is how every generator writes the field, and how the kernel stores its value: as the core field type of the shape, its base. There is one shape per case of `FieldBase`:

| Shape | Base | Options |
|---|---|---|
| `TextShape` | `text` | `minLength`, `maxLength` (1 to 10000, 255 by default), `format` (`TextFormat`) |
| `LongTextShape` | `long_text` | `minLength`, `maxLength` (1 to 1000000, 10000 by default) |
| `IntegerShape` | `integer` | `min`, `max`, `unit` |
| `DecimalShape` | `decimal` | `precision` (1 to 38), `scale`, `min` and `max` as decimal strings, `unit` |
| `BooleanShape` | `boolean` | none |
| `DateShape` | `date` | `min` and `max` as `YYYY-MM-DD` |
| `DatetimeShape` | `datetime` | `min` and `max` in RFC 3339 with an offset |
| `SelectShape` | `select` | the `SelectChoice`s (1 to 500), `multiple`, `minItems` and `maxItems` |

A shape holds its options to the limits of its base and throws `InvalidFieldShape` otherwise. `cms:generate` checks the form of each value: a choice's value is a handle, a bound a real date or instant. It reports a shape that `shape()` cannot make, or a value of the wrong form, as `generate_schema_invalid` at the field's `options`. The rules of the base apply to the field. A field whose base is `long_text`, or a `select` with `multiple`, is never filterable or sortable, and a filterable one is refused with `generate_field_not_queryable`.

The field keeps the addon's name everywhere a field type is named: `fields()` of the `TypeHandle` enum, `TypeFields` in TypeScript, and the validator's summary. In the type catalog, `FieldDefinition::$fieldType` is the name and `FieldDefinition::$base` is the base. `FieldDefinition::valueType()` gives the core field type whose form the value takes, and the kernel reads and writes the type table by it ([Type catalog](contracts/type-catalog.md)).

## Example

The reviews addon from [Addon manifest](manifest.md) contributes `reviews:stars`, a rating from 1 to `max` stars. Its manifest names `ReviewsFieldTypes` as the contributor:

<!-- example-file: examples/Unit/Addons/Reviews/ReviewsFieldTypes.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Addons\Reviews;

use Cbox\Cms\Contracts\FieldTypes\FieldTypeContributor;

/**
 * The field type contributor the reviews addon's manifest names: the field types it lists,
 * reviews:stars.
 */
final readonly class ReviewsFieldTypes implements FieldTypeContributor
{
    public function fieldTypes(): array
    {
        return [new StarsFieldType];
    }
}
```

The field type reads `max` from the field's options and takes the form of an integer:

<!-- example-file: examples/Unit/Addons/Reviews/StarsFieldType.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Addons\Reviews;

use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\FieldTypes\FieldShape;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeContribution;
use Cbox\Cms\Contracts\FieldTypes\FieldTypeOptions;
use Cbox\Cms\Contracts\FieldTypes\IntegerShape;

/**
 * The reviews addon's field type reviews:stars: a rating of 1 to `max` stars, 5 unless the field's
 * options say otherwise. Every generator writes a field of it as an integer from 1 to max, and the
 * field keeps the name reviews:stars.
 */
final readonly class StarsFieldType implements FieldTypeContribution
{
    public function name(): ContributedFieldType
    {
        return new ContributedFieldType('reviews:stars');
    }

    public function optionsSchema(): string
    {
        return __DIR__.'/stars.options.json';
    }

    public function shape(FieldTypeOptions $options): FieldShape
    {
        return new IntegerShape(min: 1, max: $options->integer('max') ?? 5, unit: 'stars');
    }
}
```

Its options schema allows only `max`, from 1 to 10:

<!-- example-file: examples/Unit/Addons/Reviews/stars.options.json -->
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "The options of reviews:stars",
  "type": "object",
  "properties": {
    "max": {
      "description": "The most stars a rating has, 5 unless set.",
      "type": "integer",
      "minimum": 1,
      "maximum": 10
    }
  },
  "additionalProperties": false
}
```

The test builds the registry with the addon and runs `cms:generate` on a type with a `reviews:stars` field. It checks the generated code, a violation of the options schema, and a field type of a namespace no addon registers:

<!-- example: examples/Unit/Addons/StarsFieldTypeTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Unit\Addons;

use Examples\Unit\Addons\Reviews\ReviewsFieldTypes;
use Examples\Unit\Addons\Reviews\ReviewsServiceProvider;
use Examples\Unit\Addons\Reviews\StarsFieldType;
use Examples\Unit\Build\BuildTestCase;
use Examples\Unit\Build\Notes\NotesServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * The reviews addon's field type reviews:stars in the application: cms:build writes the manifest's
 * contributor, ReviewsFieldTypes, to schema.php, and cms:generate reads a field of the type with
 * StarsFieldType, checks its options against stars.options.json, and writes it in every generator
 * as an integer from 1 to its max, under the name reviews:stars. A field type of a namespace no
 * installed addon has is refused.
 */
final class StarsFieldTypeTest extends BuildTestCase
{
    private string $application = '';

    #[Override]
    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->application);

        parent::tearDown();
    }

    #[Test]
    public function it_generates_a_field_of_the_addon_s_field_type(): void
    {
        $this->allowAddons('acme/cms-reviews');
        self::assertSame(0, $this->build(NotesServiceProvider::class, ReviewsServiceProvider::class));

        $schema = require $this->registryFile('schema');
        self::assertIsArray($schema);
        self::assertIsArray($schema['entries']);

        // One entry per addon of the installation, sorted by namespace; this is the reviews addon's.
        $reviews = null;

        foreach ($schema['entries'] as $entry) {
            if (is_array($entry) && ($entry['namespace'] ?? null) === 'reviews') {
                $reviews = $entry;
            }
        }

        self::assertIsArray($reviews);
        self::assertSame(ReviewsFieldTypes::class, $reviews['field_type_contributor']);
        self::assertSame([new StarsFieldType()->name()->value], $reviews['field_types']);

        self::assertSame(0, $this->generate(<<<'YAML'
              - handle: rating
                label: Rating
                description: The stars of the review.
                type: reviews:stars
                classification: public
                sortable: true
                options:
                  max: 7
            YAML));

        self::assertStringContainsString("'rating' => 'reviews:stars',", $this->generated('app/Cms/Generated/TypeHandle.php'));
        self::assertStringContainsString("fieldType: 'reviews:stars',", $this->generated('app/Cms/Generated/GeneratedTypeCatalog.php'));
        self::assertStringContainsString('base: FieldBase::Integer,', $this->generated('app/Cms/Generated/GeneratedTypeCatalog.php'));
        self::assertStringContainsString('public ?int $rating { get; }', $this->generated('app/Cms/Generated/Records/AppReview/AppReviewRecord.php'));
        self::assertStringContainsString("rating: 'reviews:stars';", $this->generated('resources/js/cms/generated/index.ts'));
        self::assertStringContainsString('check ("rating" <= 7)', $this->generated('database/migrations/cms/app__review_0001_create.php'));
    }

    #[Test]
    public function it_checks_the_options_against_the_options_schema_and_refuses_an_unknown_namespace(): void
    {
        $this->allowAddons('acme/cms-reviews');
        self::assertSame(0, $this->build(NotesServiceProvider::class, ReviewsServiceProvider::class));

        self::assertSame(65, $this->generate(<<<'YAML'
              - handle: rating
                label: Rating
                description: The stars of the review.
                type: reviews:stars
                classification: public
                options:
                  max: 11
              - handle: colour
                label: Colour
                description: A colour of an addon that is not installed.
                type: paints:colour
                classification: public
            YAML));

        $output = app(Kernel::class)->output();
        self::assertStringContainsString('[generate_schema_invalid] schema/review.yaml, /fields/0/options/max: Number must be lower than or equal to 10', $output);
        self::assertStringContainsString('(the options schema of the field type reviews:stars)', $output);
        self::assertStringContainsString('[generate_unknown_field_type] schema/review.yaml, /fields/1/type: no field type contributor registers the field type paints:colour', $output);
    }

    /**
     * Runs cms:generate in a fresh application directory whose schema holds the type review with
     * the fields, and returns its exit code.
     */
    private function generate(string $fields): int
    {
        $this->application = sys_get_temp_dir().'/cms-stars-example-'.bin2hex(random_bytes(8));
        mkdir($this->application.'/schema', 0o700, true);
        file_put_contents($this->application.'/schema/review.yaml', <<<YAML
            blueprint: 1
            kind: type
            type_id: 0192a3b4-c5d6-7e8f-9a0b-0000000000a1
            handle: review
            label: Review
            description: A review rated with the reviews addon's stars.
            version: 1
            capabilities:
              history: full
              stages: none
              localization: none
            fields:
            {$fields}
            YAML);

        config()->set('cbox-cms.generators', [
            'root' => $this->application,
            'roots' => ['app' => 'schema'],
            'php_directory' => 'app/Cms/Generated',
            'php_namespace' => 'App\Cms\Generated',
            'typescript_directory' => 'resources/js/cms/generated',
            'migrations_directory' => 'database/migrations/cms',
        ]);

        return app(Kernel::class)->call('cms:generate');
    }

    private function generated(string $path): string
    {
        $contents = file_get_contents($this->application.'/'.$path);
        self::assertIsString($contents, $path.' was not generated.');

        return $contents;
    }
}
```
