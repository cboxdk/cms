---
title: Add a form check mirrored by a hook
weight: 48
description: "Add a check to a command form that blocks the submit, mirrored by a validate hook of the addon on the same command, with the shared cases that hold the two to the same verdicts."
---

# Add a form check mirrored by a hook

A form check that blocks a submit duplicates a rule of the server in the browser, so the viewer sees the problem before the run. The server stays the authority: only a check that mirrors a `ValidateHook` or `AuthorizeHook` of its own addon on the same command may block, and the two must agree ([form check](../addons/panel/kinds/form-check.md)). The fixture addon's `fixtureaddon.slug-shape`, mirrored by its hook `RequireWellFormedSlug` on `entry.create`, was added this way.

## Inputs

- **namespace** and **id**: the addon's namespace and the check's id, such as `fixtureaddon.slug-shape`.
- **command**: the command whose form the check runs on, `<name>@<version>`, such as `entry.create@1`.
- **hook**: the hook class the check mirrors, a `ValidateHook` or `AuthorizeHook` of the addon on that command.
- **rule**: what is refused, at which path of the document, with which code and message key.

## Files

| Path, in the addon's package | What it holds |
|---|---|
| `src/<Hook>.php` | the hook, with `#[Hook]` on the command, refusing at the path ([hooks](../addons/hooks.md)) |
| `src/<Addon>ServiceProvider.php` | the hook's `AllowedHook`, and `new FormCheck($id, 'command.form.checks@1', '<command>', Severity::Error, <Hook>::class)` |
| `resources/panel/parity/<check>.json` | the shared cases: per case a document of the command and the paths the hook refuses |
| `tests/Unit/<Hook>Test.php` | the hook's tests, and the test that holds the hook to the shared cases |
| `resources/panel/src/<checks>.ts` | the check, a `FormCheck<D>` on the command's generated document type |
| `resources/panel/src/<checks>.test.ts` | `checkParity()` on the same cases, and `expectFormCheckContract()` |

## Steps

1. Write the hook and its tests, and allow it in the manifest.
2. Write the shared cases, each document of the command with the paths the hook refuses, and the hook's test that runs every case.
3. Run `vendor/bin/testbench cms:make:panel check <namespace> <id> --point=command.form.checks@1 --command=<command> --severity=error`, and add `mirrors: <Hook>::class` to the manifest line.
4. Write the check, then its test with `checkParity(check, refused, documents)`, where `refused` gives the paths the hook refused on each document, read from the cases.
5. Run the addon's PHP and JS tests, build the bundle, and run `vendor/bin/testbench cms:build`.

## Checks

- `cms:build` refuses a check of severity error without a hook it mirrors on the same command (`registry_panel_check_unmirrored`), and the host weighs a check's issues down to its declared severity, so an unmirrored check cannot block.
- `checkParity()` fails with `ParityBroken`, naming each document, when the check blocks a path the hook does not refuse, or misses one it does; the hook's test fails when the hook leaves the cases.
- `expectFormCheckContract()` holds the check to 16 ms, to codes in the addon's namespace, to its declared severity, and to the same answer twice.

## Running example

The hook, on the server:

