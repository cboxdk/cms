---
title: "command.form.checks@1"
weight: 50
description: "The checks of the generic command form: issues on the draft of one command, run on every edit, which block the submit only when they mirror a hook of the addon."
---

# command.form.checks@1

<!-- extension-point: Cbox\Cms\Panel\CommandForm\Domain\Dto\CommandFormChecksV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/command.form.checks.v1.json -->

The checks of the [command form](../command-form.md#checks-and-the-mirror-rule), run on the draft of one command as the viewer edits it.

| | |
|---|---|
| Kind | [form check](../kinds/form-check.md) |
| Page | `command.form` |
| Props | none: `CommandFormChecksV1` has no members; a check gets the command's document and a `CheckContext` |
| TypeScript | `FormCheck<D>` of `@cboxdk/cms-panel/extend`, with `D` the command's document type `cms:panel:types` writes |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | none |
| Fixture addon | on `entry.create@1`: `fixtureaddon.slug-hint` (warning), `fixtureaddon.slug-override` (acknowledge) and `fixtureaddon.slug-shape` (error, mirroring `RequireWellFormedSlug`); on `grant.assign@1`: `fixtureaddon.self-grant` (error, mirroring the authorize hook `DenySelfGrant`) |

A contribution is a `FormCheck` that names its command, `<name>@<version>`, and its severity. An issue of severity `info` or `warning` is listed below the fields, one of severity `acknowledge` holds the run until the viewer ticks it, and one of severity `error` is shown at its field and blocks the run, which only a check that mirrors a hook of its addon on the same command may do. After a submit the kernel's errors replace the checks' issues at their paths.

## Example

The fixture addon's checks of `entry.create`, each at its declared severity:

<!-- example: examples/Vitest/Panel/Points/command-form-checks.test.ts -->
```ts
// Form check contributions to command.form.checks@1, run on the draft of the generic command form
// on every edit: the fixture addon's checks of entry.create's form keep the form check contract on
// a document of the command, each answering within the host's budget with issues in the addon's
// namespace no heavier than its manifest declares, and the same twice. slug-hint warns where the
// title derives no slug, slug-override asks the viewer to acknowledge a slug set by hand, and
// slug-shape blocks a slug that is not well formed, which it may only because it mirrors the
// addon's hook RequireWellFormedSlug. The documents are of the command's generated type, as
// cms:panel:types writes it.

import { expectFormCheckContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import type { EntryCreateV1 } from '../../../../workbench/addons/fixtureaddon/resources/panel/generated/contributions';
import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';
import { ARTICLE_TYPE } from '../../../../workbench/addons/fixtureaddon/resources/panel/src/slug';

const article: EntryCreateV1 = {
  entry: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a11',
  fields: { fixture_title: 'A quiet week' },
  home: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a12',
  type: ARTICLE_TYPE,
};

const noSlug: EntryCreateV1 = { ...article, fields: { fixture_title: '!!!' } };

const byHand: EntryCreateV1 = {
  ...article,
  fields: {
    fixture_title: 'A quiet week',
    ext: { fixtureaddon: { fixture_slug: 'A quiet Week' } },
  },
};

test('the checks of entry.create keep the form check contract at their declared severity', () => {
  const hints = expectFormCheckContract({
    addon,
    id: 'fixtureaddon.slug-hint',
    namespace: 'fixtureaddon',
    severity: 'warning',
    documents: [article, noSlug],
  });
  const overrides = expectFormCheckContract({
    addon,
    id: 'fixtureaddon.slug-override',
    namespace: 'fixtureaddon',
    severity: 'acknowledge',
    documents: [article, byHand],
  });
  const shapes = expectFormCheckContract({
    addon,
    id: 'fixtureaddon.slug-shape',
    namespace: 'fixtureaddon',
    severity: 'error',
    documents: [article, byHand],
  });

  expect(hints).toEqual([
    [],
    [
      {
        path: 'fields.fixture_title',
        code: 'fixtureaddon.slug_hint',
        severity: 'warning',
        message: 'fixtureaddon.slug_hint.message',
      },
    ],
  ]);
  expect(overrides[1]).toMatchObject([
    { severity: 'acknowledge', parameters: { derived: 'a-quiet-week' } },
  ]);
  expect(shapes[1]).toMatchObject([
    {
      path: 'fields.ext.fixtureaddon.fixture_slug',
      severity: 'error',
      code: 'fixtureaddon.slug_shape',
    },
  ]);
});
```
