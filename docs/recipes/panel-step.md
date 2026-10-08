---
title: Add a flow step
weight: 49
description: "Add a step to a command form before the submit or after the receipt: the flow step in the manifest with the paths it patches, its component, and the tests that hold it."
---

# Add a flow step

A flow step is a numbered step the viewer goes through when a command form runs, such as a review before the submit. It may patch only the paths its manifest declares, and it never skips the core's own confirmation ([flow step](../addons/panel/kinds/flow-step.md)). The fixture addon's `fixtureaddon.slug-review` on the form of `entry.create`, which patches the slug into the addon's own field, was added this way.

## Inputs

- **namespace** and **id**: the addon's namespace and the step's id, such as `fixtureaddon.slug-review`.
- **command**: the command whose form the step runs on, `<name>@<version>`.
- **position**: `before_submit` or `after_receipt`.
- **patches**: the paths the step may change, each below `ext.<namespace>` of a command it does not own, such as `fields.ext.fixtureaddon.fixture_slug`, or anywhere in a command of its own.
- **timeout**: 1 to 30 seconds.

## Files

| Path, in the addon's package | What it holds |
|---|---|
| `src/<Addon>ServiceProvider.php` | `new FlowStep($id, 'command.form.steps@1', '<command>', StepPosition::BeforeSubmit, ['<path>'])`, and the point in `acceptsExperimental` |
| `resources/panel/src/<Module>.tsx` | the step's component, a `FlowStep<D, Path>` that ends with `next()` or `cancel()` when the viewer acts |
| `resources/panel/src/<Module>.test.tsx` | its test on `expectFlowStepContract()` |
| `resources/panel/src/<entry>.ts` | the registration's entry |

## Steps

1. Run `vendor/bin/testbench cms:make:panel step <namespace> <id> --point=command.form.steps@1 --command=<command> --position=<position> --patch=<path>`: it writes the stub, its test and the registration's entry, and prints the manifest line.
2. Add the line to the manifest and run `vendor/bin/testbench cms:build`.
3. Write the step with the kit's components: what it shows, what it patches, and the buttons that go on or stop.
4. Run the addon's JS tests, build the bundle, and run `vendor/bin/testbench cms:build` again.

## Checks

- `cms:build` refuses a path that is not in the command's schema, or one outside `ext.<namespace>` of a command the addon does not declare (`registry_panel_flow_path_unknown`); the host refuses and reports a patch outside the declared paths.
- `expectFlowStepContract()` fails on a step that patches another path, or that ends the flow before the viewer acts.
- The core's confirmation runs after the last step, and `tests/Browser/Panel/PanelAddonsTest.php` runs the fixture addon's steps on the form in Chromium.

## Running example

The step's component:

<!-- example-file: workbench/addons/fixtureaddon/resources/panel/src/SlugReview.tsx -->
```tsx
// The fixture addon's step before the submit of entry.create's form (section 3.8 of the panel
// extension architecture): it shows the slug the article gets, the one set by hand or the one
// derived from the title, lets the viewer take it, which patches the addon's own field of the
// draft, the one path the step's manifest declares, and go on to the core's confirmation, or stop,
// which cancels the flow in the addon's name. An entry of another type has no slug to review, and
// the step offers to go on.

import { usePanelHost, type StepProps } from '@cboxdk/cms-panel/extend';
import { Button, Callout, Inline, Stack } from '@cboxdk/cms-panel/experimental';

import type { EntryCreateV1 } from '../generated/contributions';
import { SLUG_PATH, isArticle, slugIn, slugOf, titleOf } from './slug';

export default function SlugReview({
  draft,
  patch,
  next,
  cancel,
}: StepProps<EntryCreateV1, typeof SLUG_PATH>) {
  const { t } = usePanelHost();
  const article = isArticle(draft);
  const slug = slugIn(draft) ?? slugOf(titleOf(draft) ?? '');

  return (
    <Callout
      tone="info"
      title={t(article ? 'fixtureaddon.slug_review.title' : 'fixtureaddon.slug_review.other_type')}
    >
      <Stack gap="sm">
        {article ? (
          <p data-fixtureaddon-slug={slug ?? ''}>
            {slug === null
              ? t('fixtureaddon.slug_review.none')
              : t('fixtureaddon.slug_review.slug', { slug })}
          </p>
        ) : null}
        <Inline gap="sm">
          <Button
            type="button"
            variant="primary"
            onClick={() => {
              if (article && slug !== null && slugIn(draft) === undefined) {
                patch(SLUG_PATH, slug);
              }

              next();
            }}
          >
            {t('fixtureaddon.slug_review.use')}
          </Button>
          <Button
            type="button"
            variant="quiet"
            onClick={() => {
              cancel('fixtureaddon.slug_review.cancelled');
            }}
          >
            {t('fixtureaddon.slug_review.stop')}
          </Button>
        </Inline>
      </Stack>
    </Callout>
  );
}
```

