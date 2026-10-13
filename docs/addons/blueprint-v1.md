---
title: Blueprint schema v1
weight: 38
description: "The reference of blueprint.v1.json: types, extensions of another owner's type, fields, core and addon field types, the presentation keys of the panel's forms, and the rules cms:generate checks across files."
---

# Blueprint schema, version 1

<!-- extension-point: packages/contracts/resources/schemas/blueprint.v1.json -->

A blueprint file defines one content type, or adds fields to another owner's type. Blueprint files live under `schema/**/*.yaml` (PRD 11.12). Their format is the JSON Schema [`blueprint.v1.json`](../../packages/contracts/resources/schemas/blueprint.v1.json), JSON Schema draft 2020-12, which an installed application finds at `vendor/cboxdk/cms/packages/contracts/resources/schemas/blueprint.v1.json`, and it is the one source of the rules for a single file. The rules that compare values with each other, in one file or across files, are listed under [Rules across values and files](#rules-across-values-and-files).

This is the first edition of version 1. Version 1 grows only by additions: a new field type, a new optional choice, a new enum value or a new `kind` keeps the marker `blueprint: 1`, and a file that is valid stays valid and keeps its meaning. A change that would make a valid file invalid or change its meaning is version 2.

## Editors

`php artisan cms:schema:editor` gives every blueprint file below the schema roots a first line such as `# yaml-language-server: $schema=../vendor/cboxdk/cms/packages/contracts/resources/schemas/blueprint.v1.json`. The path is relative to the file and points at this schema in the installed `cboxdk/cms`, so an editor with yaml-language-server, such as Red Hat's YAML extension for VS Code, completes and checks the file against the same version of the schema that `cms:generate` validates against, offline. The command replaces a line with another path, keeps the rest of the file as it is and changes nothing when every file has the right line. Run it again after moving a file or adding one. It skips every schema root below `vendor/`, such as an addon's `vendor/acme/shop/schema`, and names it: Composer installs those files and would see an edited package as changed, so the installation leaves them as the package ships them. `cms:generate` still reads them.

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
| `label` | yes | The name shown in the panel, 1 to 100 characters, or a map of locale to such a text, see [Texts per language](#texts-per-language). |
| `description` | no | What the type is for, 1 to 1,000 characters, or a map of locale to such a text. |
| `version` | yes | An integer from 1. The owner raises it when the definition changes (PRD 11.4). |
| `capabilities` | yes | See [Capabilities](#capabilities). |
| `fields` | yes | 1 to 200 fields, in the order the form shows them. The type's own fields and those extensions add are at most 200 together. |
| `layout` | no | The tabs and sidebar of the type's form, see [Layout](#layout). Without it the fields land in one tab of one section in file order. |

An `extension` adds fields to a type that someone else owns (PRD 11.12). The fields belong to the extender's namespace:

| Key | Required | Value |
|---|---|---|
| `extends` | yes | The `type_id` of the type it extends. |
| `version` | yes | An integer from 1: the extender's part of the type's composite version (PRD 11.2). Every extension file of one owner for one type has the same version. |
| `fields` | yes | 1 to 200 fields. The type's own fields and those extensions add are at most 200 together. |
| `layout` | no | A layout fragment that places the file's fields in the type's form, see [Layout](#layout). Without it the fields land in the tab `extensions`. |

No other keys are allowed in either kind.

## Handles

A handle is lowercase snake_case: a lowercase letter, then lowercase letters, digits and single underscores, at most 63 bytes. A double underscore is not allowed, because it separates the parts of an extension's column name (PRD 11.12). The handle `ext` and every handle that starts with `cms_` are reserved. The rules apply to the handles of types, fields and select options.

## Capabilities

| Key | Required | Values |
|---|---|---|
| `history` | yes | `full`, `audit-only` or `none` |
| `stages` | yes | `none` or `draft-release` |
| `localization` | yes | `none`, one shared variant that holds every field, or `variants`, a variant per language that holds the fields declared `translatable` and the shared variant that holds the others (PRD 10.1) |
| `routable` | no | `true` or `false`, default `false` |

## Fields

Every field has these keys:

| Key | Required | Value |
|---|---|---|
| `handle` | yes | The field's handle. |
| `label` | yes | 1 to 100 characters, or a map of locale to such a text, see [Texts per language](#texts-per-language). |
| `type` | yes | A core field type from the table below, or an addon's field type. |
| `description` | when agents see the field | What the field holds, 1 to 1,000 characters, or a map of locale to such a text. MCP tools and agents read it, and the panel shows it as help text (PRD 14.5). |
| `agents` | no | Whether MCP tools and agents see the field. The default follows the classification, see [Agents](#agents). |
| `required` | no | `true` or `false`, default `false`. |
| `ui` | no | The hints the panel's form takes for the input, see [Form hints](#form-hints). |
| `default` | no | The value the form starts the input with, see [Form defaults](#form-defaults). |

A field in `fields` at the top of the document also has:

| Key | Required | Value |
|---|---|---|
| `classification` | yes | `public`, `internal` or `confidential` (PRD 12.2). `personal` and `sensitive` are not in this edition, see [Personal data](#personal-data). |
| `filterable` | no | `true` or `false`, default `false`. |
| `sortable` | no | `true` or `false`, default `false`. |
| `translatable` | no | `true` or `false`, default `false`. In a type with `localization: variants`, which variant holds the field (PRD 10.1): a language's variant when `true`, the shared variant when `false`. Only a type with `localization: variants` may have it. |
| `fallback` | no | `variant` or `field`, default `variant`. How a translatable field falls back when the reader's language has no value for it (PRD 10.3): with its whole variant along the site's chain, so a page never mixes languages, or on its own. Only a translatable field may have it. |

A field that is `confidential` cannot be `filterable` or `sortable`. The types `long_text`, `rich_text` and `group` cannot have either key. A field inside a group has none of the five keys: it inherits the group's classification, `translatable` and `fallback`. A field inside a group may have `ui` and `default`.

## Presentation keys

The keys of this section are presentation only (gap G2): they reach the editor descriptor that `cms:generate` writes for the panel's forms, see [type editor descriptors](type-editors.md), and nothing else. They change no record, no JSON codec, no validator and no column of the type's table, so a change of them is no new version of the type and needs no migration.

### Texts per language

Every `label` and `description`, of a type, a field, a select option, a tab or a section, and the `instructions` and `input_hint` of a form, is one text, such as `label: Title`, or a map of locale to text, such as `label: { en: Title, da: Titel }`. A locale is a language of two or three letters with an optional script and region, such as `da`, `en-GB` or `zh-Hant-TW`, and a map has at least one entry. Where one text is needed, the descriptor, the catalog, the records' documentation and the tools agents read take the first text of the map in file order, so list the primary language first. The editor descriptor carries every text with its locale, so a form shows the panel's language and falls back to the first.

### Layout

`layout` places the top-level fields of its file in tabs and a sidebar:

| Key | Required | Value |
|---|---|---|
| `tabs` | one of the two | 1 to 50 tabs, each with a `handle`, a `label` and 1 to 50 `sections`. |
| `sidebar` | one of the two | An object with 1 to 50 `sections`, shown beside the tabs. An extension's fragment may place its fields in the sidebar alone. |

A section has a `handle`, an optional `label` and `instructions`, `collapsible` (`true` or `false`, default `false`, whether the form lets the editor fold it) and 1 to 200 `fields`, each a placement with the `field` (a handle of the file) and a `width`: `full` (the default), `half`, `third` or `two_thirds` of the section's row. Tab handles are unique in a layout, and section handles within a tab and within the sidebar.

Every top-level field of the file is placed exactly once: a layout that leaves one out, places one twice or places a handle the file has no top-level field for is refused, see [Rules across values and files](#rules-across-values-and-files). The fields inside a group are never placed, because the group's input holds them. A type without a `layout` gets the tab `content` with the section `fields` holding its fields in file order.

An extension places its own fields with a fragment of the same shape, as paths `ext.<namespace>.<handle>` in the type's form: a tab whose handle the type's layout has joins that tab, and in it a section whose handle the tab has gets the placements appended; every other tab or section is appended, and the sidebar's sections merge the same way. An extension without a fragment lands in the tab `extensions`, in a section named after its namespace. So every field of the type is in the form exactly once, whatever the files say.

### Form hints

`ui` holds what a form needs beyond the field's type:

| Key | Value |
|---|---|
| `instructions` | A text, or a map of locale to text, shown with the input, 1 to 1,000 characters. |
| `input_hint` | A text, or a map, shown in the empty input, 1 to 100 characters. |
| `read_only` | `true` or `false`, default `false`: the form shows the value without an input to change it. |
| `hidden` | `true` or `false`, default `false`: the form hides the input. |
| `display` | `radio`, `buttons` or `dropdown`: how a `select` shows its choices. Only a `select` field may have it. |
| `summary` | The summary template of a repeated group, 1 to 200 characters, which names the group's nested fields in braces, such as `{title} ({year})`, so a collapsed item shows its values. Only a `group` with `repeat` may have it. |

A read-only or hidden field is still written by the commands and validated as its rules say; the hints bind the form, not the kernel.

### Form defaults

`default` is the value the form starts a new entry's input with, and nothing more: a command never fills it in, and an entry written without the field has no value. Its kind follows the field type: a string for `text` and `long_text`, an integer for `integer`, a decimal as a quoted string for `decimal`, `true` or `false` for `boolean`, a quoted date for `date` or the token `today`, a quoted time with its offset for `datetime` or the token `now`, an option's value for `select`, or a list of them with `multiple: true`. The tokens are resolved by the form from the clock when it opens. `rich_text` and `group` take no default, and an addon's field type takes what its base takes.

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
| `group` | nested fields, once or repeated | `fields` is required; `repeat` with `min_items` (at most 500) and `max_items` (default 500, at most 500), so a repeated group holds at most 500 items (PRD 11.6) |

A choice that the field's type does not have is refused.

## Addon field types

An addon contributes a field type as `<namespace>:<handle>` (PRD 13.1), such as `acme:colour`. The namespace is the addon's name: a lowercase letter and up to 19 lowercase letters or digits. `app` and `ext` are reserved (PRD 11.12) and are never an addon's name, so `app:colour` and `ext:colour` are refused. The choices of an addon's field type sit under `options`, an object, so they never collide with a choice that version 1 adds to every field later. The published schema checks only the form of the name and that `options` is an object; the addon's own JSON Schema describes what goes in it, and `cms:generate` reports options that break it, or that the field type cannot make a shape of, as `generate_schema_invalid`. `cms:generate` resolves the type of every field in its registry of field types, the core field types included, which the core registers through the same extension point as any other contributor, and refuses a field type that no contributor registers. Each installed addon registers its field types through the contributor its manifest names, only in its own namespace; only the core registers names without one. Every generator writes a field of an addon's field type as the core field type of its shape. [Addon field types](field-types.md) says how an addon contributes one.

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
| `min_items` is at most `max_items`, on a `select` field and in the `repeat` of a group, where it is at most the default `max_items` when the repeat has none. | `generate_min_items_above_max_items` |
| The `scale` of a `decimal` is at most its `precision`. | `generate_scale_above_precision` |
| A field type `<namespace>:<handle>` is one that a contributor has registered (PRD 13.3): an addon whose manifest lists it and that `schema.php` holds. | `generate_unknown_field_type` |
| The types of one owner give different PHP enum cases, the owner and the handle in TitleCase: `item_2` and `item2` both give `AppItem2`, so an owner cannot have both. A type whose case would read `class` in any letter case, such as the handle `lass` of an owner `c`, is refused, because PHP reserves it. | `generate_invalid_case_name` |
| The fields, options and extender namespaces of one type give different names in the type's generated PHP records, compared without case as PHP compares class names: a field's property is its handle in camelCase, so `size_1` and `size1` both give `$size1`; the enum of a select field, the class of a group and of an item of a repeated group take the handle in TitleCase with `Choice`, `Group` or `Item`, below the group's name for a nested field and the namespace's for an extension field; and an option's case is its value in TitleCase. A field `this` and an option `class` are refused, because PHP reserves them. The records' DTOs also give different classes: the group `b_v1` of the type `a` and the type `a_v1_b` both give the class `AppAV1BV1`. See [Records and JSON codecs](codecs.md). | `generate_name_collision` |
| `translatable` and `fallback` belong to the fields of a type with `localization: variants`, its own and those its extensions add; a type with `localization: none` has only the shared variant, which holds every field (PRD 10.1). | `generate_translatable_without_variants` |
| `fallback: field` belongs to a translatable field: only a translatable field falls back on its own (PRD 10.3). | `generate_fallback_without_translatable` |
| A `layout` places every top-level field of its file, a type's own fields in the type file and an extension's fields in its fragment. The problem is at the layout and names the field. | `generate_layout_field_omitted` |
| A `layout` places a field once. The second placement is named with the first. | `generate_layout_field_repeated` |
| A placement names a top-level field of its own file: no field inside a group, which the group's input holds, and no field of another owner. | `generate_layout_field_unknown` |
| A placement's `width` is `full`, `half`, `third` or `two_thirds`. The schema takes any word, so a new preset can arrive as an addition; `cms:generate` knows these four. | `generate_layout_width_unknown` |
| The tabs of a layout have different handles, and so do the sections of one tab and the sections of the sidebar, because a fragment joins a tab or a section by its handle. | `generate_layout_duplicate_handle` |
| A `default` is of the kind the field's type takes, see [Form defaults](#form-defaults): a `select` default is one of its options, or a list of them, each once, with `multiple: true`; `rich_text` and `group` take none. | `generate_default_wrong_kind` |
| The `ui.summary` of a repeated group names, in braces, only fields the group has. | `generate_summary_field_unknown` |
| A field declared `filterable` or `sortable` has an order the typed query builder can compare: a `select` field that allows several options with `multiple: true` has none, so it is neither. The blueprint schema already refuses both flags on `long_text`, `rich_text` and `group`. See [Type tables and query builders](contracts/type-table-reader.md). | `generate_field_not_queryable` |
| A type's table name, `<owner>__<handle>`, has at most 54 bytes, so the names of its row level security policies, `<table>_actor` and `<table>_released`, fit in Postgres' 63: an owner of `app` leaves 49 bytes for the handle. | `generate_table_name_too_long` |
| A schema lock in the migrations directory, `<table>.lock`, is exactly what `cms:generate` wrote, and its file is named after its table. | `generate_lock_invalid` |
| Until schema evolution comes (B3), a type table only grows. A type whose table has a schema lock stays in the schema. | `generate_type_removed` |
| A type keeps the `type_id`, `stages` and `localization` of its schema lock, because they decide its table's key and system columns. | `generate_table_changed` |
| A field whose column is in its type's schema lock stays in the schema. | `generate_field_removed` |
| A field keeps the column type, NOT NULL, CHECK constraints and index of its type's schema lock: its type, options, `required`, classification, `filterable` and `sortable` stay as they were. | `generate_field_changed` |
| A field added to a type that has a table is optional, so its column is nullable on the rows that exist. An extension field is always nullable. | `generate_required_field_added` |

An unknown `extends` is reported only when every file was read, because a file that cannot be read may be the one that defines the type.

## What cms:generate compiles

Once the rules hold, `cms:generate` compiles every type, with the fields every extension adds to it, into a type descriptor, and every generator reads the descriptor instead of the blueprint files (PRD 11.12). The descriptor holds the type's `type_id`, owner, handle, version, the version of each extender's namespace, its capabilities, and for each field its column, its Postgres type with NOT NULL and CHECK constraints, its PHP and TypeScript types, the rules of its runtime validator, its classification, whether agents see it, whether it is filterable or sortable, whether it is encrypted, and whether it is translatable and how it falls back. The presentation keys ride along for the editor descriptor alone: the canonical JSON of the descriptor, which `schema_versions` stores, leaves them out.

| Field type | Column | PHP | TypeScript |
|---|---|---|---|
| `text`, `long_text` | `text`, with its length checked in characters | `string` | `string` |
| `integer` | `bigint`, with its bounds | `int`, as `int<min, max>` with bounds | `number` |
| `decimal` | `numeric(precision, scale)`, with its bounds | a numeric string, never a float | `string` |
| `boolean` | `boolean` | `bool` | `boolean` |
| `date` | `date`, with its bounds | `DateTimeImmutable` | `string`, a full date of RFC 3339 |
| `datetime` | `timestamptz`, with its bounds | `DateTimeImmutable` | `string`, a date-time of RFC 3339 |
| `select` | `text`, one of the values, or `text[]` of them with `multiple: true` | the values as literal types, or a list of them | the values as a union, or an array of them |
| `rich_text` | `jsonb`, a list of Portable Text blocks | a list of blocks | an array of blocks |
| `group` | `jsonb`, an object, or a list of at most `max_items` objects with `repeat` | an array shape of its fields, or a list of them | an object type of its fields, or an array of them |

A few rules decide more than the field type does:

- The column of a field of the type's owner is its handle; the column of an extension field is `ext__<namespace>__<handle>`. The fields inside a group have no column of their own: the group is one `jsonb` column.
- A `confidential` field is encrypted (PRD 12.2): its column is `bytea` holding ciphertext, without checks, whatever its type. The fields inside a confidential group are encrypted with it.
- A required field of the type's owner is NOT NULL, and its PHP and TypeScript types are not nullable. An extension field never is, even with `required: true`, because the owner's code creates and revises entries without knowing it; its `required` is enforced when an entry is published (PRD 11.12). Every other field is nullable.
- A `rich_text` field that leaves out `styles`, `marks`, `lists` or `links` allows every one of that list.

From the descriptors, `cms:generate` writes the type enum and the TypeScript types, and for each type a record DTO, its JSON codec and a TypeScript module with its validator, described on [Records and JSON codecs](codecs.md).

It also writes the migrations of the type tables (PRD 4.1, 11.6) to `database/migrations/cms` (`cbox-cms.generators.migrations_directory`), which the generated service provider registers with the migrator; they run with `php artisan migrate` as the owner role, never on their own. A type's table is `<owner>__<handle>`, such as `app__blog_post`. It has the system columns `cms_entry_id`, `cms_locale` (`shared` for a type with `localization: none`, which has that one row per entry and stage; a language for a type with `localization: variants`, which has a row per language with the translatable fields of that language and the shared fields in each, PRD 10.1, 11.6), `cms_stage` (`released`, and `draft` and `staged` for a type with stages), `cms_home_node` and `cms_owner_actor`, the key (`cms_entry_id`, `cms_locale`, `cms_stage`), a column per top-level field as the table above describes, an index on each foreign key, an index on (`cms_stage`, `cms_locale`, the column, `cms_entry_id`) for each `filterable` or `sortable` field, and fillfactor 80. Row level security is enabled and forced with the policies every type table has, and the app role may only select, insert, update and delete.

Next to the migrations, `cms:generate` writes a schema lock for each table, `<table>.lock`: the columns the migrations build, step by step. Commit it with the migrations. A new type gets a lock and a `<table>_0001_create` migration, and new optional fields get the next step and one `<table>_<step>_add_columns` migration that adds their nullable columns and builds their indexes concurrently. Until schema evolution comes (B3), every other change to a type that has a table is refused by the rules above.

The rules of each field's runtime validator become a validator per type, which checks input from outside the repository against the same schema; see [Runtime validators](validation.md).

## Examples

These examples are the test fixtures of the schema. `composer docs:check` checks that each block below is byte for byte the file it names, and the test at the end validates the five files against the schema, as an application's or addon's own tests can.

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

A type with `localization: variants`: `title` and `teaser` live in each language's variant, `teaser` falls back on its own, and `published_on` lives in the shared variant.

<!-- example-file: packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/localized.yaml -->
```yaml
blueprint: 1
kind: type
type_id: 0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6e
handle: notice
label: Notice
description: A notice in several languages, with a title and a teaser per language and a date shared by every language.
version: 1
capabilities:
  history: full
  stages: draft-release
  localization: variants
  routable: true
fields:
  - handle: title
    label: Title
    description: The title of the notice in the language.
    type: text
    required: true
    classification: public
    translatable: true
  - handle: teaser
    label: Teaser
    description: A short teaser in the language; shown in another language of the chain when the language has none.
    type: long_text
    classification: public
    translatable: true
    fallback: field
  - handle: published_on
    label: Published on
    description: The day the notice was published, the same in every language.
    type: date
    required: true
    classification: public
    sortable: true
```

The test that validates them, in the `Codecs` suite:

A type with the presentation keys: labels per language, a layout of two tabs and a sidebar, form hints and form defaults.

<!-- example-file: packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/layout.yaml -->
```yaml
# A type with the presentation keys of version 1 (gap G2): a label and a description per locale,
# a layout of two tabs and a sidebar that places every top-level field once, ui hints on a text,
# a select and a repeated group, and form-only defaults. They reach only the editor descriptor
# that cms:generate writes for the panel's forms.
blueprint: 1
kind: type
type_id: 0192a3b4-c5d6-7e8f-9a0b-0c1d2e3f4a5c
handle: event
label:
  en: Event
  da: Begivenhed
description:
  en: An event with a name, a day and its sessions.
  da: En begivenhed med et navn, en dag og dens sessioner.
version: 1
capabilities:
  history: full
  stages: draft-release
  localization: none
  routable: true
fields:
  - handle: name
    label:
      en: Name
      da: Navn
    description: The name of the event.
    type: text
    required: true
    classification: public
    ui:
      input_hint:
        en: The name on the poster
        da: Navnet på plakaten
  - handle: held_on
    label: Held on
    description: The day the event is held.
    type: date
    classification: public
    default: today
  - handle: format
    label: Format
    description: How the event is held.
    type: select
    classification: public
    default: in_person
    ui:
      display: radio
      instructions:
        en: Online events need no venue.
        da: Online begivenheder har ikke brug for et sted.
    options:
      - value: in_person
        label:
          en: In person
          da: Fysisk
      - value: online
        label: Online
  - handle: seats
    label: Seats
    description: How many seats the venue has.
    type: integer
    classification: internal
    min: 0
    default: 100
  - handle: sessions
    label: Sessions
    description: The sessions of the event.
    type: group
    classification: public
    repeat:
      max_items: 20
    ui:
      summary: "{title} ({minutes} min)"
    fields:
      - handle: title
        label: Title
        description: The title of the session.
        type: text
        required: true
      - handle: minutes
        label: Minutes
        description: How long the session takes.
        type: integer
        min: 1
        default: 45
  - handle: notes
    label: Notes
    description: Notes for the organisers.
    type: long_text
    classification: internal
    ui:
      read_only: true
layout:
  tabs:
    - handle: content
      label:
        en: Content
        da: Indhold
      sections:
        - handle: about
          fields:
            - field: name
              width: two_thirds
            - field: format
              width: third
        - handle: programme
          label: Programme
          instructions: One session per row.
          collapsible: true
          fields:
            - field: sessions
    - handle: internal
      label: Internal
      sections:
        - handle: organisers
          fields:
            - field: notes
  sidebar:
    sections:
      - handle: when
        label: When
        fields:
          - field: held_on
            width: half
          - field: seats
            width: half
```

<!-- example: examples/Codecs/Blueprint/ValidBlueprintsTest.php -->
```php
<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Symfony\Component\Yaml\Yaml;

// Validates blueprint files against blueprint.v1.json from the installed cboxdk/cms, the schema
// cms:generate validates against, found through Composer. Read YAML with PARSE_OBJECT_FOR_MAP, so that a map stays
// an object, and validate with CompliantValidator, which never writes defaults into the data.

it('accepts the blueprint file', function (string $file): void {
    $root = dirname(__DIR__, 3);
    $schema = file_get_contents(InstalledVersions::getInstallPath('cboxdk/cms').'/packages/contracts/resources/schemas/blueprint.v1.json')
        ?: throw new RuntimeException('Cannot read blueprint.v1.json.');
    $blueprint = Yaml::parseFile($root.'/'.$file, Yaml::PARSE_OBJECT_FOR_MAP);

    $error = new CompliantValidator()->validate($blueprint, $schema)->error();

    expect($error instanceof ValidationError ? new ErrorFormatter()->format($error) : [])->toBe([]);
})->with([
    'the type article' => ['packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/article.yaml'],
    'the extension of another owner\'s type' => ['packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/extension.yaml'],
    'the type product with the addon field type acme:colour' => ['packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/addon-field-type.yaml'],
    'the type notice with localization variants' => ['packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/localized.yaml'],
    'the type event with labels per locale, a layout, ui hints and defaults' => ['packages/contracts/tests/Codecs/Fixtures/Blueprint/valid/layout.yaml'],
]);
```