<!-- example-file: workbench/addons/fixtureaddon/src/RequireWellFormedSlug.php -->
```php
<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\HookError;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ValidateHook;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;

/**
 * The fixture addon's validate hook on entry.create (PRD 6.3, 13.4): a slug a caller sets by hand
 * in ext.fixtureaddon.fixture_slug of a revision of app:fixture_article must be well formed, SHAPE:
 * lowercase letters and digits in runs joined by single hyphens, as DeriveSlug derives one from
 * the title. A revision without a slug passes, because DeriveSlug gives it one before this hook
 * runs, and its length is the blueprint's rule, not this one's. The kernel adds the error as
 * validation_hook_failed at the field and rejects the create with validation_failed.
 *
 * The addon's form check fixtureaddon.slug-shape in the panel mirrors it (the mirror rule, PRD
 * 13.4): it blocks the submit of entry.create's form on the same documents, and
 * resources/panel/parity/slug-shape.json holds both to the same verdicts.
 */
#[Hook(command: CreateEntry::class, phase: Phase::Validate, priority: 20, budgetMs: 2)]
final readonly class RequireWellFormedSlug implements ValidateHook
{
    /** A well-formed slug: runs of lowercase letters and digits joined by single hyphens. */
    public const string SHAPE = '/\A[a-z0-9]+(-[a-z0-9]+)*\z/';

    public function validate(PlanView $plan): HookErrors
    {
        $errors = [];

        foreach ($plan->revisions() as $revision) {
            $slug = $revision->fields->extension(FixtureArticle::namespace())?->get(FixtureArticle::slug());

            if (FixtureArticle::is($revision->type) && $slug instanceof TextValue && ! self::isWellFormed($slug->value)) {
                $errors[] = HookError::onField(
                    FixtureArticle::slug(),
                    sprintf('The slug "%s" is not well formed: use lowercase letters and digits joined by single hyphens, such as a-quiet-week.', $slug->value),
                    FixtureArticle::namespace(),
                );
            }
        }

        return new HookErrors(...$errors);
    }

    public static function isWellFormed(string $slug): bool
    {
        return preg_match(self::SHAPE, $slug) === 1;
    }
}
```

The hook held to the shared cases:

<!-- example: workbench/addons/fixtureaddon/tests/Unit/RequireWellFormedSlugTest.php -->
```php
<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Tests\Unit;

use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\HookError;
use Cbox\Cms\Contracts\Ids\TypeId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Workbench\FixtureAddon\FixtureArticle;
use Workbench\FixtureAddon\RequireWellFormedSlug;

/**
 * The fixture addon's validate hook on entry.create: a slug set by hand in
 * ext.fixtureaddon.fixture_slug of a revision of app:fixture_article must be well formed, and
 * the hook is held to the verdicts of resources/panel/parity/slug-shape.json, which the panel's
 * check fixtureaddon.slug-shape mirrors (the mirror rule, PRD 13.4), case by case.
 */
final class RequireWellFormedSlugTest extends TestCase
{
    private const string PARITY = __DIR__.'/../../resources/panel/parity/slug-shape.json';

    #[Test]
    public function it_refuses_a_slug_that_is_not_well_formed_at_the_addons_field(): void
    {
        $views = new PlanViews;

        $errors = new RequireWellFormedSlug()->validate($views->create(
            $views->revision($views->entry(), PlanViews::article(), PlanViews::fields('A quiet week', 'A quiet Week')),
        ))->errors;

        self::assertEquals([HookError::onField(
            FixtureArticle::slug(),
            'The slug "A quiet Week" is not well formed: use lowercase letters and digits joined by single hyphens, such as a-quiet-week.',
            FixtureArticle::namespace(),
        )], $errors);
    }

    #[Test]
    public function it_passes_a_well_formed_slug_a_revision_without_one_and_other_types(): void
    {
        $views = new PlanViews;
        $hook = new RequireWellFormedSlug;

        self::assertTrue($hook->validate($views->create($views->revision($views->entry(), PlanViews::article(), PlanViews::fields('A quiet week', 'a-quiet-week'))))->isEmpty());
        self::assertTrue($hook->validate($views->create($views->revision($views->entry(), PlanViews::article(), PlanViews::fields('A quiet week'))))->isEmpty());
        self::assertTrue($hook->validate($views->create($views->revision($views->entry(), $views->otherType(), PlanViews::fields('A quiet week', 'Not This Type'))))->isEmpty());
    }

    #[Test]
    public function it_gives_the_verdicts_the_panels_mirrored_check_is_held_to(): void
    {
        $cases = $this->cases();
        $views = new PlanViews;
        $hook = new RequireWellFormedSlug;

        self::assertNotEmpty($cases);

        foreach ($cases as $case) {
            $document = $case['document'];
            $fields = is_array($document['fields'] ?? null) ? $document['fields'] : [];
            $title = $fields['fixture_title'] ?? null;
            $extension = is_array($fields['ext'] ?? null) && is_array($fields['ext']['fixtureaddon'] ?? null) ? $fields['ext']['fixtureaddon'] : [];
            $slug = $extension['fixture_slug'] ?? null;
            $own = is_string($title) ? new FieldMap(new NamedValue(FixtureArticle::title(), new TextValue($title))) : new FieldMap;
            $values = is_string($slug)
                ? new FieldValues($own, new ExtensionFields(FixtureArticle::namespace(), new FieldMap(new NamedValue(FixtureArticle::slug(), new TextValue($slug)))))
                : new FieldValues($own);
            $type = is_string($document['type'] ?? null) ? TypeId::fromString($document['type']) : PlanViews::article();

            $refused = array_map(
                static fn (HookError $error): string => 'fields.ext.'.($error->namespace instanceof FieldNamespace ? $error->namespace->value : '').'.'.($error->handle instanceof FieldHandle ? $error->handle->value : ''),
                $hook->validate($views->create($views->revision($views->entry(), $type, $values)))->errors,
            );

            self::assertSame($case['refused'], $refused, $case['name']);
        }
    }

    /**
     * @return list<array{name: string, document: array<string, mixed>, refused: list<string>}>
     */
    private function cases(): array
    {
        $decoded = json_decode((string) file_get_contents(self::PARITY), true, 16, JSON_THROW_ON_ERROR);
        $cases = [];

        foreach (is_array($decoded) && is_array($decoded['cases'] ?? null) ? $decoded['cases'] : [] as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['name']);
            self::assertIsArray($case['document']);
            self::assertIsArray($case['refused']);

            $refused = [];

            foreach ($case['refused'] as $path) {
                self::assertIsString($path);
                $refused[] = $path;
            }

            $document = [];

            foreach ($case['document'] as $key => $value) {
                $document[(string) $key] = $value;
            }

            $cases[] = ['name' => $case['name'], 'document' => $document, 'refused' => $refused];
        }

        return $cases;
    }
}
```

