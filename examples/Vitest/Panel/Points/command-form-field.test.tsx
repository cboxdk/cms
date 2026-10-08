// @vitest-environment jsdom

// A replacement contribution to command.form.field@1, the input of one field of the generic
// command form: the fixture addon's fixtureaddon.slug-input takes the place of the default input of
// every member a command binds to the addon's own value class ArticleSlug, renders something with
// exactly the point's props, keeps the default input's id and name, so the error summary still
// links to it and the value is submitted under the member's path, and hands the form a well-formed
// slug through onChange when the field loses focus. The props are the sample props of the point's
// JSON Schema with the member's value, and onChange, which the host adds in the browser.

import type { FieldInputProps } from '@cboxdk/cms-panel/experimental';
import { expectNoA11yViolations, expectReplacementContract } from '@cboxdk/cms-panel/testing';
import { act } from 'react';
import { expect, test } from 'vitest';

import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

test('fixtureaddon.slug-input keeps the replacement contract and shapes the slug', async () => {
  const changes: (string | null)[] = [];
  const props: FieldInputProps = {
    command: 'fixtureaddon.slug.set',
    version: 1,
    path: 'slug',
    id: 'command-slug',
    label: 'Slug',
    description: 'The article s slug.',
    schema: { type: 'string' },
    value: 'A Quiet Week',
    errors: [],
    read_only: false,
    locale: 'en',
    presence: 'required',
    onChange: (value) => {
      changes.push(value);
    },
  };

  const rendered = await expectReplacementContract({
    addon,
    id: 'fixtureaddon.slug-input',
    props,
    host: {
      namespace: 'fixtureaddon',
      texts: {
        'fixtureaddon.slug_input.description': 'Lowercase letters and digits, joined by hyphens.',
        'fixtureaddon.slug_input.preview': 'The article gets the slug {slug}.',
        'fixtureaddon.slug_input.empty': 'No slug yet.',
      },
    },
  });

  const input = rendered.container.querySelector('input');

  expect(input?.id).toBe('command-slug');
  expect(input?.name).toBe('slug');
  expect(rendered.container.textContent).toContain('The article gets the slug a-quiet-week.');
  await expectNoA11yViolations(rendered.container);

  await act(async () => {
    input?.dispatchEvent(new FocusEvent('focusout', { bubbles: true }));
    await Promise.resolve();
  });

  expect(changes).toEqual(['a-quiet-week']);
  await rendered.unmount();
});
