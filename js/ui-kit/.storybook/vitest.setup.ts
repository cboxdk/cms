// The visual regression of the kit's story tests (GUARDRAILS 8, decision D10). After a story has
// rendered, its play function has run and axe has found nothing, the page is compared with the
// story's baseline, js/ui-kit/visual-baselines/<story id>.png (vitest.config.ts resolves the path).
// A story without a baseline fails, as does any changed pixel beyond pixelmatch's threshold for
// antialiasing; `npm run storybook:baselines` in the dev image writes the baselines anew after an
// intended change, and the new images are reviewed in the diff like any other change.

import { afterEach, expect } from 'vitest';
import { page } from 'vitest/browser';

afterEach(async (context) => {
  const storyId: unknown = Reflect.get(context.task.meta, 'storyId');

  if (typeof storyId !== 'string' || context.task.result?.state === 'fail') {
    return;
  }

  await expect.element(page.elementLocator(document.body)).toMatchScreenshot(storyId);
});