The checks of the fixture addon, `slugShape` among them:

<!-- example-file: workbench/addons/fixtureaddon/resources/panel/src/checks.ts -->
```ts
// The fixture addon's checks of the generic command form (section 3.7 of the panel extension
// architecture), each a pure function of the document to issues, run in the browser on every
// edit. They are a courtesy to the viewer; the rule is the addon's hooks on the server. On
// entry.create's form:
//
// - slugHint warns, at the title, when no slug can be derived from it and none is set, because
//   RequireSlugOnRelease will refuse the release until the slug is set;
// - slugOverride asks the viewer to acknowledge a slug set by hand, in place of the one DeriveSlug
//   would derive from the title;
// - slugShape blocks a slug that is not well formed, as RequireWellFormedSlug refuses it on the
//   server: the check mirrors the hook, so the mirror rule lets it block, and
//   ../parity/slug-shape.json holds the two to the same verdicts.
//
// Each applies to the addon's own type alone, and says nothing about an entry of another type. On
// grant.assign's form, selfGrant blocks a grant to the viewer themselves, read against the viewer
// the check's context names, as the authorize hook DenySelfGrant refuses it on the server, the
// addon's four-eyes rule; ../parity/self-grant.json holds the two to the same verdicts.

import type { FormCheck } from '@cboxdk/cms-panel/extend';

import type { EntryCreateV1, GrantAssignV1 } from '../generated/contributions';
import { SHAPE, SLUG_PATH, TITLE_PATH, isArticle, slugIn, slugOf, titleOf } from './slug';

export const slugHint: FormCheck<EntryCreateV1> = (document) => {
  if (!isArticle(document) || slugIn(document) !== undefined) {
    return [];
  }

  const title = titleOf(document);

  return slugOf(title ?? '') === null
    ? [
        {
          path: TITLE_PATH,
          code: 'fixtureaddon.slug_hint',
          severity: 'warning',
          message: 'fixtureaddon.slug_hint.message',
        },
      ]
    : [];
};

export const slugOverride: FormCheck<EntryCreateV1> = (document) => {
  const slug = slugIn(document);

  if (!isArticle(document) || slug === undefined) {
    return [];
  }

  const derived = slugOf(titleOf(document) ?? '');

  return derived === slug
    ? []
    : [
        {
          path: SLUG_PATH,
          code: 'fixtureaddon.slug_override',
          severity: 'acknowledge',
          message: 'fixtureaddon.slug_override.message',
          parameters: { derived: derived ?? '' },
        },
      ];
};

export const slugShape: FormCheck<EntryCreateV1> = (document) => {
  const slug = slugIn(document);

  if (!isArticle(document) || slug === undefined || SHAPE.test(slug)) {
    return [];
  }

  return [
    {
      path: SLUG_PATH,
      code: 'fixtureaddon.slug_shape',
      severity: 'error',
      message: 'fixtureaddon.slug_shape.message',
      parameters: { slug },
    },
  ];
};

/** The path of the grantee in a grant.assign document, as FieldPath::toString() writes it. */
export const ACTOR_PATH = 'actor';

/** The draft of grant.assign as the form holds it while it is edited: any member may be missing. */
type GrantDraft = { readonly [K in keyof GrantAssignV1]?: GrantAssignV1[K] };

export const selfGrant: FormCheck<GrantAssignV1> = (document, context) => {
  const actor = (document as GrantDraft).actor;

  // The kernel reads an id whatever the case of its hex digits, so the hook compares ids, not text.
  if (
    typeof actor !== 'string' ||
    context.viewer === null ||
    actor.toLowerCase() !== context.viewer.toLowerCase()
  ) {
    return [];
  }

  return [
    {
      path: ACTOR_PATH,
      code: 'fixtureaddon.self_grant',
      severity: 'error',
      message: 'fixtureaddon.self_grant.message',
    },
  ];
};
```

