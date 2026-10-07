// What the baselines of the panel's points are rendered from (GUARDRAILS 8, decision D10 of the
// panel extension architecture). The section "Panel points" of the Storybook shows the data
// cms:panel:stories generates from panel.php, so a change to a point or to any contribution an
// addon or the core compiles for one changes how its story looks, though no file of the kit or
// the panel's stories was touched by hand. The story tests that see it are gate 7, which runs only
// in CI; gate 5's js/ui-kit/tests/story-screenshots.test.js holds the baselines to the files they
// were rendered from, through the SHA-256 of each that `npm run storybook:baselines` records in
// RENDERED_FROM when it writes the baselines.

import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/** The record of what the baselines were rendered from, relative to the root. */
export const RENDERED_FROM = 'js/ui-kit/visual-baselines/rendered-from.json';

/**
 * The files the stories of the panel's points are rendered from, relative to the root: the two
 * modules cms:panel:stories generates and the component that renders them.
 */
export const PANEL_POINT_SOURCES = [
  'js/panel/stories/PanelPointStory.tsx',
  'js/panel/stories/generated/PanelPoints.stories.tsx',
  'js/panel/stories/generated/points.ts',
];

/**
 * The SHA-256 of each source in a tree, by its path relative to the root.
 *
 * @param {string} root
 * @returns {Record<string, string>}
 */
export function renderedFrom(root) {
  return Object.fromEntries(
    PANEL_POINT_SOURCES.map((path) => [
      path,
      createHash('sha256')
        .update(readFileSync(join(root, path)))
        .digest('hex'),
    ]),
  );
}

/**
 * The record as `npm run storybook:baselines` writes it: JSON as Prettier prints it.
 *
 * @param {Record<string, string>} digests
 * @returns {string}
 */
export function renderedFromRecord(digests) {
  return `${JSON.stringify(digests, null, 2)}\n`;
}