The step patching its declared path, and cancelling in the addon's name:

<!-- example: examples/Vitest/Panel/Recipes/flow-step.test.tsx -->
```tsx
// @vitest-environment jsdom

// A flow step before the submit, the recipe's real run: the fixture addon's
// fixtureaddon.slug-review is a flow step contribution to command.form.steps@1 on the form of
// entry.create, its component SlugReview, registered in the addon's panel.ts. It shows the slug the
// article gets, patches it into the draft at the one path its manifest declares, the addon's own
// field, when the viewer takes it, and goes on to the core's confirmation; stopping cancels the
// flow in the addon's name. The draft is of the command's generated type, as cms:panel:types writes
// it.

import { expectFlowStepContract, expectNoA11yViolations } from '@cboxdk/cms-panel/testing';
import { act } from 'react';
import { expect, test } from 'vitest';

import type { EntryCreateV1 } from '../../../../workbench/addons/fixtureaddon/resources/panel/generated/contributions';
import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';
import {
  ARTICLE_TYPE,
  SLUG_PATH,
} from '../../../../workbench/addons/fixtureaddon/resources/panel/src/slug';

const draft: EntryCreateV1 = {
  entry: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a11',
  fields: { fixture_title: 'A quiet week' },
  home: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a12',
  type: ARTICLE_TYPE,
};

const texts = {
  'fixtureaddon.slug_review.title': 'The slug',
  'fixtureaddon.slug_review.slug': 'The article gets the slug {slug}.',
  'fixtureaddon.slug_review.use': 'Use it',
  'fixtureaddon.slug_review.stop': 'Stop',
};

async function press(container: HTMLElement, text: string): Promise<void> {
  const button = [...container.querySelectorAll('button')].find(
    (candidate) => candidate.textContent === text,
  );

  await act(async () => {
    button?.click();
    await Promise.resolve();
  });
}

test('fixtureaddon.slug-review patches the slug into its declared path and goes on', async () => {
  const rendered = await expectFlowStepContract({
    addon,
    id: 'fixtureaddon.slug-review',
    draft,
    patches: [SLUG_PATH],
    host: { namespace: 'fixtureaddon', texts },
  });

  expect(rendered.container.textContent).toContain('The article gets the slug a-quiet-week.');
  await expectNoA11yViolations(rendered.container);

  await press(rendered.container, 'Use it');

  expect(rendered.step.patches).toEqual([{ path: SLUG_PATH, value: 'a-quiet-week' }]);
  expect(rendered.step.refusedPatches).toEqual([]);
  expect(rendered.step.next).toBe(1);
  await rendered.unmount();
});

test('fixtureaddon.slug-review cancels the flow in the addon s name when the viewer stops', async () => {
  const rendered = await expectFlowStepContract({
    addon,
    id: 'fixtureaddon.slug-review',
    draft,
    patches: [SLUG_PATH],
    host: { namespace: 'fixtureaddon', texts },
  });

  await press(rendered.container, 'Stop');

  expect(rendered.step.cancelled).toBe('fixtureaddon.slug_review.cancelled');
  expect(rendered.step.next).toBe(0);
  await rendered.unmount();
});
```