The check held to the same cases:

<!-- example: examples/Vitest/Panel/Recipes/mirrored-check.test.ts -->
```ts
// A form check mirrored by a validate hook, the recipe's real run: the fixture addon's
// fixtureaddon.slug-shape, the check slugShape of checks.ts, blocks the submit of entry.create's
// form with an error, which it may only because its manifest names the hook it mirrors,
// RequireWellFormedSlug, a ValidateHook of the same addon on the same command. The two must agree:
// RequireWellFormedSlugTest records the hook's verdict on each case of parity/slug-shape.json,
// the paths it refuses, and checkParity() holds the check to the same verdicts here, so a change
// to one side that the other does not follow fails.

import { checkParity } from '@cboxdk/cms-panel/testing';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from 'vitest';

import type { EntryCreateV1 } from '../../../../workbench/addons/fixtureaddon/resources/panel/generated/contributions';
import { slugShape } from '../../../../workbench/addons/fixtureaddon/resources/panel/src/checks';

interface ParityCase {
  readonly document: EntryCreateV1;
  readonly refused: readonly string[];
}

const PARITY = JSON.parse(
  readFileSync(
    join(
      import.meta.dirname,
      '../../../../workbench/addons/fixtureaddon/resources/panel/parity/slug-shape.json',
    ),
    'utf8',
  ),
) as { readonly cases: readonly ParityCase[] };

/** The paths the hook refused on a document, as RequireWellFormedSlugTest recorded them. */
function refusedByTheHook(document: EntryCreateV1): readonly string[] {
  const text = JSON.stringify(document);

  return (
    PARITY.cases.find((candidate) => JSON.stringify(candidate.document) === text)?.refused ?? []
  );
}

test('fixtureaddon.slug-shape blocks exactly the documents RequireWellFormedSlug refuses', async () => {
  const documents = PARITY.cases.map((candidate) => candidate.document);

  await expect(checkParity(slugShape, refusedByTheHook, documents)).resolves.toBe(documents.length);
});
```
