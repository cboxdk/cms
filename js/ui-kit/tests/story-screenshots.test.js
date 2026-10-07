// Every story has its baseline, and the baselines of the panel's points were rendered from the
// stories as they are (GUARDRAILS 8, decision D10 of the panel extension architecture). The story
// tests of gate 7 compare each story with js/ui-kit/visual-baselines/<story id>.png, but gate 7
// runs only in CI, so a change that adds a story, or changes the stories of the panel's points
// through what cms:panel:stories generates, without writing the baselines anew passed every local
// gate and failed CI (B1-review, 7 October 2026). This file holds both in gate 5: each story of
// the files .storybook/main.ts finds has a baseline named by its story id, as vitest.setup.ts takes
// the screenshot, each baseline belongs to a story, and RENDERED_FROM holds the SHA-256 of the
// files the panel points' stories are rendered from as they are now. `composer image:run -- npm
// run storybook:baselines` writes both anew. `npm run test:kit -- story-screenshots` runs this file.

import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import ts from 'typescript';
import { describe, test } from 'vitest';

import {
  PANEL_POINT_SOURCES,
  RENDERED_FROM,
  renderedFrom,
  renderedFromRecord,
} from '../scripts/rendered-from.js';
import { ROOT } from './kit.js';

/** Where the baselines are, as vitest.config.ts resolves a screenshot's path. */
const BASELINES = join(ROOT, 'js/ui-kit/visual-baselines');

/** The directories .storybook/main.ts finds story files in, relative to the root. */
const STORY_DIRECTORIES = ['js/ui-kit/stories', 'js/panel/stories'];

/**
 * The name of a story's baseline: its story id as Vitest's toMatchScreenshot names the file, each
 * run of hyphens one hyphen, so panel-points--overview is panel-points-overview.png.
 *
 * @param {string} storyId
 * @returns {string}
 */
export function baselineName(storyId) {
  return `${storyId.replace(/-{2,}/g, '-')}.png`;
}

/**
 * A part of a story id as Storybook's sanitize() writes it: lower case, each run of spaces and
 * punctuation one hyphen, none at either end.
 *
 * @param {string} text
 * @returns {string}
 */
