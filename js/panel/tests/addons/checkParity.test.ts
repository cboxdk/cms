// The mirror rule held by checkParity (section 3.7 of the panel extension architecture): the
// fixture addon's blocking check fixtureaddon.slug-shape blocks the submit of entry.create's form
// only because it mirrors the addon's hook RequireWellFormedSlug, and the two must agree. The
// hook's verdicts come from the addon's PHP test, RequireWellFormedSlugTest, which holds the hook
// to the cases of resources/panel/parity/slug-shape.json; this test holds the check to the same
// cases, so a change to either side that the other does not follow fails here or there. The
// warning and acknowledge checks are the addon's own and mirror nothing, so checkParity does not
// apply to them; they are held to their contract instead.

import { definePanelAddon } from '@cboxdk/cms-panel/extend';
import { checkParity, expectFormCheckContract, ParityBroken } from '@cboxdk/cms-panel/testing';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, test } from 'vitest';

import type {
  Contributions,
  EntryCreateV1,
  GrantAssignV1,
} from '../../../../workbench/addons/fixtureaddon/resources/panel/generated/contributions';
import {
  selfGrant,
  slugHint,
  slugOverride,
  slugShape,
} from '../../../../workbench/addons/fixtureaddon/resources/panel/src/checks';
import {
  ARTICLE_TYPE,
  slugOf,
} from '../../../../workbench/addons/fixtureaddon/resources/panel/src/slug';

