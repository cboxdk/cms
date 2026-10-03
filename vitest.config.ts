// The JS test suites of the repository, on Vitest, in two projects (decision D11 of the panel
// extension architecture, 2 October 2026):
//
// - unit: the JS unit suite of gate 5 (`npm run test:js`), every js/<workspace>/tests/**/*.test.js,
//   *.test.ts and *.test.tsx, in Node; a test file that renders React components, such as the
//   keyboard tests of the component kit, asks for jsdom with a `@vitest-environment jsdom` comment.
// - storybook: the story tests of gate 7 (`npm run storybook:stories`), one test per story of the
//   component kit, in Chromium through Playwright: the story renders, its play function runs, axe
//   finds no violation, and a screenshot of the page matches the baseline committed in
//   js/ui-kit/visual-baselines/<story id>.png. The baselines are rendered in the php-baseimages dev
//   image (decision D10), whose Chromium and fonts are the same on every machine, so the run belongs
//   there: `composer image:run -- npm run storybook:test`, or `npm run storybook:baselines` to write
//   them after an intended change.

import { join } from 'node:path';

import { storybookTest } from '@storybook/addon-vitest/vitest-plugin';
import { playwright } from '@vitest/browser-playwright';
import { defineConfig } from 'vitest/config';

const ROOT = import.meta.dirname;

/** Where the baselines of the story tests live, one PNG per story, named by its id. */
export const VISUAL_BASELINES = 'js/ui-kit/visual-baselines';

/** Where a failed comparison writes what it saw and the difference; git ignores it. */
export const VISUAL_DIFFERENCES = '.cache/storybook/differences';

export default defineConfig({
  // Vitest keeps its results in the checkout, below .cache/ with the other gate tools' caches.
  cacheDir: join(ROOT, '.cache/vitest'),
  test: {
    projects: [
      {
        test: {
          name: 'unit',
          root: ROOT,
          include: ['js/*/tests/**/*.test.{js,ts,tsx}'],
          environment: 'node',
        },
      },
      {
        plugins: [storybookTest({ configDir: join(ROOT, 'js/ui-kit/.storybook') })],
        test: {
          name: 'storybook',
          dir: ROOT,
          root: ROOT,
          setupFiles: [join(ROOT, 'js/ui-kit/.storybook/vitest.setup.ts')],
          attachmentsDir: join(ROOT, VISUAL_DIFFERENCES),
          browser: {
            enabled: true,
            headless: true,
            provider: playwright(),
            instances: [{ browser: 'chromium' }],
            viewport: { width: 1200, height: 900 },
            // The page every story starts on (js/ui-kit/.storybook/vitest.setup.ts): reduced
            // motion, so nothing moves while its screenshot is taken, forced colours for the story
            // in forced colours, and the pointer in the corner, so no control is hovered because of
            // where the story before left it.
            commands: {
              prepareStoryPage: async (context, forcedColors: 'active' | 'none') => {
                await context.page.emulateMedia({ forcedColors, reducedMotion: 'reduce' });
                await context.page.mouse.move(0, 0);
              },
            },
            screenshotFailures: false,
            expect: {
              toMatchScreenshot: {
                comparatorName: 'pixelmatch',
                comparatorOptions: { threshold: 0.1, allowedMismatchedPixelRatio: 0 },
                resolveScreenshotPath: ({ arg, ext }) => join(ROOT, VISUAL_BASELINES, arg + ext),
                resolveDiffPath: ({ arg, ext }) => join(ROOT, VISUAL_DIFFERENCES, arg + ext),
              },
            },
          },
        },
      },
    ],
  },
});
