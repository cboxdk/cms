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
