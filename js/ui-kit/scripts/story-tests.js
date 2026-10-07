// Runs the story tests of gate 7: the storybook project of vitest.config.ts, one test per story of
// the component kit in Chromium, with its play function, axe and the comparison of a screenshot with
// the story's baseline in js/ui-kit/visual-baselines. A skipped story fails the run, as a skipped
// test fails every gate (js/tooling/vitest-no-skipped.js).
//
// The baselines are rendered in the php-baseimages dev image (decision D10 of the panel extension
// architecture, 2 October 2026), whose Chromium and fonts are the same on every machine, so the
// tests run there and nowhere else: outside the image (CBOX_IMAGE_TIER is not dev) this exits 2 and
// says how to run it, `composer image:run -- npm run storybook:test`. CI runs it in the image.
//
// `npm run storybook:stories` compares; `npm run storybook:baselines` (--update) removes the
// baselines and writes one for every story anew, after an intended change of how the kit looks,
// so a baseline of a story that no longer exists does not linger, and, when every story passed,
// records the SHA-256 of the files the panel points' stories are rendered from
// (rendered-from.js), which gate 5 holds the baselines to. The new images are reviewed in the
// diff like any other change.

import { spawnSync } from 'node:child_process';
import { rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import process from 'node:process';

import { RENDERED_FROM, renderedFrom, renderedFromRecord } from './rendered-from.js';

/** The variable the php-baseimages images set to their tier, and the tier of the dev image. */
export const IMAGE_TIER_VARIABLE = 'CBOX_IMAGE_TIER';
export const DEV_TIER = 'dev';

const root = join(import.meta.dirname, '../../..');
const update = process.argv.includes('--update');

if (process.env[IMAGE_TIER_VARIABLE] !== DEV_TIER) {
  process.stderr.write(
    `The story tests compare screenshots with baselines rendered in the php-baseimages dev image, so they run in it: composer image:run -- npm run ${update ? 'storybook:baselines' : 'storybook:test'}\n`,
  );
  process.exit(2);
}

if (update) {
  rmSync(join(root, 'js/ui-kit/visual-baselines'), { recursive: true, force: true });
}

const vitest = join(
  dirname(createRequire(import.meta.url).resolve('vitest/package.json')),
  'vitest.mjs',
);

const run = spawnSync(
  process.execPath,
  [
    vitest,
    'run',
    '--project=storybook',
    '--reporter=default',
    '--reporter=./js/tooling/vitest-no-skipped.js',
    ...(update ? ['--update'] : []),
  ],
  {
    cwd: root,
    stdio: 'inherit',
    env: { ...process.env, STORYBOOK_DISABLE_TELEMETRY: '1' },
  },
);

if (update && run.status === 0) {
  writeFileSync(join(root, RENDERED_FROM), renderedFromRecord(renderedFrom(root)));
}

process.exit(run.status ?? 1);
