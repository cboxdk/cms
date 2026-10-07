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
} from '../../../../workbench/addons/fixtureaddon/resources/panel/generated/contributions';
import {
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

const addon = definePanelAddon<Contributions>({
  'fixtureaddon.slug-hint': slugHint,
  'fixtureaddon.slug-override': slugOverride,
  'fixtureaddon.slug-shape': slugShape,
  'fixtureaddon.slug-review': () => Promise.reject(new Error('Not rendered here.')),
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
      ...slugShape(document, { locale: 'en' }),
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

    expect(slugHint(noSlug, { locale: 'en' })).toMatchObject([
      { path: 'fields.fixture_title', severity: 'warning' },
    ]);
    // The draft as the viewer edits it may lack the fields member, which the form fills last.
    expect(slugHint({ type: ARTICLE_TYPE } as EntryCreateV1, { locale: 'en' })).toMatchObject([
      { path: 'fields.fixture_title', severity: 'warning' },
    ]);
    expect(slugShape({ type: ARTICLE_TYPE } as EntryCreateV1, { locale: 'en' })).toEqual([]);
    expect(slugOverride({} as EntryCreateV1, { locale: 'en' })).toEqual([]);
    expect(slugHint(derived, { locale: 'en' })).toEqual([]);
    expect(slugHint(other, { locale: 'en' })).toEqual([]);
    expect(slugOverride(derived, { locale: 'en' })).toEqual([]);
    expect(slugOverride(byHand, { locale: 'en' })).toMatchObject([
      {
        path: 'fields.ext.fixtureaddon.fixture_slug',
        severity: 'acknowledge',
        parameters: { derived: 'a-quiet-week' },
      },
    ]);
  });
});
