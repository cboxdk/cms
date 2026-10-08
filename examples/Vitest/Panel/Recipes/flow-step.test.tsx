// @vitest-environment jsdom

// A flow step before the submit, the recipe's real run: the fixture addon's
// fixtureaddon.slug-review is a flow step contribution to command.form.steps@1 on the form of
// entry.create, its component SlugReview, registered in the addon's panel.ts. It shows the slug the
// article gets, patches it into the draft at the one path its manifest declares, the addon's own
// field, when the viewer takes it, and goes on to the core's confirmation; stopping cancels the
// flow in the addon's name. The draft is of the command's generated type, as cms:panel:types writes
// it.

import { expectFlowStepContract, expectNoA11yViolations } from '@cboxdk/cms-panel/testing';
import { act } from 'react';
import { expect, test } from 'vitest';

import type { EntryCreateV1 } from '../../../../workbench/addons/fixtureaddon/resources/panel/generated/contributions';
import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';
import {
  ARTICLE_TYPE,
  SLUG_PATH,
} from '../../../../workbench/addons/fixtureaddon/resources/panel/src/slug';

const draft: EntryCreateV1 = {
  entry: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a11',
  fields: { fixture_title: 'A quiet week' },
  home: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a12',
  type: ARTICLE_TYPE,
};

const texts = {
  'fixtureaddon.slug_review.title': 'The slug',
  'fixtureaddon.slug_review.slug': 'The article gets the slug {slug}.',
  'fixtureaddon.slug_review.use': 'Use it',
  'fixtureaddon.slug_review.stop': 'Stop',
};

async function press(container: HTMLElement, text: string): Promise<void> {
  const button = [...container.querySelectorAll('button')].find(
    (candidate) => candidate.textContent === text,
  );

  await act(async () => {
    button?.click();
    await Promise.resolve();
  });
}

test('fixtureaddon.slug-review patches the slug into its declared path and goes on', async () => {
  const rendered = await expectFlowStepContract({
    addon,
    id: 'fixtureaddon.slug-review',
    draft,
    patches: [SLUG_PATH],
    host: { namespace: 'fixtureaddon', texts },
  });

  expect(rendered.container.textContent).toContain('The article gets the slug a-quiet-week.');
  await expectNoA11yViolations(rendered.container);

  await press(rendered.container, 'Use it');

  expect(rendered.step.patches).toEqual([{ path: SLUG_PATH, value: 'a-quiet-week' }]);
  expect(rendered.step.refusedPatches).toEqual([]);
  expect(rendered.step.next).toBe(1);
  await rendered.unmount();
});

test('fixtureaddon.slug-review cancels the flow in the addon s name when the viewer stops', async () => {
  const rendered = await expectFlowStepContract({
    addon,
    id: 'fixtureaddon.slug-review',
    draft,
    patches: [SLUG_PATH],
    host: { namespace: 'fixtureaddon', texts },
  });

  await press(rendered.container, 'Stop');

  expect(rendered.step.cancelled).toBe('fixtureaddon.slug_review.cancelled');
  expect(rendered.step.next).toBe(0);
  await rendered.unmount();
});
