---
title: Add a content type
weight: 41
description: "Add a content type as one blueprint file: the schema line, cms:generate with its code, TypeScript and migration, check:generated, and the tests that list the workbench's types."
---

# Add a content type

The kernel knows no content types (GUARDRAILS 2.4). A type is one blueprint file, and everything else about it is generated from that file. Adding a type therefore changes the schema, the generated code, and the few tests that list the workbench's types by name, and nothing below `packages/`. The workbench's third type, `app:fixture_event`, was added this way (M1-T50).

## Inputs

- **owner**: the owner of the schema root, `app` for the workbench's `workbench/schema`.
- **handle**: lowercase words joined by underscores. In the workbench every handle, field handle and select value starts with `fixture_`, because `tests/Arch/ContentTypesTest.php` fails on one that does not, and fails when a handle appears in the kernel's source.
- **type_id**: a new UUIDv7 in lowercase, unique across every blueprint. `php -r 'require "vendor/autoload.php"; echo Ramsey\Uuid\Uuid::uuid7(), PHP_EOL;'` prints one.
- **capabilities**: `history` (`full`, `audit-only` or `none`), `stages` (`draft-release` or `none`), `localization` (`none` or `variants`) and `routable`. An entry of a type with stages `none` has no release and is public once it is placed and published. A type with stages `draft-release` is released with `variant.release`, which the kernel allows only with history `full` and otherwise rejects as `type_not_releasable`.
- **localization**: with `none`, the one shared variant holds every field. With `variants` (PRD 10.1), every language is a variant of the entry with its own revisions and release, and each field says which variant holds it with `translatable: true` (the language's) or `false` (the shared one, the default); a translatable field may add `fallback: field` to fall back on its own along the site's chain instead of with its whole variant. Both keys belong to a `variants` type only, and `fallback: field` to a translatable field only; `cms:generate` refuses anything else with `generate_translatable_without_variants` and `generate_fallback_without_translatable`. The type table of a `variants` type holds one row per language with the shared fields in each (PRD 11.6), so its query builder takes `locale()`, and the entry commands of version 1 refuse such a type until version 2 writes the languages' variants. The workbench's `fixture_notice` is one.
- **fields**: each with its handle, core field type, `required`, `classification` and the options of its type, as [blueprint schema v1](../addons/blueprint-v1.md) lists them.
- **relations**, optional (PRD 5.11): a field of type `relation` names other entries by id, with `types`, the type names `<owner>:<handle>` a target may have, such as `[app:fixture_article]`; `multiple: true` for a list of up to `max_items` (at most 1,000) entries, each once. A relation is `public` or `internal` and never `filterable`, `sortable` or given a `default`. A to-one relation is a `uuid` column with a foreign key to `entries` ON DELETE RESTRICT and an index, which the migration adds; a multiple relation is a `jsonb` list of ids. `cms:generate` refuses a type name no blueprint defines with `generate_unknown_relation_type`, and a command refuses a target that does not exist or that the writer cannot read with `validation_relation_target_missing`, and one of another type with `validation_relation_target_type`, at the field's path. In a record a relation is an `EntryId`, or a list of them. The workbench's `fixture_measurement` has one of each, `fixture_corrects` and `fixture_cited_in`, and one inside its group `fixture_sensor`.
- **conditions**, optional (gap G3): a field shown only for some values of others has a `when`, such as `{ field: fixture_kind, eq: fixture_external }`, `in`, `neq`, `"null": true` (quoted in YAML), `not_null: true`, or `all` and `any` of conditions, over its siblings and with `../` the siblings of the groups it is in, and a `when_hidden` of `clear` or `keep`, which every conditional field needs. A hidden field is never required, and with `clear` holds no value; a conditional field's column is nullable. `cms:generate` refuses a reference it cannot reach, a circle, another owner's namespace, a field classified above the conditional field, a field of another variant and a comparison of the wrong kind, each with its own code: `generate_when_unknown_field`, `generate_when_cycle`, `generate_when_foreign_namespace`, `generate_when_classification_above`, `generate_when_other_variant` and `generate_when_value_wrong_kind`. See [Conditional fields](../addons/blueprint-v1.md#conditional-fields). The items of the workbench's `fixture_measurement` series have three conditional fields.
- **presentation**, optional and form only (gap G2): a `label` and `description` as one text or a map of locale to text, such as `{ en: Title, da: Titel }`; a `layout` of tabs and a sidebar that places every top-level field exactly once, each with a width preset; per field a `ui` with `instructions`, an `input_hint`, `read_only`, `hidden`, the `display` of a select and the `summary` template of a repeated group; and a `default` of the field's kind, or the tokens `today` and `now`. They reach only the editor descriptor the panel's forms render the type from: no record, codec, validator or column changes, so a change of them is no new version and needs no migration. `cms:generate` refuses a layout that leaves a field out or places one twice, an unknown width and a default of the wrong kind with `generate_layout_field_omitted`, `generate_layout_field_repeated`, `generate_layout_width_unknown` and `generate_default_wrong_kind`. The workbench's `fixture_article` declares two tabs and a sidebar with Danish and English labels.

From the owner and handle come the names every generator uses: the type's name `<owner>:<handle>`, its `TypeHandle` case `<Case>` (the owner and handle in TitleCase, `AppFixtureEvent`), and its table `<owner>__<handle>`.

## Files

| Path | What it holds | Written by |
|---|---|---|
| `workbench/schema/<handle>.yaml` | the blueprint | hand |
| `workbench/app/Cms/Generated/Boundary/<Case>CodecV1.php` and `Domain/Dto/<Case>V1*.php` | the record DTOs and JSON codec | generated |
| `workbench/app/Cms/Generated/Records/<Case>/` | the record interfaces, the composite record, its factory, a `*Choice` enum per select and a class per group | generated |
| `workbench/app/Cms/Generated/QueryBuilders/<Case>/` | `<Case>Query` with its filter and sort field enums | generated |
| `workbench/app/Cms/Generated/Validators/<Case>Validator.php` | the runtime validator, with the condition and `WhenHidden` of each conditional field, which the record's codec reads too | generated |
| `workbench/app/Cms/Generated/TypeHandle.php`, `GeneratedTypeCatalog.php`, `GeneratedTypeValidators.php`, `GeneratedRecordCodecs.php`, `GeneratedTypesServiceProvider.php` | the type added to the catalog, validators, codecs and bindings | generated |
| `workbench/app/Cms/Generated/GeneratedTypeEditors.php` | the type's editor descriptor: its layout, labels per locale, form hints and defaults with its fields | generated |
| `workbench/resources/js/cms/generated/index.ts` and `records/<Case>V1.ts` | the TypeScript types and validator | generated |
| `workbench/database/migrations/cms/<owner>__<handle>_0001_create.php` and `<owner>__<handle>.lock` | the type table's migration and its schema lock | generated |
| `tests/Feature/Workbench/WorkbenchSchemaTest.php` | the exact list of the workbench's blueprint files and generated files, and what the new type's blueprint reads as | hand |
| `tests/Postgres/WalkingSkeleton/RlsWithoutActorContextTest.php` | a row of the new type table in `TYPE_TABLE_ROWS`, with a value for each NOT NULL column | hand |
| `tests/Postgres/WalkingSkeleton/<Name>Test.php` | an end-to-end test, when the type has a combination of capabilities no other type has | hand |

## Steps

1. Write the blueprint in `workbench/schema/<handle>.yaml`: `blueprint: 1`, `kind: type`, the `type_id`, `handle`, `label`, `description`, `version: 1`, the capabilities and the fields, each conditional field with its `when` and `when_hidden`, and the presentation keys the form needs: a `layout` when the fields do not fit one section, `ui` and `default` per field, and the labels per language.
2. Run `vendor/bin/testbench cms:schema:editor`. It gives the file its first line, `# yaml-language-server: $schema=../../packages/contracts/resources/schemas/blueprint.v1.json`, so an editor validates the file as you write it.
3. Run `vendor/bin/testbench cms:generate`. It validates every blueprint, stops with a code such as `generate_duplicate_type_id` or `generate_schema_invalid` and the path of what is wrong, and otherwise writes the generated files above, the migration and the schema lock included. Never edit a generated file; change the blueprint and run the command again.
4. Update the two tests that list the workbench's types, then stage everything with `git add`. The migration runs in the tests on its own, because the Postgres harness runs `migrate:fresh`; for the shared dev database, `composer dev:prepare` runs it from the main checkout.
5. Run `composer check:generated`. It fails on generated files that are not staged or that differ from what `cms:generate` and `composer generate:protocol` write now.
6. Run `composer check`, and record the two changed tests, and any new one, in `CHECKS-LOG.md`.

A later change to the type is a new `version` of the same file. Until schema evolution comes (B3), a type table only grows: new optional fields give a `<owner>__<handle>_<step>_add_columns` migration, and `cms:generate` refuses every other change to a type that has a schema lock (`generate_field_changed`, `generate_field_removed`, `generate_table_changed`, `generate_type_removed`).

## Checks

- `tests/Feature/Workbench/WorkbenchSchemaTest.php` fails until it lists the new blueprint file and every file generated from it. That is on purpose: the list is the one place the workbench's types are named.
- `tests/Postgres/WalkingSkeleton/RlsWithoutActorContextTest.php` fails for a type table it has no row for, because it checks that every table under row level security, generated type tables included, holds no row for the app role without an actor context.
- `tests/Arch/ContentTypesTest.php` fails for a handle without the `fixture_` prefix, or when the kernel's source names one.
- A test below `packages/` that fails because a type was added reads the workbench by name. That test is the bug: fix it to read the types from the schema or the `TypeCatalog`, as M1-T50 did, in a commit of its own, and record it in `CHECKS-LOG.md`.
- A type with conditional fields: a test feeds input in which each conditional field is shown and hidden through the generated validator and the record's codec and TypeScript validator, as `tests/Feature/Validation/WorkbenchValidatorsTest.php` and `tests/Codecs/TypeScriptValidatorsTest.php` do for `fixture_measurement`, and the seeder's values pass, which `packages/core/tests/Seeding/FieldValueGeneratorTest.php` checks for every type of the workbench.
- A type with a relation: `tests/Postgres/ForeignKeyIndexTest.php` finds the index of a to-one relation's foreign key, and `tests/Codecs/WorkbenchRecordCodecsTest.php` and `tests/Codecs/TypeScriptValidatorsTest.php` hold its record to its ids; `packages/core/tests/Postgres/SeedScaleTest.php` seeds it with targets among the entries seeded before.
- `composer check:generated` (gate 6) and `composer check` (gates 1 to 6) pass.

## Running example

The workbench's third type, `app:fixture_event`, is history `audit-only`, stages `none` and routable. Its blueprint is the only file of M1-T50 that is not generated or a test:

<!-- example-file: workbench/schema/fixture_event.yaml -->
```yaml
# yaml-language-server: $schema=../../packages/contracts/resources/schemas/blueprint.v1.json
# The workbench's third fixture type (GUARDRAILS 2.4, MILESTONES M1 exit criteria), owner app:
# added as this schema file alone, with no change in the modules' code (packages/*/src), and working through the
# generators, the commands, the delivery API and every surface. Its capabilities differ from both
# other types: history audit-only (the kernel keeps head snapshots and no revisions, as
# fixture_measurement's none does, while fixture_article keeps every revision), stages none and
# routable, so an entry of it is public once it is placed and published, without a release. Its
# fields use what the other two do not: a unit on an integer and a decimal, a top-level e-mail
# address hidden from agents, a long text with a lower bound, rich text with links and numbered
# lists, a select that is sortable, and a group with a group inside it. Every handle and select
# value starts with fixture_, as in fixture_article.yaml. tests/Postgres/WalkingSkeleton
# /ThirdTypeTest.php runs it end to end. cms:generate reads it and writes the committed code in
# workbench/app/Cms/Generated, workbench/resources/js/cms/generated and the migration in
# workbench/database/migrations/cms; the first line is written by cms:schema:editor.
blueprint: 1
kind: type
type_id: 01a0f424-cbfd-727a-9467-bff093dc17b2
handle: fixture_event
label: Fixture event
description: A public event with a venue, a programme, a price and the seats it has.
version: 1
capabilities:
  history: audit-only
  stages: none
  localization: none
  routable: true
fields:
  - handle: fixture_name
    label: Name
    description: The name of the event, as it is shown on the page and in lists.
    type: text
    required: true
    classification: public
    sortable: true
    min_length: 2
    max_length: 120
  - handle: fixture_starts_at
    label: Starts at
    description: When the event starts.
    type: datetime
    required: true
    classification: public
    filterable: true
    sortable: true
  - handle: fixture_kind
    label: Kind
    description: What kind of event it is.
    type: select
    required: true
    classification: public
    filterable: true
    sortable: true
    options:
      - value: fixture_concert
        label: Concert
      - value: fixture_lecture
        label: Lecture
      - value: fixture_market
        label: Market
  - handle: fixture_seats
    label: Seats
    description: How many seats the venue has for the event.
    type: integer
    classification: public
    filterable: true
    min: 0
    max: 100000
    unit: seats
  - handle: fixture_price
    label: Price
    description: The price of a ticket.
    type: decimal
    classification: public
    sortable: true
    precision: 8
    scale: 2
    min: '0.00'
    unit: DKK
  - handle: fixture_summary
    label: Summary
    description: A short summary of the event for lists.
    type: long_text
    classification: public
    min_length: 10
    max_length: 500
  - handle: fixture_programme
    label: Programme
    description: The programme of the event, with links to the performers.
    type: rich_text
    classification: public
    styles: [normal, h3]
    marks: [em]
    lists: [number]
    links: [url]
  - handle: fixture_free
    label: Free
    description: Whether the event is free to attend.
    type: boolean
    classification: public
  - handle: fixture_doors_on
    label: Doors on
    description: The day the doors open, when it is before the event starts.
    type: date
    classification: public
  - handle: fixture_organiser_email
    label: Organiser e-mail
    description: The e-mail address of the person who organises the event.
    type: text
    classification: internal
    agents: false
    format: email
  - handle: fixture_venue
    label: Venue
    description: Where the event takes place.
    type: group
    classification: public
    fields:
      - handle: fixture_venue_name
        label: Venue name
        description: The name of the venue.
        type: text
        required: true
      - handle: fixture_venue_address
        label: Venue address
        description: The address of the venue.
        type: group
        fields:
          - handle: fixture_venue_street
            label: Street
            description: The street and number.
            type: text
            required: true
          - handle: fixture_venue_postcode
            label: Postcode
            description: The postcode.
            type: text
            min_length: 4
            max_length: 10
```

The test that lists the workbench's schema exactly, and reads the new type as its blueprint says:

The workbench's first type, `app:fixture_article`, carries the presentation keys: labels in English and Danish, a layout of the tabs `fixture_content` and `fixture_restrictions` and a sidebar, hints on its fields and form-only defaults. The fixture addon's extension has no layout fragment, so its field lands in the tab `extensions`:

<!-- example-file: workbench/schema/fixture_article.yaml -->
```yaml
# yaml-language-server: $schema=../../packages/contracts/resources/schemas/blueprint.v1.json
# The workbench's fixture type (GUARDRAILS 2.4, 2.6, PRD 11.12), a blueprint v1 file of the
# workbench's schema root, owner app. Every handle starts with fixture_, so no ordinary word in code
# is a handle, and tests/Arch/ContentTypesTest.php can reject each handle in the core packages in
# any spelling. cms:generate reads it and writes the committed code in
# workbench/app/Cms/Generated and workbench/resources/js/cms/generated, and
# `composer check:generated` fails when that code is not what the blueprints generate. The first
# line, which points editors at the blueprint schema, is written by cms:schema:editor.
# Version 2 adds the owner's fixture_slug, the handle of the fixture addon's extension field in
# workbench/addons/fixtureaddon/schema: the two are different fields, fixture_slug and
# ext.fixtureaddon.fixture_slug, side by side (PRD 11.12 point 2).
# The presentation keys (gap G2, B2-T72) reach only the editor descriptor: the labels in Danish
# and English, the layout of two tabs and a sidebar, the ui hints and the form-only defaults.
# The fixture addon's extension has no layout fragment, so its field lands in the tab extensions.
# fixture_related, up to 200 other articles, is a relation (PRD 5.11, B2-T112), whose edges the
# entry commands write in the draft stage and, once released, the released stage.
blueprint: 1
kind: type
type_id: 01a0df3e-8cef-7e9f-8daf-9faa60f1faa6
handle: fixture_article
label:
  en: Fixture article
  da: Fixture-artikel
description:
  en: A news article with a title, a body, its topics and sources, and an embargo.
  da: En nyhedsartikel med en titel, en brødtekst, dens emner og kilder, og en embargo.
version: 2
capabilities:
  history: full
  stages: draft-release
  localization: none
  routable: true
fields:
  - handle: fixture_title
    label:
      en: Title
      da: Titel
    description: The title of the article, as it is shown on the page and in lists.
    type: text
    classification: public
    ui:
      input_hint:
        en: The headline
        da: Overskriften
  - handle: fixture_slug
    label: Slug
    description: The owner's own slug of the article, added in version 2 beside the fixture addon's ext.fixtureaddon.fixture_slug, with the same handle and no collision.
    type: text
    classification: public
    max_length: 160
  - handle: fixture_body
    label:
      en: Body
      da: Brødtekst
    description: The body of the article.
    type: rich_text
    classification: public
    ui:
      instructions:
        en: Write the article; the first paragraph is shown in lists.
        da: Skriv artiklen; det første afsnit vises i lister.
  - handle: fixture_published_on
    label: Published on
    description: The day the article was first published.
    type: date
    classification: public
    sortable: true
    min: '2000-01-01'
    default: today
  - handle: fixture_reading_minutes
    label: Reading minutes
    description: How many minutes the article takes to read.
    type: integer
    classification: public
    min: 1
    max: 600
  - handle: fixture_featured
    label: Featured
    description: Whether the article is featured on the front page.
    type: boolean
    required: true
    classification: public
    filterable: true
    default: false
  - handle: fixture_topics
    label: Topics
    description: The topics the article is listed under.
    type: select
    classification: public
    multiple: true
    max_items: 2
    ui:
      display: buttons
    options:
      - value: fixture_politics
        label:
          en: Politics
          da: Politik
      - value: fixture_science
        label:
          en: Science
          da: Videnskab
      - value: fixture_culture
        label:
          en: Culture
          da: Kultur
  - handle: fixture_sources
    label: Sources
    description: The sources the article cites.
    type: group
    classification: internal
    repeat:
      max_items: 10
    ui:
      summary: '{fixture_source_title}'
    fields:
      - handle: fixture_source_title
        label: Source title
        description: The title of the source.
        type: text
        required: true
      - handle: fixture_source_url
        label: Source URL
        description: Where the source can be read.
        type: text
        format: url
  - handle: fixture_related
    label:
      en: Related articles
      da: Relaterede artikler
    description: The other articles the article points its readers to.
    type: relation
    classification: public
    multiple: true
    max_items: 200
    types: [app:fixture_article]
  - handle: fixture_embargo
    label: Embargo
    type: group
    classification: confidential
    fields:
      - handle: fixture_embargo_until
        label: Embargo until
        type: datetime
        required: true
      - handle: fixture_embargo_reason
        label: Embargo reason
        type: long_text
        ui:
          read_only: true
layout:
  tabs:
    - handle: fixture_content
      label:
        en: Content
        da: Indhold
      sections:
        - handle: fixture_headline
          fields:
            - field: fixture_title
              width: two_thirds
            - field: fixture_slug
              width: third
            - field: fixture_body
        - handle: fixture_sources_section
          label:
            en: Sources
            da: Kilder
          instructions:
            en: Cite every source the article relies on.
            da: Angiv hver kilde artiklen bygger på.
          collapsible: true
          fields:
            - field: fixture_sources
            - field: fixture_related
    - handle: fixture_restrictions
      label:
        en: Restrictions
        da: Begrænsninger
      sections:
        - handle: fixture_embargo_section
          fields:
            - field: fixture_embargo
  sidebar:
    sections:
      - handle: fixture_publishing
        label:
          en: Publishing
          da: Udgivelse
        fields:
          - field: fixture_published_on
            width: half
          - field: fixture_reading_minutes
            width: half
          - field: fixture_featured
          - field: fixture_topics
```

The workbench's `app:fixture_measurement` has every core field type and every rule, and its series' items have conditional fields: an operator required for a manual reading and cleared otherwise, a device shown on any of two conditions, one of them the measurement's `fixture_calibrated` one group up, which keeps its value, and a check required while all of a method and the kelvin scale hold. They are inside a group, whose column is one `jsonb`, so adding them changed no column and needed no migration. Its relations came later as new optional fields: `fixture_corrects` names one earlier measurement and `fixture_cited_in` up to 200 articles or measurements, which gave the step `app__fixture_measurement_0002_add_columns` with the foreign key of `fixture_corrects` and its index, and `fixture_sensor_calibration` names a measurement inside the group:

<!-- example-file: workbench/schema/fixture_measurement.yaml -->
```yaml
# yaml-language-server: $schema=../../packages/contracts/resources/schemas/blueprint.v1.json
# The workbench's second fixture type (GUARDRAILS 2.4, MILESTONES M1), owner app: a measurement
# with no history, no stages and no route, and other fields than fixture_article, so milestone 1
# exercises a type whose entries are written once and never drafted. It has every core field type
# and every rule of the runtime validators, which packages/generators/tests/Validators
# /WorkbenchValidatorsTest.php feeds input through. Every handle and select value starts with
# fixture_, as in fixture_article.yaml. cms:generate reads it and writes the committed
# code in workbench/app/Cms/Generated and workbench/resources/js/cms/generated; the first line is
# written by cms:schema:editor. The items of fixture_series have conditional fields (gap G3), which
# tests/Codecs/TypeScriptValidatorsTest.php and WorkbenchValidatorsTest.php hold PHP and TypeScript
# to: fixture_series_operator is required while the method is manual and cleared when hidden,
# fixture_series_device is shown on any of two conditions, one on the measurement's
# fixture_calibrated, and keeps its value when hidden, and fixture_series_check is required while
# all of a method and the kelvin scale of the measurement hold. They are inside a group, so the
# type table is unchanged. The relations (PRD 5.11, B2-T74) name other entries: fixture_corrects one
# measurement, a uuid column with a foreign key to entries, fixture_cited_in up to 200 articles or
# measurements, a jsonb list, and fixture_sensor_calibration one measurement inside the group. The
# two top-level ones are step 2 of the type table's migrations.
blueprint: 1
kind: type
type_id: 0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a6b
handle: fixture_measurement
label: Fixture measurement
description: One reading of a sensor at a station.
version: 1
capabilities:
  history: none
  stages: none
  localization: none
  routable: false
fields:
  - handle: fixture_reading
    label: Reading
    description: The value the sensor read, in the unit of the scale.
    type: decimal
    required: true
    classification: public
    sortable: true
    precision: 12
    scale: 3
  - handle: fixture_scale
    label: Scale
    description: The scale of the reading.
    type: select
    required: true
    classification: public
    filterable: true
    options:
      - value: fixture_celsius
        label: Celsius
      - value: fixture_kelvin
        label: Kelvin
  - handle: fixture_measured_at
    label: Measured at
    description: When the sensor took the reading.
    type: datetime
    required: true
    classification: public
    filterable: true
    sortable: true
  - handle: fixture_station
    label: Station
    description: The code of the station the sensor stands at.
    type: text
    classification: internal
    filterable: true
    min_length: 3
    max_length: 40
  - handle: fixture_note
    label: Note
    type: long_text
    classification: internal
    agents: false
  - handle: fixture_samples
    label: Samples
    description: How many samples the sensor averaged into the reading.
    type: integer
    classification: public
    min: 1
    max: 1000
  - handle: fixture_calibrated
    label: Calibrated
    description: Whether the sensor was calibrated when it took the reading.
    type: boolean
    classification: public
  - handle: fixture_calibrated_on
    label: Calibrated on
    description: The day the sensor was last calibrated.
    type: date
    classification: public
    min: '2000-01-01'
    max: '2099-12-31'
  - handle: fixture_alerts
    label: Alerts
    description: The alerts the reading raised.
    type: select
    classification: public
    multiple: true
    min_items: 1
    max_items: 2
    options:
      - value: fixture_low
        label: Low
      - value: fixture_high
        label: High
      - value: fixture_drift
        label: Drift
  - handle: fixture_remark
    label: Remark
    description: A remark on the reading, in rich text.
    type: rich_text
    classification: public
    styles: [normal]
    marks: [strong]
    lists: [bullet]
    links: []
  - handle: fixture_corrects
    label: Corrects
    description: The earlier reading this one corrects.
    type: relation
    classification: public
    types: [app:fixture_measurement]
  - handle: fixture_cited_in
    label: Cited in
    description: The articles and readings that cite this reading.
    type: relation
    classification: public
    multiple: true
    max_items: 200
    types: [app:fixture_article, app:fixture_measurement]
  - handle: fixture_sensor
    label: Sensor
    description: The sensor that took the reading.
    type: group
    classification: internal
    fields:
      - handle: fixture_sensor_code
        label: Sensor code
        description: The code of the sensor.
        type: text
        required: true
      - handle: fixture_sensor_contact
        label: Sensor contact
        description: Who looks after the sensor.
        type: text
        format: email
      - handle: fixture_sensor_manual
        label: Sensor manual
        description: Where the sensor's manual can be read.
        type: text
        format: url
      - handle: fixture_sensor_calibration
        label: Sensor calibration
        description: The reading the sensor was last calibrated against.
        type: relation
        types: [app:fixture_measurement]
  - handle: fixture_series
    label: Series
    description: The single readings the reading is the mean of.
    type: group
    classification: public
    repeat:
      min_items: 1
      max_items: 3
    fields:
      - handle: fixture_series_value
        label: Series value
        description: One single reading.
        type: decimal
        required: true
        precision: 6
        scale: 2
        min: '-100.00'
        max: '1000.00'
      - handle: fixture_series_taken_at
        label: Series taken at
        description: When the single reading was taken.
        type: datetime
        min: '2000-01-01T00:00:00Z'
        max: '2099-12-31T23:59:59Z'
      - handle: fixture_series_method
        label: Series method
        description: How the single reading was taken.
        type: select
        options:
          - value: fixture_manual
            label: Manual
          - value: fixture_logged
            label: Logged
      - handle: fixture_series_operator
        label: Series operator
        description: Who took the single reading by hand.
        type: text
        required: true
        max_length: 60
        when:
          field: fixture_series_method
          eq: fixture_manual
        when_hidden: clear
      - handle: fixture_series_device
        label: Series device
        description: The device that logged the single reading, or the calibrated sensor's.
        type: text
        max_length: 60
        when:
          any:
            - field: fixture_series_method
              eq: fixture_logged
            - field: ../fixture_calibrated
              eq: true
        when_hidden: keep
      - handle: fixture_series_check
        label: Series check
        description: How the single reading was checked against the kelvin scale.
        type: text
        required: true
        max_length: 60
        when:
          all:
            - field: fixture_series_method
              not_null: true
            - field: ../fixture_scale
              in: [fixture_kelvin]
        when_hidden: clear
```

<!-- example: tests/Feature/Workbench/WorkbenchSchemaTest.php -->
```php
<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Workbench;

use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Generation\Boundary\GeneratorConfig;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Domain\BlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\Dto\BooleanOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DateOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DatetimeOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DecimalOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\IntegerOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\LongTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\RichTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;
use Cbox\Cms\Generators\Schema\Domain\History;
use Cbox\Cms\Generators\Schema\Domain\Localization;
use Cbox\Cms\Generators\Schema\Domain\Stages;
use Illuminate\Contracts\Config\Repository;
use LogicException;

/*
 * The workbench's own schema, listed exactly (GUARDRAILS 2.4, 2.6): the blueprint files of its app
 * root, each type's blueprint where no kernel test reads it, and every file cms:generate writes from
 * them. The kernel's modules test the workbench generically (packages/generators/tests/WorkbenchFixtureTest.php
 * finds fixture_article and fixture_measurement by handle and compares the generated files with the
 * committed ones), so a type added as a schema file changes the workbench and this file only,
 * never a file below packages/.
 */

/**
 * @return list<string>
 */
function workbenchSchemaFiles(string $directory): array
{
    $files = array_values(array_filter(
        scandir($directory) ?: [],
        static fn (string $file): bool => is_file($directory.'/'.$file),
    ));
    sort($files, SORT_STRING);

    return $files;
}

it('holds a blueprint file per fixture type in the app root', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());

    expect(workbenchSchemaFiles($target->roots[0]->path()))->toBe(['fixture_article.yaml', 'fixture_event.yaml', 'fixture_measurement.yaml', 'fixture_notice.yaml'])
        ->and(array_map(
            static fn (TypeBlueprint $type): string => $type->owner->value.':'.$type->handle->value,
            app(BlueprintSource::class)->read($target->roots)->types,
        ))->toBe(['app:fixture_article', 'app:fixture_event', 'app:fixture_measurement', 'app:fixture_notice']);
});

it('reads the third fixture type, added as a schema file alone', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());
    $types = app(BlueprintSource::class)->read($target->roots)->types;
    $article = array_find($types, static fn (TypeBlueprint $type): bool => $type->handle->value === 'fixture_article');
    $event = array_find($types, static fn (TypeBlueprint $type): bool => $type->handle->value === 'fixture_event');
    $measurement = array_find($types, static fn (TypeBlueprint $type): bool => $type->handle->value === 'fixture_measurement');

    if ($article === null || $event === null || $measurement === null) {
        throw new LogicException('The workbench lacks one of its three fixture types.');
    }

    // The third fixture type, added as a schema file alone (MILESTONES M1 exit criteria), with
    // capabilities neither other type has: history audit-only, stages none and a route.
    expect($event->handle->value)->toBe('fixture_event')
        ->and($event->owner->value)->toBe('app')
        ->and($event->typeId->equals($article->typeId) || $event->typeId->equals($measurement->typeId))->toBeFalse()
        ->and([$event->capabilities->history, $event->capabilities->stages, $event->capabilities->localization, $event->capabilities->routable])
        ->toBe([History::AuditOnly, Stages::None, Localization::None, true])
        ->and(array_map(static fn (FieldBlueprint $field): array => [$field->handle->value, $field->options::class, $field->classification, $field->required], $event->fields))
        ->toBe([
            ['fixture_name', TextOptions::class, Classification::Public, true],
            ['fixture_starts_at', DatetimeOptions::class, Classification::Public, true],
            ['fixture_kind', SelectOptions::class, Classification::Public, true],
            ['fixture_seats', IntegerOptions::class, Classification::Public, false],
            ['fixture_price', DecimalOptions::class, Classification::Public, false],
            ['fixture_summary', LongTextOptions::class, Classification::Public, false],
            ['fixture_programme', RichTextOptions::class, Classification::Public, false],
            ['fixture_free', BooleanOptions::class, Classification::Public, false],
            ['fixture_doors_on', DateOptions::class, Classification::Public, false],
            ['fixture_organiser_email', TextOptions::class, Classification::Internal, false],
            ['fixture_venue', GroupOptions::class, Classification::Public, false],
        ]);
});

it('generates exactly these files from the workbench\'s schema', function (): void {
    $target = GeneratorConfig::read(app(Repository::class), base_path());
    $paths = app(GeneratorRunner::class)->run(DescriptorCompiler::compile(SchemaResolver::resolve(app(BlueprintSource::class)->read($target->roots))), $target)->paths();

    expect($paths)->toBe([
        'workbench/app/Cms/Generated/Boundary/AppFixtureArticleCodecV1.php',
        'workbench/app/Cms/Generated/Boundary/AppFixtureEventCodecV1.php',
        'workbench/app/Cms/Generated/Boundary/AppFixtureMeasurementCodecV1.php',
        'workbench/app/Cms/Generated/Boundary/AppFixtureNoticeCodecV1.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureArticleV1.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureArticleV1Ext.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureArticleV1ExtFixtureaddon.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureArticleV1FixtureEmbargo.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureArticleV1FixtureSources.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureEventV1.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureEventV1FixtureVenue.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureEventV1FixtureVenueFixtureVenueAddress.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureMeasurementV1.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureMeasurementV1FixtureSensor.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureMeasurementV1FixtureSeries.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureNoticeV1.php',
        'workbench/app/Cms/Generated/Domain/Dto/AppFixtureNoticeV1FixtureLinks.php',
        'workbench/app/Cms/Generated/GeneratedRecordCodecs.php',
        'workbench/app/Cms/Generated/GeneratedTypeCatalog.php',
        'workbench/app/Cms/Generated/GeneratedTypeEditors.php',
        'workbench/app/Cms/Generated/GeneratedTypeValidators.php',
        'workbench/app/Cms/Generated/GeneratedTypesServiceProvider.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureArticle/AppFixtureArticleFilterField.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureArticle/AppFixtureArticleQuery.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureArticle/AppFixtureArticleSortField.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureEvent/AppFixtureEventFilterField.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureEvent/AppFixtureEventQuery.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureEvent/AppFixtureEventSortField.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureMeasurement/AppFixtureMeasurementFilterField.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureMeasurement/AppFixtureMeasurementQuery.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureMeasurement/AppFixtureMeasurementSortField.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureNotice/AppFixtureNoticeFilterField.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureNotice/AppFixtureNoticeQuery.php',
        'workbench/app/Cms/Generated/QueryBuilders/AppFixtureNotice/AppFixtureNoticeSortField.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticle.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleExt.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleFixtureaddonExt.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleFixtureaddonExtension.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleFixtureaddonFields.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleRecord.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/AppFixtureArticleRecordFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/FixtureEmbargoGroup.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/FixtureSourcesItem.php',
        'workbench/app/Cms/Generated/Records/AppFixtureArticle/FixtureTopicsChoice.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/AppFixtureEvent.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/AppFixtureEventFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/AppFixtureEventRecord.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/AppFixtureEventRecordFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/FixtureKindChoice.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/FixtureVenueFixtureVenueAddressGroup.php',
        'workbench/app/Cms/Generated/Records/AppFixtureEvent/FixtureVenueGroup.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/AppFixtureMeasurement.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/AppFixtureMeasurementFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/AppFixtureMeasurementRecord.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/AppFixtureMeasurementRecordFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/FixtureAlertsChoice.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/FixtureScaleChoice.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/FixtureSensorGroup.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/FixtureSeriesFixtureSeriesMethodChoice.php',
        'workbench/app/Cms/Generated/Records/AppFixtureMeasurement/FixtureSeriesItem.php',
        'workbench/app/Cms/Generated/Records/AppFixtureNotice/AppFixtureNotice.php',
        'workbench/app/Cms/Generated/Records/AppFixtureNotice/AppFixtureNoticeFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureNotice/AppFixtureNoticeRecord.php',
        'workbench/app/Cms/Generated/Records/AppFixtureNotice/AppFixtureNoticeRecordFactory.php',
        'workbench/app/Cms/Generated/Records/AppFixtureNotice/FixtureChannelChoice.php',
        'workbench/app/Cms/Generated/Records/AppFixtureNotice/FixtureLinksItem.php',
        'workbench/app/Cms/Generated/TypeHandle.php',
        'workbench/app/Cms/Generated/Validators/AppFixtureArticleValidator.php',
        'workbench/app/Cms/Generated/Validators/AppFixtureEventValidator.php',
        'workbench/app/Cms/Generated/Validators/AppFixtureMeasurementValidator.php',
        'workbench/app/Cms/Generated/Validators/AppFixtureNoticeValidator.php',
        'workbench/database/migrations/cms/app__fixture_article.lock',
        'workbench/database/migrations/cms/app__fixture_article_0001_create.php',
        'workbench/database/migrations/cms/app__fixture_article_0002_add_columns.php',
        'workbench/database/migrations/cms/app__fixture_article_0003_add_columns.php',
        'workbench/database/migrations/cms/app__fixture_article_0004_add_columns.php',
        'workbench/database/migrations/cms/app__fixture_event.lock',
        'workbench/database/migrations/cms/app__fixture_event_0001_create.php',
        'workbench/database/migrations/cms/app__fixture_measurement.lock',
        'workbench/database/migrations/cms/app__fixture_measurement_0001_create.php',
        'workbench/database/migrations/cms/app__fixture_measurement_0002_add_columns.php',
        'workbench/database/migrations/cms/app__fixture_notice.lock',
        'workbench/database/migrations/cms/app__fixture_notice_0001_create.php',
        'workbench/database/migrations/cms/app__fixture_notice_0002_add_columns.php',
        'workbench/resources/js/cms/generated/index.ts',
        'workbench/resources/js/cms/generated/protocol/ActionListV1.ts',
        'workbench/resources/js/cms/generated/protocol/ActivateActorV1.ts',
        'workbench/resources/js/cms/generated/protocol/ActorListV1.ts',
        'workbench/resources/js/cms/generated/protocol/ActorMeV1.ts',
        'workbench/resources/js/cms/generated/protocol/AddSiteLocaleV1.ts',
        'workbench/resources/js/cms/generated/protocol/ArchiveNodeV1.ts',
        'workbench/resources/js/cms/generated/protocol/AssignGrantV1.ts',
        'workbench/resources/js/cms/generated/protocol/ChangeNodeRouteV2.ts',
        'workbench/resources/js/cms/generated/protocol/CreateEntryV1.ts',
        'workbench/resources/js/cms/generated/protocol/CreateEntryV2.ts',
        'workbench/resources/js/cms/generated/protocol/CreateMountV1.ts',
        'workbench/resources/js/cms/generated/protocol/CreateNodeV1.ts',
        'workbench/resources/js/cms/generated/protocol/CreatePlacementV1.ts',
        'workbench/resources/js/cms/generated/protocol/CreateRoleV1.ts',
        'workbench/resources/js/cms/generated/protocol/CreateVariantFromV1.ts',
        'workbench/resources/js/cms/generated/protocol/DeactivateActorV1.ts',
        'workbench/resources/js/cms/generated/protocol/DeleteVariantV1.ts',
        'workbench/resources/js/cms/generated/protocol/DeliveryExplanationV1.ts',
        'workbench/resources/js/cms/generated/protocol/DeliveryFragmentV1.ts',
        'workbench/resources/js/cms/generated/protocol/DeliveryV1.ts',
        'workbench/resources/js/cms/generated/protocol/DescribeTypeV1.ts',
        'workbench/resources/js/cms/generated/protocol/DryRunSummaryV1.ts',
        'workbench/resources/js/cms/generated/protocol/EntryDetailV1.ts',
        'workbench/resources/js/cms/generated/protocol/EntryListV1.ts',
        'workbench/resources/js/cms/generated/protocol/EnvelopeV1.ts',
        'workbench/resources/js/cms/generated/protocol/ExplainedPathV1.ts',
        'workbench/resources/js/cms/generated/protocol/GetEntryV1.ts',
        'workbench/resources/js/cms/generated/protocol/GrantBootstrapRoleV1.ts',
        'workbench/resources/js/cms/generated/protocol/GrantListV1.ts',
        'workbench/resources/js/cms/generated/protocol/ListActionsV1.ts',
        'workbench/resources/js/cms/generated/protocol/ListActorsV1.ts',
        'workbench/resources/js/cms/generated/protocol/ListEntriesV1.ts',
        'workbench/resources/js/cms/generated/protocol/ListGrantsV1.ts',
        'workbench/resources/js/cms/generated/protocol/ListNodesV1.ts',
        'workbench/resources/js/cms/generated/protocol/ListRolesV1.ts',
        'workbench/resources/js/cms/generated/protocol/ListTypesV1.ts',
        'workbench/resources/js/cms/generated/protocol/MoveNodeV1.ts',
        'workbench/resources/js/cms/generated/protocol/NodeListV1.ts',
        'workbench/resources/js/cms/generated/protocol/PanelBundleSignatureV1.ts',
        'workbench/resources/js/cms/generated/protocol/PanelBundleV1.ts',
        'workbench/resources/js/cms/generated/protocol/PathExplanationV1.ts',
        'workbench/resources/js/cms/generated/protocol/ProblemV1.ts',
        'workbench/resources/js/cms/generated/protocol/PublishEntryV1.ts',
        'workbench/resources/js/cms/generated/protocol/PublishEntryV2.ts',
        'workbench/resources/js/cms/generated/protocol/ReceiptV1.ts',
        'workbench/resources/js/cms/generated/protocol/RegisterActorV1.ts',
        'workbench/resources/js/cms/generated/protocol/RegisterSiteV1.ts',
        'workbench/resources/js/cms/generated/protocol/ReleaseVariantV1.ts',
        'workbench/resources/js/cms/generated/protocol/ReleaseVariantV2.ts',
        'workbench/resources/js/cms/generated/protocol/RemoveSiteLocaleV1.ts',
        'workbench/resources/js/cms/generated/protocol/ResolvePathV1.ts',
        'workbench/resources/js/cms/generated/protocol/ResolvedPathV1.ts',
        'workbench/resources/js/cms/generated/protocol/ReviseEntryV1.ts',
        'workbench/resources/js/cms/generated/protocol/ReviseEntryV2.ts',
        'workbench/resources/js/cms/generated/protocol/RevokeGrantV1.ts',
        'workbench/resources/js/cms/generated/protocol/RoleListV1.ts',
        'workbench/resources/js/cms/generated/protocol/SetMountOverrideV1.ts',
        'workbench/resources/js/cms/generated/protocol/SetNodeLabelV1.ts',
        'workbench/resources/js/cms/generated/protocol/SetNodeRouteV1.ts',
        'workbench/resources/js/cms/generated/protocol/SetPlacementSlugV1.ts',
        'workbench/resources/js/cms/generated/protocol/SetPlacementWindowV1.ts',
        'workbench/resources/js/cms/generated/protocol/SetRolePermissionsV1.ts',
        'workbench/resources/js/cms/generated/protocol/TypeDescriptionV1.ts',
        'workbench/resources/js/cms/generated/protocol/TypeEditorV1.ts',
        'workbench/resources/js/cms/generated/protocol/TypeListV1.ts',
        'workbench/resources/js/cms/generated/protocol/UnpublishEntryV1.ts',
        'workbench/resources/js/cms/generated/protocol/WhoAmIV1.ts',
        'workbench/resources/js/cms/generated/records/AppFixtureArticleV1.ts',
        'workbench/resources/js/cms/generated/records/AppFixtureEventV1.ts',
        'workbench/resources/js/cms/generated/records/AppFixtureMeasurementV1.ts',
        'workbench/resources/js/cms/generated/records/AppFixtureNoticeV1.ts',
        'workbench/resources/js/cms/generated/validation.ts',
    ]);
});
```

The type also runs end to end in `tests/Postgres/WalkingSkeleton/ThirdTypeTest.php`: created, revised, placed and published through the command pipeline and `cms:run`, resolved over `GET /v1/resolve`, its fragment invalidated by the event runner, and its type table rebuilt from its head snapshots. Write such a test when a new type brings a combination of capabilities the others do not have.
