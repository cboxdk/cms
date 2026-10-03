// What `cms:panel:types` writes, held to the SDK: the golden module of the PHP generator's fixture
// addon, packages/generators/tests/PanelTypes/Fixtures/contributions.ts.golden, compiles against
// @cboxdk/cms-panel/extend with the fixture points' props in place of the SDK's, so an addon's
// definePanelAddon<Contributions>() takes a registration with every member and usePanelHost<Issues>()
// issues only its commands; and Prettier leaves the module as it is.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import * as prettier from 'prettier';
import { describe, test } from 'vitest';

import { compileProbe, ROOT } from './sdk.js';

const GOLDEN = 'packages/generators/tests/PanelTypes/Fixtures/contributions.ts.golden';

/** The golden module, importing the fixture points' props from modules beside it. */
function golden(): string {
  return readFileSync(join(ROOT, GOLDEN), 'utf8')
    .replace("from '@cboxdk/cms-panel/extend';", "from './extend';")
    .replace("from '@cboxdk/cms-panel/experimental';", "from './experimental';");
}

const POINTS = {
  'extend.ts': [
    "export * from '@cboxdk/cms-panel/extend';",
    'export interface NoteToolbarV1 { readonly filter: string; }',
    '',
  ].join('\n'),
  'experimental.ts':
    'export interface NoteCardV1 { readonly owner: string; readonly title: string; }\n',
};

const REGISTRATION = [
  "import { definePanelAddon, usePanelHost, type SlotProps } from '@cboxdk/cms-panel/extend';",
  "import type { Contributions, Issues, ReviewsPendingResultV1 } from './contributions';",
  "import type { NoteCardV1 } from './experimental';",
  '',
  'function Badge(input: SlotProps<NoteCardV1, ReviewsPendingResultV1>): string {',
  "  return input.data.status === 'ready' ? String(input.data.value.count) : input.props.title;",
  '}',
  '',
  'export function useRequest(): Promise<unknown> {',
  "  return usePanelHost<Issues>().runCommand('reviews.request@1', { note: 'n', priority: 1 });",
  '}',
  '',
  'export const addon = definePanelAddon<Contributions>({',
  "  'reviews.audit': (event) => void event.filter,",
  "  'reviews.badge': () => Promise.resolve({ default: Badge }),",
  "  'reviews.field': () => Promise.resolve({ default: (props: NoteCardV1) => props.title }),",
  "  'reviews.four-eyes': () => Promise.resolve({ default: (step: { readonly next: () => void }) => String(step.next) }),",
  "  'reviews.frame': () => Promise.resolve({ default: (frame: { readonly children: unknown }) => String(frame.children) }),",
  "  'reviews.queue': () => Promise.resolve({ default: () => 'queue' }),",
  "  'reviews.submit': () => ({ tighten: { tone_towards_danger: 'danger' } }),",
  "  'reviews.summary': () => Promise.resolve({ default: (slot: { readonly props: NoteCardV1 }) => slot.props.owner }),",
  "  'reviews.title-check': (document) => (document.title === '' ? [{ path: 'title', code: 'reviews.empty', severity: 'warning', message: 'reviews.empty' }] : []),",
  '});',
  '',
].join('\n');

describe('the module cms:panel:types writes', () => {
  test(
    'compiles against the SDK and types the registration and the commands',
    { timeout: 60_000 },
    () => {
      assert.deepEqual(compileProbe({ 'contributions.ts': golden(), ...POINTS }), []);
      assert.deepEqual(
        compileProbe({ 'probe.ts': REGISTRATION, 'contributions.ts': golden(), ...POINTS }),
        [],
      );
    },
  );

  test(
    'refuses a command the addon may not issue and a document of other members',
    { timeout: 60_000 },
    () => {
      const errors = compileProbe({
        'probe.ts': [
          "import { usePanelHost } from '@cboxdk/cms-panel/extend';",
          "import type { Issues } from './contributions';",
          "export const other = () => usePanelHost<Issues>().runCommand('grant.assign@1', {});",
          "export const wrong = () => usePanelHost<Issues>().runCommand('reviews.request@1', { note: 1, priority: 1 });",
          '',
        ].join('\n'),
        'contributions.ts': golden(),
        ...POINTS,
      });

      assert.deepEqual(
        errors.map((error) => [error.line, error.code]),
        [
          [3, 2345],
          [4, 2322],
        ],
      );
    },
  );

  test('is as Prettier prints it', async () => {
    const source = readFileSync(join(ROOT, GOLDEN), 'utf8');
    const options = (await prettier.resolveConfig(join(ROOT, 'js/panel-sdk/src/extend.ts'))) ?? {};

    assert.equal(await prettier.format(source, { ...options, parser: 'typescript' }), source);
  });
});
