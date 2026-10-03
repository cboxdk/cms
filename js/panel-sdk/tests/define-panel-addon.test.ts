// definePanelAddon() (section 3.1 of the panel extension architecture): tsc refuses a
// registration with a missing key, an extra key or a component of other props, each with its own
// error, and at run time the registration holds its contributions frozen, their ids sorted and
// the version of the panel's API, and refuses what tsc would have.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, test } from 'vitest';

import {
  definePanelAddon,
  InvalidPanelAddon,
  PANEL_API_VERSION,
  type FormCheck,
  type Lazy,
  type SlotComponent,
} from '@cboxdk/cms-panel/extend';

import { CONTRIBUTION_ID, CONTRIBUTION_ID_MAX_LENGTH } from '../src/addon';
import { compileProbe, ROOT } from './sdk.js';

const CONTRIBUTIONS = readFileSync(join(import.meta.dirname, 'types/contributions.ts'), 'utf8');

/** A probe that registers the body below with definePanelAddon<Contributions>(...). */
function probe(registration: string): string {
  return [
    "import { definePanelAddon, type SlotProps } from '@cboxdk/cms-panel/extend';",
    "import type { Contributions, NoteCardV1, NotesPendingResultV1 } from './contributions';",
    'function Badge(input: SlotProps<NoteCardV1, NotesPendingResultV1>): string {',
    "  return input.data.status === 'ready' ? String(input.data.value.count) : input.props.title;",
    '}',
    'function Summary(input: SlotProps<NoteCardV1>): string {',
    '  return input.props.owner;',
    '}',
    'function WrongProps(input: { readonly props: { readonly title: number } }): string {',
    '  return String(input.props.title);',
    '}',
    'const complete = {',
    "  'reviews.badge': () => Promise.resolve({ default: Badge }),",
    "  'reviews.summary': () => Promise.resolve({ default: Summary }),",
    "  'reviews.title-check': () => [],",
    "  'reviews.submit': () => ({ tighten: { disabled_reason: 'reviews.locked' } }),",
    '} as const;',
    'void WrongProps;',
    `export const registered = definePanelAddon<Contributions>(${registration});`,
    '',
  ].join('\n');
}

function errorsOf(registration: string) {
  return compileProbe({ 'probe.ts': probe(registration), 'contributions.ts': CONTRIBUTIONS });
}

describe('the types of definePanelAddon()', () => {
  test(
    'accept exactly the keys of Contributions, each with the props its kind gives',
    { timeout: 60_000 },
    () => {
      assert.deepEqual(errorsOf('complete'), []);
    },
  );

  test('refuse a missing key, naming it', { timeout: 60_000 }, () => {
    const errors = errorsOf(
      "{ 'reviews.badge': complete['reviews.badge'], 'reviews.summary': complete['reviews.summary'], 'reviews.title-check': complete['reviews.title-check'] }",
    );

    assert.equal(errors.length, 1);
    assert.equal(errors[0]?.code, 2345);
    assert.match(errors[0].message, /Property '+reviews\.submit'+ is missing/);
  });

  test('refuse an extra key, naming it', { timeout: 60_000 }, () => {
    const errors = errorsOf("{ ...complete, 'reviews.unknown': () => [] }");

    assert.equal(errors.length, 1);
    assert.equal(errors[0]?.code, 2353);
    assert.match(errors[0].message, /'+reviews\.unknown'+ does not exist in type 'Contributions'/);
  });

  test('refuse a component whose props the point does not give', { timeout: 60_000 }, () => {
    const errors = errorsOf(
      "{ ...complete, 'reviews.summary': () => Promise.resolve({ default: WrongProps }) }",
    );

    assert.equal(errors.length, 1);
    assert.equal(errors[0]?.code, 2322);
    assert.match(errors[0].message, /title/);
    assert.match(errors[0].message, /'number'/);
  });

  test(
    'refuse a decorator that tightens a prop its manifest does not declare',
    { timeout: 60_000 },
    () => {
      const errors = errorsOf(
        "{ ...complete, 'reviews.submit': () => ({ tighten: { description: 'reviews.note' } }) }",
      );

      assert.equal(errors.length, 1);
      assert.match(errors[0]?.message ?? '', /'description' does not exist/);
    },
  );
});

describe('definePanelAddon() at run time', () => {
  interface Contributions {
    readonly 'reviews.summary': Lazy<SlotComponent<{ readonly title: string }>>;
    readonly 'reviews.check': FormCheck<{ readonly title: string }>;
  }

  const summary: Contributions['reviews.summary'] = () =>
    Promise.resolve({
      default: (input: { readonly props: { readonly title: string } }) => input.props.title,
    });
  const check: Contributions['reviews.check'] = () => [];

  test('holds the contributions frozen, with the ids sorted and the version of the panel API', () => {
    const addon = definePanelAddon<Contributions>({
      'reviews.summary': summary,
      'reviews.check': check,
    });

    assert.deepEqual(addon.ids, ['reviews.check', 'reviews.summary']);
    assert.equal(addon.contributions['reviews.check'], check);
    assert.deepEqual(addon.sdk, PANEL_API_VERSION);
    assert.ok(
      Object.isFrozen(addon) && Object.isFrozen(addon.contributions) && Object.isFrozen(addon.ids),
    );
  });

  test('refuses an id that is not the namespace and a name, a value that is no function, and two namespaces', () => {
    const untyped = definePanelAddon as (
      contributions: Readonly<Record<string, unknown>>,
    ) => unknown;

    assert.throws(() => untyped({ badge: summary }), InvalidPanelAddon);
    assert.throws(
      () => untyped({ [`reviews.${'a'.repeat(CONTRIBUTION_ID_MAX_LENGTH)}`]: summary }),
      InvalidPanelAddon,
    );
    assert.throws(() => untyped({ 'reviews.summary': 'ApprovalsBadge' }), /is not a function/);
    assert.throws(
      () => untyped({ 'reviews.summary': summary, 'approvals.check': check }),
      /approvals, reviews/,
    );
  });

  test('reads a contribution id as the PHP side does', () => {
    const php = readFileSync(
      join(ROOT, 'packages/contracts/src/PanelPoints/ContributionId.php'),
      'utf8',
    );
    const pattern = /PATTERN = '\/\\A(.+)\\z\/';/.exec(php)?.[1];
    const length = /MAX_LENGTH = (\d+);/.exec(php)?.[1];

    assert.equal(CONTRIBUTION_ID.source, `^${pattern ?? ''}$`);
    assert.equal(CONTRIBUTION_ID_MAX_LENGTH, Number(length));
  });

  test('has the version of the panel API the PHP side checks addons against', () => {
    const php = readFileSync(
      join(ROOT, 'packages/contracts/src/PanelPoints/PanelApiVersion.php'),
      'utf8',
    );

    assert.deepEqual(PANEL_API_VERSION, {
      major: Number(/CURRENT_MAJOR = (\d+);/.exec(php)?.[1]),
      minor: Number(/CURRENT_MINOR = (\d+);/.exec(php)?.[1]),
    });
  });
});
