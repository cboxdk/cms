// The story tests' own steps around each story (GUARDRAILS 8, decision D10 of the panel extension
// architecture).
//
// Before a story renders, the page is put in forced colours for a story named FORCED_COLOURS, the
// story every component exports as ForcedColors (stories/csf.ts), and out of them for every other
// story; every story is shown with reduced motion, which stops the kit's animations and makes its
// transitions instant (--cms-duration-*), so a screenshot never catches one halfway; and the
// pointer is moved to the corner of the page, so a control is hovered only when the story itself
// moves the pointer over it. The command prepareStoryPage of vitest.config.ts asks Playwright for
// all three. A text field's caret does not blink in the story tests (STEADY_CARET).
//
// After a story has rendered, its play function has run and axe has found nothing, and the kit's
// two typefaces have loaded, the page is compared with the story's baseline, js/ui-kit/visual-baselines/<story id>.png (vitest.config.ts
// resolves the path). A story without a baseline fails, as does any changed pixel beyond
// pixelmatch's threshold for antialiasing; `npm run storybook:baselines` in the dev image writes the
// baselines anew after an intended change, and the new images are reviewed in the diff like any
// other change.

import { afterEach, beforeEach, expect } from 'vitest';
import { commands, page } from 'vitest/browser';

import { FORCED_COLOURS } from '../stories/csf';

declare module 'vitest/browser' {
  interface BrowserCommands {
    /**
     * Emulates prefers-reduced-motion: reduce and the media feature forced-colors on the page, and
     * moves the pointer to its corner.
     */
    prepareStoryPage: (forcedColors: 'active' | 'none') => Promise<void>;
  }
}

/**
 * A text field that has focus shows its caret in the screenshot, and the caret blinks, so a
 * screenshot would catch it at random: the story tests stop the blinking, which leaves the caret
 * shown or hidden the same way every time. Forced colours draw the caret in a system colour, so
 * hiding it does not work there.
 */
const STEADY_CARET = '*, *::before, *::after { caret-animation: manual !important; }';

beforeEach(async (context) => {
  if (document.getElementById('story-tests-steady-caret') === null) {
    const style = document.createElement('style');
    style.id = 'story-tests-steady-caret';
    style.textContent = STEADY_CARET;
    document.head.append(style);
  }

  await commands.prepareStoryPage(context.task.name === FORCED_COLOURS ? 'active' : 'none');
});

afterEach(async (context) => {
  const storyId: unknown = Reflect.get(context.task.meta, 'storyId');

  if (typeof storyId !== 'string' || context.task.result?.state === 'fail') {
    return;
  }

  // The kit's typefaces are web fonts (base.css), which load while the story renders; the
  // screenshot waits for both faces, so it never catches a fallback face.
  await Promise.all([
    document.fonts.load("1em 'Plus Jakarta Sans'"),
    document.fonts.load("1em 'JetBrains Mono'"),
  ]);
  await document.fonts.ready;
  await expect.element(page.elementLocator(document.body)).toMatchScreenshot(storyId);
});