interface ParityCase {
  readonly name: string;
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

interface SelfGrantCase {
  readonly name: string;
  readonly viewer: string | null;
  readonly document: GrantAssignV1;
  readonly refused: readonly string[];
}

const SELF_GRANT_PARITY = JSON.parse(
  readFileSync(
    join(
      import.meta.dirname,
      '../../../../workbench/addons/fixtureaddon/resources/panel/parity/self-grant.json',
    ),
    'utf8',
  ),
) as { readonly cases: readonly SelfGrantCase[] };

/** A module that is never rendered here: the parity tests hold the checks, not the components. */
const notRendered = () => Promise.reject(new Error('Not rendered here.'));

const addon = definePanelAddon<Contributions>({
  'fixtureaddon.activity': () => undefined,
  'fixtureaddon.articles': notRendered,
  'fixtureaddon.articles-permission': notRendered,
  'fixtureaddon.dry-run-note': notRendered,
  'fixtureaddon.faulty': notRendered,
  'fixtureaddon.four-eyes': notRendered,
  'fixtureaddon.four-eyes-note': notRendered,
  'fixtureaddon.my-articles': notRendered,
  'fixtureaddon.receipt-note': () => ({}),
  'fixtureaddon.recent-activity': notRendered,
  'fixtureaddon.self-grant': selfGrant,
  'fixtureaddon.slug-help': notRendered,
  'fixtureaddon.slug-hint': slugHint,
  'fixtureaddon.slug-input': notRendered,
  'fixtureaddon.slug-override': slugOverride,
  'fixtureaddon.slug-review': notRendered,
  'fixtureaddon.slug-shape': slugShape,
  'fixtureaddon.submit-note': () => ({}),
});

/** The hook's verdict on a document, as the PHP test recorded it; checkParity hands a frozen copy. */
function recorded(document: EntryCreateV1): readonly string[] {
  const text = JSON.stringify(document);
  const found = PARITY.cases.find((candidate) => JSON.stringify(candidate.document) === text);

  if (found === undefined) {
    throw new Error('The document is not a recorded case.');
  }

  return found.refused;
}

describe('the fixture addon s mirrored check', () => {
  test('blocks exactly the documents its hook refuses, on every recorded case', async () => {
    const documents = PARITY.cases.map((candidate) => candidate.document);

    expect(documents.length).toBeGreaterThanOrEqual(5);
    await expect(checkParity(slugShape, recorded, documents)).resolves.toBe(documents.length);
  });

  test('would be caught by checkParity if it blocked more than the hook refuses', async () => {
    const stricter: typeof slugShape = (document) => [
      ...slugShape(document, { locale: 'en', viewer: null }),
      ...(document.fields.ext === undefined
        ? [
            {
              path: 'fields.ext.fixtureaddon.fixture_slug',
              code: 'fixtureaddon.slug_shape',
              severity: 'error' as const,
              message: 'fixtureaddon.slug_shape.message',
            },
          ]
        : []),
    ];

    await expect(
      checkParity(
        stricter,
        recorded,
        PARITY.cases.map((candidate) => candidate.document),
      ),
    ).rejects.toBeInstanceOf(ParityBroken);
  });
});

describe('the fixture addon s checks', () => {
  const documents = PARITY.cases.map((candidate) => candidate.document);

  test('keep the contract of a form check at the severity their manifest declares', () => {
    expectFormCheckContract({
      addon,
      id: 'fixtureaddon.slug-hint',
      documents,
      severity: 'warning',
      namespace: 'fixtureaddon',
    });
    expectFormCheckContract({
      addon,
      id: 'fixtureaddon.slug-override',
      documents,
      severity: 'acknowledge',
      namespace: 'fixtureaddon',
    });
    expectFormCheckContract({
      addon,
      id: 'fixtureaddon.slug-shape',
      documents,
      severity: 'error',
      namespace: 'fixtureaddon',
    });
  });

  test('derive a slug as DeriveSlug does, warn where none can be derived, and ask to acknowledge one set by hand', () => {
    expect(slugOf('A quiet week')).toBe('a-quiet-week');
    expect(slugOf('  --Æ Ø å!!  ')).toBeNull();
    expect(slugOf('Æbleskiver & Co.')).toBe('bleskiver-co');
    expect(slugOf('!!!')).toBeNull();
    expect(slugOf('x'.repeat(130))).toBe('x'.repeat(120));
    expect(slugOf(`${'x'.repeat(119)}-y`)).toBe('x'.repeat(119));

    const article = PARITY.cases[0]?.document ?? documents[0];

    if (article === undefined) {
      throw new Error('No case.');
    }

    const noSlug: EntryCreateV1 = { ...article, fields: { fixture_title: '!!!' } };
    const derived: EntryCreateV1 = {
      ...article,
      fields: {
        fixture_title: 'A quiet week',
        ext: { fixtureaddon: { fixture_slug: 'a-quiet-week' } },
      },
    };
    const byHand: EntryCreateV1 = {
      ...article,
      fields: {
        fixture_title: 'A quiet week',
        ext: { fixtureaddon: { fixture_slug: 'another-slug' } },
      },
    };
    const other: EntryCreateV1 = { ...noSlug, type: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a09' };

    expect(slugHint(noSlug, { locale: 'en', viewer: null })).toMatchObject([
      { path: 'fields.fixture_title', severity: 'warning' },
    ]);
    // The draft as the viewer edits it may lack the fields member, which the form fills last.
    expect(slugHint({ type: ARTICLE_TYPE } as EntryCreateV1, { locale: 'en', viewer: null })).toMatchObject([
      { path: 'fields.fixture_title', severity: 'warning' },
    ]);
    expect(slugShape({ type: ARTICLE_TYPE } as EntryCreateV1, { locale: 'en', viewer: null })).toEqual([]);
    expect(slugOverride({} as EntryCreateV1, { locale: 'en', viewer: null })).toEqual([]);
    expect(slugHint(derived, { locale: 'en', viewer: null })).toEqual([]);
    expect(slugHint(other, { locale: 'en', viewer: null })).toEqual([]);
    expect(slugOverride(derived, { locale: 'en', viewer: null })).toEqual([]);
    expect(slugOverride(byHand, { locale: 'en', viewer: null })).toMatchObject([
      {
        path: 'fields.ext.fixtureaddon.fixture_slug',
        severity: 'acknowledge',
        parameters: { derived: 'a-quiet-week' },
      },
    ]);
  });
});

describe('the fixture addon s self-grant check', () => {
  /** The hook's verdict on a document for the viewer of its case, as DenySelfGrantTest recorded it. */
  function recordedSelfGrant(viewer: string | null): (document: GrantAssignV1) => readonly string[] {
    return (document) => {
      const text = JSON.stringify(document);
      const found = SELF_GRANT_PARITY.cases.find(
        (candidate) => candidate.viewer === viewer && JSON.stringify(candidate.document) === text,
      );

      if (found === undefined) {
        throw new Error('The document is not a recorded case of the viewer.');
      }

      return found.refused;
    };
  }

  test('blocks exactly the grants its hook refuses, for the viewer of each recorded case', async () => {
    expect(SELF_GRANT_PARITY.cases.length).toBeGreaterThanOrEqual(4);

    for (const candidate of SELF_GRANT_PARITY.cases) {
      await expect(
        checkParity(selfGrant, recordedSelfGrant(candidate.viewer), [candidate.document], {
          viewer: candidate.viewer,
        }),
      ).resolves.toBe(1);
    }
  });

  test('reads the viewer from the check s context: the same grant blocks for the grantee and no one else', () => {
    const self = SELF_GRANT_PARITY.cases.find((candidate) => candidate.refused.length > 0);

    if (self === undefined || self.viewer === null) {
      throw new Error('No case of a grant to oneself.');
    }

    expect(selfGrant(self.document, { locale: 'en', viewer: self.viewer })).toEqual([
      {
        path: 'actor',
        code: 'fixtureaddon.self_grant',
        severity: 'error',
        message: 'fixtureaddon.self_grant.message',
      },
    ]);
    expect(selfGrant(self.document, { locale: 'en', viewer: self.viewer.toUpperCase() })).toHaveLength(1);
    expect(selfGrant(self.document, { locale: 'da', viewer: null })).toEqual([]);
    expect(
      selfGrant(self.document, { locale: 'en', viewer: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5aff' }),
    ).toEqual([]);
    expect(selfGrant({} as GrantAssignV1, { locale: 'en', viewer: self.viewer })).toEqual([]);
  });

  test('keeps the contract of a form check at the severity its manifest declares', () => {
    expectFormCheckContract({
      addon,
      id: 'fixtureaddon.self-grant',
      documents: SELF_GRANT_PARITY.cases.map((candidate) => candidate.document),
      severity: 'error',
      namespace: 'fixtureaddon',
      viewer: SELF_GRANT_PARITY.cases[0]?.viewer ?? null,
    });
  });
});
