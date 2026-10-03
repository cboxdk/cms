// The story-per-export check of gate 7 (scripts/story-exports.js): which exports of the kit's entry
// are components, which component a story file covers, and that a component without a story is
// reported at its line in the entry, as is anything that keeps the check from reading a file.
// `npm run test:kit -- story-exports` runs this file.

import assert from 'node:assert/strict';
import { describe, test } from 'vitest';

import {
  ENTRY,
  isComponentName,
  kitComponents,
  storyComponent,
  storyExportProblems,
} from '../scripts/story-exports.js';

/** @param {string} text */
const entry = (text) => ({ path: ENTRY, text });

/** @param {string} name @param {string} text */
const story = (name, text) => ({ path: `js/ui-kit/stories/${name}.stories.tsx`, text });

const ENTRY_TEXT = [
  "export { Alert } from './components/Alert';",
  "export type { AlertProps } from './components/Alert';",
  "export { Button as KitButton, type ButtonProps } from './components/Button';",
  "export { KIT_LOCALES, isKitLocale, KitI18nProvider } from './i18n/KitI18nProvider';",
  'export function Badge(): null {',
  '  return null;',
  '}',
  'export const Divider = (): null => null;',
  '',
].join('\n');

describe('the story-per-export check', () => {
  test('counts a PascalCase name as a component, and not a constant or a function', () => {
    assert.ok(isComponentName('Alert'));
    assert.ok(isComponentName('KitI18nProvider'));
    assert.ok(!isComponentName('KIT_LOCALES'));
    assert.ok(!isComponentName('isKitLocale'));
    assert.ok(!isComponentName('A'));
  });

  test('reads the components the entry exports by name, under the name it exports them as', () => {
    const { components, problems } = kitComponents(entry(ENTRY_TEXT));

    assert.deepEqual(problems, []);
    assert.deepEqual(components, [
      { name: 'Alert', line: 1 },
      { name: 'KitButton', line: 3 },
      { name: 'KitI18nProvider', line: 4 },
      { name: 'Badge', line: 5 },
      { name: 'Divider', line: 8 },
    ]);
  });

  test('refuses an entry that hides its names behind export * or a default export', () => {
    const { problems } = kitComponents(
      entry("export * from './components/Alert';\nexport default function Panel() {}\n"),
    );

    assert.deepEqual(problems, [
      `${ENTRY}:1: export * hides the names the kit exports; name each one`,
      `${ENTRY}:2: a default export has no name to hold to a story; export it by name`,
    ]);
  });

  test('reads the component of a story file from its meta, through satisfies and an alias', () => {
    const covered = storyComponent(
      story(
        'Button',
        [
          "import { KitButton as Button } from '@cboxdk/cms-ui-kit';",
          '',
          "const meta = { title: 'Components/Button', component: Button } satisfies object;",
          '',
          'export default meta;',
          '',
        ].join('\n'),
      ),
    );
    const inline = storyComponent(
      story(
        'Alert',
        "import { Alert } from '@cboxdk/cms-ui-kit';\n\nexport default { component: Alert };\n",
      ),
    );

    assert.deepEqual(covered, { component: 'KitButton', problems: [] });
    assert.deepEqual(inline, { component: 'Alert', problems: [] });
  });

  test('accepts a story file whose meta names no component, such as a pattern', () => {
    assert.deepEqual(
      storyComponent(story('Forms', "export default { title: 'Patterns/Forms' };\n")),
      { component: null, problems: [] },
    );
  });

  test('refuses a meta whose component does not come from the kit package', () => {
    const { problems } = storyComponent(
      story(
        'Alert',
        "import { Alert } from '../src/components/Alert';\n\nexport default { component: Alert };\n",
      ),
    );

    assert.deepEqual(problems, [
      'js/ui-kit/stories/Alert.stories.tsx:3: the component of the meta is not a value imported from @cboxdk/cms-ui-kit',
    ]);
  });

  test('refuses a story file without a default export it can read', () => {
    assert.deepEqual(storyComponent(story('Empty', 'export const A = 1;\n')).problems, [
      'js/ui-kit/stories/Empty.stories.tsx:1: has no default export with the meta of its stories',
    ]);
    assert.deepEqual(
      storyComponent(story('Built', 'const build = () => ({});\nexport default build();\n'))
        .problems,
      [
        'js/ui-kit/stories/Built.stories.tsx:2: the default export is not an object literal, so the check cannot read its component',
      ],
    );
  });

  test('reports each component without a story at its line in the entry', () => {
    const problems = storyExportProblems(entry(ENTRY_TEXT), [
      story(
        'Alert',
        "import { Alert } from '@cboxdk/cms-ui-kit';\n\nexport default { component: Alert };\n",
      ),
      story(
        'Provider',
        "import { KitI18nProvider } from '@cboxdk/cms-ui-kit';\n\nexport default { component: KitI18nProvider };\n",
      ),
    ]);

    assert.deepEqual(
      problems.map((problem) => problem.split(';')[0]),
      [
        `${ENTRY}:3: the kit exports the component KitButton without a story`,
        `${ENTRY}:5: the kit exports the component Badge without a story`,
        `${ENTRY}:8: the kit exports the component Divider without a story`,
      ],
    );
  });
});
