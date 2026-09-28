# Blueprint schema, version 1

<!-- extension-point: packages/contracts/resources/schemas/blueprint.v1.json -->

A blueprint file defines one content type, or adds fields to another owner's type. Blueprint files live under `schema/**/*.yaml` (PRD 11.12). Their format is the JSON Schema [`blueprint.v1.json`](blueprint.v1.json) next to this page, JSON Schema draft 2020-12, and it is the one source of the rules for a single file. The rules that compare values with each other, in one file or across files, are listed under [Rules across values and files](#rules-across-values-and-files).

This is the first edition of version 1. Version 1 grows only by additions: a new field type, a new optional choice, a new enum value or a new `kind` keeps the marker `blueprint: 1`, and a file that is valid stays valid and keeps its meaning. A change that would make a valid file invalid or change its meaning is version 2.

## Editors

`php artisan cms:schema:editor` (in `cboxdk/cms-generators`) gives every blueprint file below the schema roots a first line such as `# yaml-language-server: $schema=../vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json`. The path is relative to the file and points at this schema in the installed `cboxdk/cms-contracts`, so an editor with yaml-language-server, such as Red Hat's YAML extension for VS Code, completes and checks the file against the same version of the schema that `cms:generate` validates against, offline. The command replaces a line with another path, keeps the rest of the file as it is and changes nothing when every file has the right line. Run it again after moving a file or adding one. It skips every schema root below `vendor/`, such as an addon's `vendor/acme/shop/schema`, and names it: Composer installs those files and would see an edited package as changed, so the installation leaves them as the package ships them. `cms:generate` still reads them.

## Dates must be quoted

Write every date and time in quotes: `min: '2026-01-01'`, not `min: 2026-01-01`. A YAML parser reads an unquoted date as a number, so the schema refuses it at the field's `min` or `max`. The same holds for a `datetime` value, such as `'2026-01-01T00:00:00Z'`, and for the `min` and `max` of a `decimal`, which are strings so that they are read without rounding.

## The document

One definition per file. Every file starts with the format marker and the kind:

| Key | Value |
|---|---|
| `blueprint` | `1`, required. The schema refuses any other version. |
| `kind` | `type` or `extension`, required. |

A `type` has these keys:

| Key | Required | Value |
|---|---|---|
| `type_id` | yes | A UUIDv7 in lowercase. It stays the same when the type is renamed (PRD 11.2). |
| `handle` | yes | The type's handle, see [Handles](#handles). |
| `label` | yes | The name shown in the panel, 1 to 100 characters. |
| `description` | no | What the type is for, 1 to 1,000 characters. |
| `version` | yes | An integer from 1. The owner raises it when the definition changes (PRD 11.4). |
| `capabilities` | yes | See [Capabilities](#capabilities). |
| `fields` | yes | 1 to 200 fields, in the order the form shows them. The type's own fields and those extensions add are at most 200 together. |

An `extension` adds fields to a type that someone else owns (PRD 11.12). The fields belong to the extender's namespace:

| Key | Required | Value |
|---|---|---|
| `extends` | yes | The `type_id` of the type it extends. |
| `version` | yes | An integer from 1: the extender's part of the type's composite version (PRD 11.2). Every extension file of one owner for one type has the same version. |
| `fields` | yes | 1 to 200 fields. The type's own fields and those extensions add are at most 200 together. |

No other keys are allowed in either kind.

## Handles

A handle is lowercase snake_case: a lowercase letter, then lowercase letters, digits and single underscores, at most 63 bytes. A double underscore is not allowed, because it separates the parts of an extension's column name (PRD 11.12). The handle `ext` and every handle that starts with `cms_` are reserved. The rules apply to the handles of types, fields and select options.

## Capabilities

| Key | Required | Values |
|---|---|---|
| `history` | yes | `full`, `audit-only` or `none` |
| `stages` | yes | `none` or `draft-release` |
| `localization` | yes | `none` |
| `routable` | no | `true` or `false`, default `false` |

## Fields

Every field has these keys:

| Key | Required | Value |
|---|---|---|
| `handle` | yes | The field's handle. |
| `label` | yes | 1 to 100 characters. |
| `type` | yes | A core field type from the table below, or an addon's field type. |
| `description` | when agents see the field | What the field holds, 1 to 1,000 characters. MCP tools and agents read it, and the panel shows it as help text (PRD 14.5). |
| `agents` | no | Whether MCP tools and agents see the field. The default follows the classification, see [Agents](#agents). |
| `required` | no | `true` or `false`, default `false`. |

A field in `fields` at the top of the document also has:

| Key | Required | Value |
|---|---|---|
| `classification` | yes | `public`, `internal` or `confidential` (PRD 12.2). `personal` and `sensitive` are not in this edition, see [Personal data](#personal-data). |
| `filterable` | no | `true` or `false`, default `false`. |
| `sortable` | no | `true` or `false`, default `false`. |

A field that is `confidential` cannot be `filterable` or `sortable`. The types `long_text`, `rich_text` and `group` cannot have either key. A field inside a group has none of the three keys: it inherits the group's classification.

### Agents

Whether MCP tools and agents see a field follows its classification (PRD 2.31, 12.2), so a field is never exposed to agents because its author left a key out:

| Classification | Without `agents` | `agents: true` |
|---|---|---|
| `public` | seen | seen |
| `internal` | seen | seen |
| `confidential` | hidden | seen |

`agents: false` hides any field. A field inside a group has the group's value unless it says otherwise. An MCP token reaches at most `confidential` fields, and agents never see `personal` or `sensitive` data (PRD 2.31, 12.2), which this edition does not have, see [Personal data](#personal-data). A field that agents see needs a `description`; a hidden one may leave it out.

### Personal data

This edition refuses the classifications `personal` and `sensitive` (PRD 12.2). A field that holds personal data declares in its blueprint the purpose of the processing, its legal basis, its retention and its recipients, and the compiler refuses the field without them (PRD 12.14). It also declares which subject the data belongs to, so that the subject's key encrypts it and erasure reaches it (PRD 12.3, 12.4). `cms:ropa`, crypto-shredding and requests from data subjects read these declarations. This edition has no keys for them, so the schema refuses `classification: personal` and `classification: sensitive` at the field's `classification`, and `cms:generate` refuses them with `generate_schema_invalid`.

Both classifications arrive as an addition to version 1, together with the keys they require. No valid file can use them now, so every valid file stays valid and keeps its meaning when they arrive.

## Core field types

| Type | What | Choices |
|---|---|---|
| `text` | one line of text | `min_length`, `max_length` (default 255, at most 10,000), `format`: `plain` (default), `email` or `url` |
| `long_text` | lines of plain text | `min_length`, `max_length` (default 10,000) |
| `integer` | a whole number | `min`, `max`, `unit` |
| `decimal` | a decimal number without rounding | `precision` (1 to 38) and `scale` (0 to 38) are required; `min` and `max` as quoted strings; `unit` |
| `boolean` | a flag | none |
| `date` | a date | `min`, `max`, quoted |
| `datetime` | a time in UTC | `min`, `max`, quoted, in RFC 3339 with an offset such as `Z` or `+01:00` |
| `select` | a choice from a fixed list | `options`, a list of 1 to 500 items with `value` (a handle) and `label`, is required; `multiple`; `min_items` and `max_items` only with `multiple: true` |
| `rich_text` | Portable Text (PRD 11.10) | `styles`, `marks`, `lists`, `links`: `url` |
| `group` | nested fields, once or repeated | `fields` is required; `repeat` with `min_items` and `max_items` (at most 500) |

A choice that the field's type does not have is refused.

## Addon field types

An addon contributes a field type as `<namespace>:<handle>` (PRD 13.1), such as `acme:colour`. The namespace is the addon's name: a lowercase letter and up to 19 lowercase letters or digits. `app` and `ext` are reserved (PRD 11.12) and are never an addon's name, so `app:colour` and `ext:colour` are refused. The choices of an addon's field type sit under `options`, an object, so they never collide with a choice that version 1 adds to every field later. The published schema checks only the form of the name and that `options` is an object; the addon's own JSON Schema describes what goes in it. `cms:generate` resolves the type of every field in its registry of field types, the core field types included, which the core registers through the same extension point as any other contributor, and refuses a field type that no contributor registers. A contributor registers its field types only in its own namespace; only the core registers names without one.

## Rules across values and files

JSON Schema checks one file at a time and cannot compare values with each other. `cms:generate` reads every blueprint file below the schema roots and also checks these rules. Each has its own error code, and each problem names the file and the JSON pointer of the value that breaks the rule; when two places collide, the later file or item is named with the earlier one.

| Rule | Error code |
|---|---|
| Every type has its own `type_id`, across the application, modules and addons. | `generate_duplicate_type_id` |
| The types of one owner have different handles. Two owners may each have a type with the same handle: the generated code names a type by its owner and handle, `<owner>:<handle>` such as `acme:product`, so a module that adds a type later never collides with a type of the application. | `generate_duplicate_type_handle` |
| The fields of one namespace have different handles: the fields of a type, the fields that one owner adds to one type in all its extension files, and the fields of each group. | `generate_duplicate_field_handle` |
| The options of a `select` field have different values. | `generate_duplicate_select_value` |
| `extends` is the `type_id` of a type in a blueprint file below the schema roots. | `generate_unknown_extends_target` |
| `extends` is the `type_id` of a type of another owner. A type has one owner and only others extend it, so an owner adds fields to its own type in the type file. | `generate_extension_of_own_type` |
| The extension files of one owner for one type have the same `version`. Their fields are one namespace, and its version is the extender's part of the type's composite version, which upcasters are keyed on. | `generate_extension_version_mismatch` |
| A column name has at most 63 bytes. A field that an extension adds has the column `ext__<namespace>__<handle>`, where the namespace is the extender's, `app` for the application, so its handle has at most 56 bytes less the length of the namespace: 53 for `app`. | `generate_column_name_too_long` |
| A type has at most 200 top-level fields, its own and those every extension adds to it together, because each is a column of the type's table (PRD 11.6). A file holds at most 200 fields, and the rule caps the type once the extension files of every owner are added, however many files an owner splits its fields over. The problem names the type file's `/fields` and the extension files that add to the type. | `generate_too_many_fields` |
| `min` is at most `max`. Decimals are compared by value, dates by day and times as instants. | `generate_min_above_max` |
| `min_length` is at most `max_length`, or at most the default `max_length` when the field has none. | `generate_min_length_above_max_length` |
| `min_items` is at most `max_items`, on a `select` field and in the `repeat` of a group. | `generate_min_items_above_max_items` |
| The `scale` of a `decimal` is at most its `precision`. | `generate_scale_above_precision` |
| A field type `<namespace>:<handle>` is one that a contributor has registered (PRD 13.3). | `generate_unknown_field_type` |
| The types of one owner give different PHP enum cases, the owner and the handle in TitleCase: `item_2` and `item2` both give `AppItem2`, so an owner cannot have both. A type whose case would read `class` in any letter case, such as the handle `lass` of an owner `c`, is refused, because PHP reserves it. | `generate_invalid_case_name` |

An unknown `extends` is reported only when every file was read, because a file that cannot be read may be the one that defines the type.

## Examples

These examples are the test fixtures of the schema. `composer docs:check` checks that each block below is byte for byte the file it names, and the test at the end validates the three files against the schema, as an application's or addon's own tests can.

A type with every core field type:

<!-- example-file: packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/article.yaml -->
```yaml
blueprint: 1
kind: type
type_id: 0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b
handle: article
label: Article
description: A news article with a headline, a body and its credits.
version: 1
capabilities:
  history: full
  stages: draft-release
  localization: none
  routable: true
fields:
  - handle: title
    label: Headline
    description: The headline as it is shown on the page and in lists.
    type: text
    max_length: 120
    required: true
    classification: public
    sortable: true
  - handle: summary
    label: Summary
    description: A short plain-text summary for lists and search results.
    type: long_text
    max_length: 500
    classification: public
  - handle: reading_minutes
    label: Reading time
    description: The estimated reading time in whole minutes.
    type: integer
    min: 1
    max: 120
    unit: min
    classification: public
    filterable: true
  - handle: rating
    label: Rating
    description: The editors' rating of the article, from 0 to 5.
    type: decimal
    precision: 3
    scale: 2
    min: '0'
    max: '5.00'
    classification: internal
  - handle: featured
    label: Featured
    description: Whether the article is shown on the front page.
    type: boolean
    classification: public
    filterable: true
  - handle: event_date
    label: Event date
    description: The date of the event the article covers.
    type: date
    min: '2000-01-01'
    classification: public
    sortable: true
  - handle: embargo_until
    label: Embargo until
    description: The time before which the article may not be published.
    type: datetime
    min: '2000-01-01T00:00:00Z'
    classification: internal
  - handle: section
    label: Section
    description: The sections of the site the article is listed under.
    type: select
    options:
      - value: news
        label: News
      - value: sport
        label: Sport
      - value: culture
        label: Culture
    multiple: true
    min_items: 1
    max_items: 2
    classification: public
    filterable: true
  - handle: body
    label: Body
    description: The body text of the article.
    type: rich_text
    styles: [normal, h2, h3, blockquote]
    marks: [strong, em]
    lists: [bullet, number]
    links: [url]
    classification: public
  - handle: credits
    label: Credits
    description: The people who made the article, in the order they are credited.
    type: group
    repeat:
      min_items: 1
      max_items: 10
    fields:
      - handle: name
        label: Name
        description: The name of the person as it is printed.
        type: text
        max_length: 100
        required: true
      - handle: role
        label: Role
        type: text
        max_length: 50
        agents: false
    classification: confidential
```

An extension that adds a field to another owner's type:

<!-- example-file: packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/extension.yaml -->
```yaml
blueprint: 1
kind: extension
extends: 0192a3b4-c5d6-7e8f-9a0b-aaaaaaaaaaaa
version: 1
fields:
  - handle: tax_code
    label: Tax code
    description: The customer's tax code for the product.
    type: text
    max_length: 20
    classification: internal
```

A type with a field of an addon's field type, `acme:colour`, and its choices under `options`:

<!-- example-file: packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/addon-field-type.yaml -->
```yaml
blueprint: 1
kind: type
type_id: 0192a3b4-c5d6-7e8f-9a0b-bbbbbbbbbbbb
handle: product
label: Product
description: A product in the shop.
version: 1
capabilities:
  history: audit-only
  stages: none
  localization: none
fields:
  - handle: name
    label: Name
    description: The name of the product.
    type: text
    required: true
    classification: public
  - handle: colour
    label: Colour
    description: The colour of the product, picked from the shop's palette.
    type: acme:colour
    options:
      palette: shop
      allow_custom: false
    classification: public
```

The test that validates them, in the `Codecs` suite:

<!-- example: examples/Codecs/Blueprint/ValidBlueprintsTest.php -->
```php
<?php

declare(strict_types=1);

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Symfony\Component\Yaml\Yaml;

// Validates blueprint files against blueprint.v1.json from the installed cboxdk/cms-contracts, the
// schema cms:generate validates against. Read YAML with PARSE_OBJECT_FOR_MAP, so that a map stays
// an object, and validate with CompliantValidator, which never writes defaults into the data.

it('accepts the blueprint file', function (string $file): void {
    $root = dirname(__DIR__, 3);
    $schema = file_get_contents($root.'/vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json')
        ?: throw new RuntimeException('Cannot read blueprint.v1.json.');
    $blueprint = Yaml::parseFile($root.'/'.$file, Yaml::PARSE_OBJECT_FOR_MAP);

    $error = new CompliantValidator()->validate($blueprint, $schema)->error();

    expect($error instanceof ValidationError ? new ErrorFormatter()->format($error) : [])->toBe([]);
})->with([
    'the type article' => ['packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/article.yaml'],
    'the extension of another owner\'s type' => ['packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/extension.yaml'],
    'the type product with the addon field type acme:colour' => ['packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/addon-field-type.yaml'],
]);
```
