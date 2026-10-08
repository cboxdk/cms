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
