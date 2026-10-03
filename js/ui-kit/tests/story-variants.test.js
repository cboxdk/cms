// The stories of every component of the kit show it in the dark theme, in forced colours and in
// Danish, and every component has its MDX page (GUARDRAILS 8; the panel extension architecture,
// section 2.7): each story file whose title is under Components exports the stories Dark,
// ForcedColors and Danish, made with inDark, inForcedColours and inDanish of stories/csf.ts, so
// each has a baseline of its own in gate 7; and each story file has an MDX page beside it,
// <Name>.mdx, that is the docs page of its stories (<Meta of={...} />), with its stability, its do
// and don't and its props. `npm run test:kit -- story-variants` runs this file.

import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, test } from 'vitest';

import { ROOT } from './kit.js';

const STORIES = join(ROOT, 'js/ui-kit/stories');

/** Each variant every component has, by the export that holds it and the helper that makes it. */
export const VARIANTS = [
  { story: 'Dark', helper: 'inDark' },
  { story: 'ForcedColors', helper: 'inForcedColours' },
  { story: 'Danish', helper: 'inDanish' },
];

/**
 * The variants a story file of a component lacks, as messages.
 *
 * @param {string} text
 * @returns {string[]}
 */
export function missingVariants(text) {
  if (!/title: 'Components\//.test(text)) {
    return [];
  }

  return VARIANTS.filter(
    ({ story, helper }) =>
      !new RegExp(`^export const ${story}: Story = ${helper}\\(\\w+\\);$`, 'm').test(text),
  ).map(({ story, helper }) => `has no story ${story} = ${helper}(<story>)`);
}

describe('the stories of each component', () => {
  const files = readdirSync(STORIES)
    .filter((file) => file.endsWith('.stories.tsx'))
    .sort();

  test('are read', () => {
    assert.ok(files.length > 0);
  });

  for (const file of files) {
    test(`show ${file} in the dark theme, in forced colours and in Danish`, () => {
      assert.deepEqual(missingVariants(readFileSync(join(STORIES, file), 'utf8')), []);
    });
  }

  test('are held to the variants, and a story outside Components is not', () => {
    const component = "const meta = { title: 'Components/Forms/Field' };\n";

    assert.deepEqual(missingVariants(component), [
      'has no story Dark = inDark(<story>)',
      'has no story ForcedColors = inForcedColours(<story>)',
      'has no story Danish = inDanish(<story>)',
    ]);
    assert.deepEqual(
      missingVariants(
        `${component}export const Dark: Story = inDark(Default);\nexport const ForcedColors: Story = inForcedColours(Default);\nexport const Danish: Story = inDanish(Default);\n`,
      ),
      [],
    );
    assert.deepEqual(missingVariants("const meta = { title: 'Foundations/Icon' };\n"), []);
  });
});

/**
 * What keeps an MDX page from being the docs page of a story file's stories, as messages.
 *
 * @param {string} name the story file's name without .stories.tsx
 * @param {string | null} mdx the page's text, or null when there is none
 * @returns {string[]}
 */
export function mdxProblems(name, mdx) {
  if (mdx === null) {
    return [`has no MDX page ${name}.mdx`];
  }

  /** @type {[string | RegExp, string][]} */
  const parts = [
    [`import * as ${name}Stories from './${name}.stories';`, 'import its stories'],
    [`<Meta of={${name}Stories} />`, 'name its stories in its Meta'],
    [
      /^Stability: \*\*(stable|experimental)\*\*\. Since: \*\*\d+\.\d+\*\*\.$/m,
      'say its stability and since',
    ],
    ["## Do and don't", "have do and don't"],
    [`<ArgTypes of={${name}Stories} />`, 'show its props'],
  ];

  return parts
    .filter(([pattern]) =>
      typeof pattern === 'string' ? !mdx.includes(pattern) : !pattern.test(mdx),
    )
    .map(([, what]) => `${name}.mdx does not ${what}`);
}

describe('the MDX page of each component', () => {
  const names = readdirSync(STORIES)
    .filter((file) => file.endsWith('.stories.tsx'))
    .map((file) => file.slice(0, -'.stories.tsx'.length))
    .sort();

  for (const name of names) {
    test(`documents ${name}`, () => {
      const path = join(STORIES, `${name}.mdx`);

      assert.deepEqual(mdxProblems(name, existsSync(path) ? readFileSync(path, 'utf8') : null), []);
    });
  }

  test('is held to its parts', () => {
    assert.deepEqual(mdxProblems('Field', null), ['has no MDX page Field.mdx']);
    assert.deepEqual(mdxProblems('Field', "import * as FieldStories from './Field.stories';\n"), [
      'Field.mdx does not name its stories in its Meta',
      'Field.mdx does not say its stability and since',
      "Field.mdx does not have do and don't",
      'Field.mdx does not show its props',
    ]);
  });
});