function sanitize(text) {
  return text
    .toLowerCase()
    .replace(/[ ’–—―′¿'`~!@#$%^&*()_|+\-=?;:'",.<>{}[\]\\/]/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-+/, '')
    .replace(/-+$/, '');
}

/**
 * The name Storybook gives the story of an export, as its storyNameFromExport() does: the words
 * of the export's name, split at each capital that starts a word and between letters and digits,
 * so LoginNoticeV1 is Login Notice V 1.
 *
 * @param {string} key
 * @returns {string}
 */
function storyNameFromExport(key) {
  return key
    .replace(/[_.-]/g, ' ')
    .replace(/([^\n])([A-Z])([a-z])/g, '$1 $2$3')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/([a-z])([0-9])/gi, '$1 $2')
    .replace(/([0-9])([a-z])/gi, '$1 $2')
    .replace(/(\s|^)(\w)/g, (word) => word.toUpperCase())
    .replace(/ +/g, ' ')
    .trim();
}

/**
 * The expression without the parentheses, `satisfies` and `as` around it.
 *
 * @param {ts.Expression} expression
 * @returns {ts.Expression}
 */
function unwrap(expression) {
  let current = expression;

  while (
    ts.isParenthesizedExpression(current) ||
    ts.isSatisfiesExpression(current) ||
    ts.isAsExpression(current)
  ) {
    current = current.expression;
  }

  return current;
}

/**
 * The ids of the stories of a story file, as Storybook indexes them: the title of the meta its
 * default export names, then each export by name, `<title>--<story name>` with each part
 * sanitized. A file whose meta has no literal title has no ids, and fails "are read".
 *
 * @param {string} path relative to the root
 * @param {string} text
 * @returns {string[]}
 */
export function storyIds(path, text) {
  const source = ts.createSourceFile(path, text, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
  /** @type {Map<string, ts.Expression>} */
  const constants = new Map();
  /** @type {string[]} */
  const exports = [];
  /** @type {ts.Expression | undefined} */
  let meta;

  for (const statement of source.statements) {
    if (ts.isVariableStatement(statement)) {
      const exported = (ts.getModifiers(statement) ?? []).some(
        (modifier) => modifier.kind === ts.SyntaxKind.ExportKeyword,
      );

      for (const declaration of statement.declarationList.declarations) {
        if (ts.isIdentifier(declaration.name)) {
          if (declaration.initializer !== undefined) {
            constants.set(declaration.name.text, declaration.initializer);
          }

          if (exported) {
            exports.push(declaration.name.text);
          }
        }
      }
    } else if (ts.isExportAssignment(statement) && !statement.isExportEquals) {
      meta = unwrap(statement.expression);
    }
  }

  const object = meta !== undefined && ts.isIdentifier(meta) ? constants.get(meta.text) : meta;
  const literal = object === undefined ? undefined : unwrap(object);
  const title =
    literal !== undefined && ts.isObjectLiteralExpression(literal)
      ? literal.properties.find(
          (property) =>
            ts.isPropertyAssignment(property) &&
            ts.isIdentifier(property.name) &&
            property.name.text === 'title' &&
            ts.isStringLiteralLike(property.initializer),
        )
      : undefined;

  if (
    title === undefined ||
    !ts.isPropertyAssignment(title) ||
    !ts.isStringLiteralLike(title.initializer)
  ) {
    return [];
  }

  const kind = sanitize(title.initializer.text);

  return exports.map((name) => `${kind}--${sanitize(storyNameFromExport(name))}`);
}

/**
 * Every story file below a directory, relative to the root, sorted.
 *
 * @param {string} directory relative to the root
 * @returns {string[]}
 */
function storyFiles(directory) {
  return readdirSync(join(ROOT, directory), { recursive: true, encoding: 'utf8' })
    .filter((file) => file.endsWith('.stories.tsx'))
    .map((file) => relative(ROOT, join(ROOT, directory, file)))
    .sort();
}

/**
 * What differs between the record and the sources, as messages.
 *
 * @param {string | null} record the record's text, or null when there is none
 * @param {Record<string, string>} digests
 * @returns {string[]}
 */
export function staleSources(record, digests) {
  if (record === null) {
    return [`${RENDERED_FROM} is missing`];
  }

  /** @type {unknown} */
  const recorded = JSON.parse(record);
  const entries = typeof recorded === 'object' && recorded !== null ? Object.entries(recorded) : [];
  const known = new Map(entries);

  return [
    ...Object.entries(digests)
      .filter(([path, digest]) => known.get(path) !== digest)
      .map(([path]) => `${path} changed since the baselines were rendered`),
    ...[...known.keys()]
      .filter((path) => !Object.hasOwn(digests, path))
      .map((path) => `${RENDERED_FROM} names ${path}, which the baselines are not rendered from`),
  ];
}

const ADVICE = 'write the baselines anew: composer image:run -- npm run storybook:baselines';

describe('the baselines of the stories', () => {
  const files = STORY_DIRECTORIES.flatMap(storyFiles);
  const stories = files.flatMap((file) =>
    storyIds(file, readFileSync(join(ROOT, file), 'utf8')).map((id) => ({ file, id })),
  );
  const baselines = readdirSync(BASELINES).filter((file) => file.endsWith('.png'));

  test('are read', () => {
    assert.ok(files.includes('js/panel/stories/generated/PanelPoints.stories.tsx'));
    assert.ok(stories.length > 0);
  });

  for (const file of files) {
    test(`include one for each story of ${file}`, () => {
      assert.deepEqual(
        stories
          .filter((story) => story.file === file && !baselines.includes(baselineName(story.id)))
          .map((story) => `${story.id} has no baseline ${baselineName(story.id)}; ${ADVICE}`),
        [],
      );
    });
  }

  test('belong each to a story', () => {
    const named = new Set(stories.map((story) => baselineName(story.id)));

    assert.deepEqual(
      baselines.filter((baseline) => !named.has(baseline)),
      [],
    );
  });

  test('of the panel points were rendered from their stories as they are', () => {
    const path = join(ROOT, RENDERED_FROM);

    assert.deepEqual(
      staleSources(existsSync(path) ? readFileSync(path, 'utf8') : null, renderedFrom(ROOT)).map(
        (problem) => `${problem}; ${ADVICE}`,
      ),
      [],
    );
  });

  test('are named and held as the story tests and the baselines script name and write them', () => {
    assert.equal(
      baselineName('panel-points--login-notice-v-1'),
      'panel-points-login-notice-v-1.png',
    );
    assert.deepEqual(
      storyIds(
        'x.stories.tsx',
        "const meta = { title: 'Panel points' };\nexport default meta;\nexport const LoginNoticeV1 = {};\n",
      ),
      ['panel-points--login-notice-v-1'],
    );

    const digests = { [PANEL_POINT_SOURCES[0] ?? '']: 'a', 'js/panel/stories/other.ts': 'b' };

    assert.deepEqual(staleSources(renderedFromRecord(digests), digests), []);
    assert.deepEqual(staleSources(null, digests), [`${RENDERED_FROM} is missing`]);
    assert.deepEqual(
      staleSources(renderedFromRecord({ ...digests, 'js/panel/stories/other.ts': 'c' }), digests),
      ['js/panel/stories/other.ts changed since the baselines were rendered'],
    );
    assert.deepEqual(staleSources(renderedFromRecord({ ...digests, 'gone.ts': 'd' }), digests), [
      `${RENDERED_FROM} names gone.ts, which the baselines are not rendered from`,
    ]);
  });
});
