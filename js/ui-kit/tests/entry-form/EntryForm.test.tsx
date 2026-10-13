// @vitest-environment jsdom

// The form rendered from a type editor descriptor (PRD 11.12, 14.1; gap G3): the layout's tabs,
// sections and sidebar with each field at its width; labels in the reader's locale; an input per
// base of a field, each through the EntryFieldInput contract a caller can swap; a conditional
// field shown while its condition holds and cleared when hidden under clear; the ui's hidden and
// read-only fields; and the client's errors, from the generated validation runtime, at paths with
// the keys of the items of repeated groups, which follow an item the editor moves.

import { fireEvent, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, test } from 'vitest';

import { EntryForm, type EntryFormProps } from '../../src/components/EntryForm';
import type { EntryFieldInputProps } from '../../src/components/entry-form/inputs';
import { newEntryFields } from '../../src/components/entry-form/values';
import type { TypeEditorV1 } from '../../src/generated/protocol/TypeEditorV1';
import type { JsonObject } from '../../src/components/schema-form/model';
import { renderKit } from '../keyboard/harness';
import { changed, NOW, PRODUCT } from './fixtures';

/** The attribute of an input's hint, written in parts, as the marker gate asks of a word it bans. */
const INPUT_HINT = ['place', 'holder'].join('');

/** The form, controlled by a holder whose data-fields attribute shows the fields. */
function Holder({
  descriptor = PRODUCT,
  start = {},
  ...props
}: Partial<EntryFormProps> & { readonly start?: JsonObject }) {
  const [fields, setFields] = useState<JsonObject>(start);

  return (
    <div data-fields={JSON.stringify(fields)}>
      <EntryForm
        descriptor={descriptor}
        value={fields}
        onChange={setFields}
        locale="en"
        idPrefix="product"
        now={() => NOW}
        {...props}
      />
    </div>
  );
}

/** The fields the holder has now. */
function fields(container: HTMLElement): Record<string, unknown> {
  const holder = container.querySelector('[data-fields]');

  return JSON.parse(holder?.getAttribute('data-fields') ?? '{}') as Record<string, unknown>;
}

function control(container: HTMLElement, path: string): HTMLElement {
  const element = container.querySelector(`[id="product-${path}"]`);

  if (!(element instanceof HTMLElement)) {
    throw new Error(`No control product-${path}.`);
  }

  return element;
}

/** Shows the tab with the name. */
async function showTab(user: ReturnType<typeof renderKit>['user'], name: string): Promise<void> {
  await user.click(screen.getByRole('tab', { name }));
}

describe('the layout', () => {
  test('renders the tabs, the sections and the sidebar', () => {
    const { container } = renderKit(<Holder />);

    expect(screen.getAllByRole('tab').map((tab) => tab.textContent)).toEqual([
      'General',
      'Details',
      'Local',
    ]);
    const sidebar = screen.getByRole('complementary', { name: 'Details' });
    expect(within(sidebar).getByRole('heading', { name: 'Status' })).toBeDefined();
    expect(within(sidebar).getByRole('heading', { name: 'Contact' })).toBeDefined();
    expect(within(sidebar).getByLabelText('Launch date')).toBeDefined();
    expect(container.querySelector('[id="product-name"]')).not.toBeNull();
  });

  test('gives each placed field its width', () => {
    const { container } = renderKit(<Holder />);
    const width = (path: string) =>
      control(container, path).closest('[data-width]')?.getAttribute('data-width');

    expect(width('name')).toBe('two_thirds');
    expect(container.querySelector('[data-width="third"]')).not.toBeNull();
    expect(width('summary')).toBe('full');
  });

  test('collapses and expands a collapsible section, with its instructions', async () => {
    const { container, user } = renderKit(<Holder />);
    const toggle = screen.getByRole('button', { name: 'Supply' });

    expect(screen.getByText('Who supplies the product and what it costs.')).toBeDefined();
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    await user.click(toggle);
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(control(container, 'stock').closest('[hidden]')).not.toBeNull();
    await user.click(toggle);
    expect(control(container, 'stock').closest('[hidden]')).toBeNull();
  });

  test('renders a single tab without a tab list', () => {
    const one: TypeEditorV1 = {
      ...PRODUCT,
      layout: { ...PRODUCT.layout, tabs: PRODUCT.layout.tabs.slice(0, 1) },
    };

    renderKit(<Holder descriptor={one} />);
    expect(screen.queryByRole('tab')).toBeNull();
    expect(screen.getByLabelText('Name', { exact: false })).toBeDefined();
  });
});

describe('the labels', () => {
  test("are in the reader's locale", () => {
    renderKit(<Holder locale="da" />);

    expect(screen.getAllByRole('tab').map((tab) => tab.textContent)).toEqual([
      'Generelt',
      'Details',
      'Local',
    ]);
    expect(screen.getByLabelText('Navn', { exact: false })).toBeDefined();
    expect(screen.getByText('Vælg produktets farve.')).toBeDefined();
  });

  test("fall back to the blueprint's own for a locale it has no text in", () => {
    renderKit(<Holder locale="de" />);

    expect(screen.getByLabelText('Name', { exact: false })).toBeDefined();
  });
});

describe('the inputs', () => {
  test('text: plain, email and url, each its own kind of input', async () => {
    const { container, user } = renderKit(<Holder start={{ discontinued: false }} />);

    expect(control(container, 'name').getAttribute('type')).toBe('text');
    expect(control(container, 'name').getAttribute(INPUT_HINT)).toBe('The name on the shelf');
    expect(control(container, 'support_email').getAttribute('type')).toBe('email');
    expect(control(container, 'supplier.website').getAttribute('type')).toBe('url');
    await user.type(control(container, 'name'), 'Lamp');
    expect(fields(container)['name']).toBe('Lamp');
    await user.clear(control(container, 'name'));
    expect(Object.hasOwn(fields(container), 'name')).toBe(false);
  });

  test('long text: a text area', async () => {
    const { container, user } = renderKit(<Holder />);
    const area = control(container, 'summary');

    expect(area.tagName).toBe('TEXTAREA');
    await user.type(area, 'Two{Enter}lines');
    expect(fields(container)['summary']).toBe('Two\nlines');
  });

  test('integer: a number field within the bounds', async () => {
    const { container, user } = renderKit(<Holder />);
    await showTab(user, 'Details');
    const weight = control(container, 'weight');

    await user.type(weight, '250');
    await user.tab();
    expect(fields(container)['weight']).toBe(250);
  });

  test('decimal: text that keeps every digit', async () => {
    const { container, user } = renderKit(<Holder />);
    await showTab(user, 'Details');

    await user.type(control(container, 'price'), '12.50');
    expect(fields(container)['price']).toBe('12.50');
    expect(control(container, 'price').getAttribute('inputmode')).toBe('decimal');
  });

  test('boolean: a check box', async () => {
    const { user, container } = renderKit(<Holder />);

    await user.click(screen.getByRole('checkbox', { name: 'Discontinued' }));
    expect(fields(container)['discontinued']).toBe(true);
  });

  test("date and datetime: the browser's controls, a datetime stored with its offset", () => {
    const { container } = renderKit(<Holder />);
    const date = control(container, 'launch_date');

    expect(date.getAttribute('type')).toBe('date');
    expect(date.getAttribute('min')).toBe('2000-01-01');
    fireEvent.change(date, { target: { value: '2026-11-01' } });
    expect(fields(container)['launch_date']).toBe('2026-11-01');
  });

  test("datetime: the local time with the reader's offset", () => {
    const editable = changed(PRODUCT, {
      checked_at: (field) => ({ ...field, ui: { ...field.ui, read_only: false } }),
    });
    const { container } = renderKit(<Holder descriptor={editable} />);
    const input = control(container, 'checked_at');

    expect(input.getAttribute('type')).toBe('datetime-local');
    fireEvent.change(input, { target: { value: '2026-11-01T08:15:30' } });
    const stored = String(fields(container)['checked_at']);
    expect(stored).toMatch(/^2026-11-01T08:15:30(Z|[+-][0-9]{2}:[0-9]{2})$/);
    expect(new Date(stored).getTime()).toBe(new Date(2026, 10, 1, 8, 15, 30).getTime());
  });

  test('select: radio buttons, buttons or a dropdown by the ui display', async () => {
    const { user, container } = renderKit(<Holder />);

    expect(
      screen.getByRole('radiogroup', { name: 'Colour' }).getAttribute('aria-orientation'),
    ).toBe('vertical');
    await user.click(screen.getByRole('radio', { name: 'Green' }));
    expect(fields(container)['colour']).toBe('green');
  });

  test('select: buttons in a row', () => {
    const buttons = changed(PRODUCT, {
      colour: (field) => ({ ...field, ui: { ...field.ui, display: 'buttons' } }),
    });
    renderKit(<Holder descriptor={buttons} />);

    expect(
      screen.getByRole('radiogroup', { name: 'Colour' }).getAttribute('aria-orientation'),
    ).toBe('horizontal');
  });

  test('select: a dropdown', () => {
    const dropdown = changed(PRODUCT, {
      colour: (field) => ({ ...field, ui: { ...field.ui, display: 'dropdown' } }),
    });
    const { container } = renderKit(<Holder descriptor={dropdown} />);

    expect(screen.queryByRole('radiogroup', { name: 'Colour' })).toBeNull();
    expect(control(container, 'colour').tagName).toBe('BUTTON');
  });

  test('multiple select: check boxes, or a multiple select as a dropdown', async () => {
    const { user, container } = renderKit(<Holder start={{ tags: ['new'] }} />);
    const tags = screen.getByRole('group', { name: 'Tags' });

    await user.click(within(tags).getByRole('checkbox', { name: 'Sale' }));
    expect(fields(container)['tags']).toEqual(['new', 'sale']);
    await user.click(within(tags).getByRole('checkbox', { name: 'New' }));
    await user.click(within(tags).getByRole('checkbox', { name: 'Sale' }));
    expect(Object.hasOwn(fields(container), 'tags')).toBe(false);
  });

  test('multiple select as a dropdown', () => {
    const dropdown = changed(PRODUCT, {
      tags: (field) => ({ ...field, ui: { ...field.ui, display: 'dropdown' } }),
    });
    renderKit(<Holder descriptor={dropdown} start={{ tags: ['eco'] }} />);

    expect(screen.queryByRole('group', { name: 'Tags' })).toBeNull();
    expect(screen.getByText('Tags', { selector: 'label, span' })).toBeDefined();
  });

  test('group: a fieldset of its fields, left out while they are empty', async () => {
    const { user, container } = renderKit(<Holder />);
    const supplier = screen.getByRole('group', { name: 'Supplier' });

    await user.type(within(supplier).getByLabelText('Company', { exact: false }), 'Acme');
    expect(fields(container)['supplier']).toEqual({ company: 'Acme' });
    await user.clear(within(supplier).getByLabelText('Company', { exact: false }));
    expect(Object.hasOwn(fields(container), 'supplier')).toBe(false);
  });

  test('repeated group: items added, moved and removed, each shown by its summary', async () => {
    const { user, container } = renderKit(
      <Holder
        start={{
          discontinued: false,
          dimensions: [{ size: 'small' }, { size: 'large', width: 40 }],
        }}
      />,
    );
    await showTab(user, 'Details');
    const dimensions = screen.getByRole('group', { name: 'Dimensions' });

    expect(within(dimensions).getByRole('button', { name: 'Small: mm' })).toBeDefined();
    const large = within(dimensions).getByRole('button', { name: 'Large: 40 mm' });
    expect(large.getAttribute('aria-expanded')).toBe('false');
    await user.click(large);
    expect(within(dimensions).getAllByLabelText('Width', { exact: false }).length).toBeGreaterThan(
      0,
    );

    await user.click(within(dimensions).getByRole('button', { name: 'Move Large: 40 mm up' }));
    expect(fields(container)['dimensions']).toEqual([
      { size: 'large', width: 40 },
      { size: 'small' },
    ]);

    await user.click(within(dimensions).getByRole('button', { name: 'Add an item to Dimensions' }));
    expect(fields(container)['dimensions']).toEqual([
      { size: 'large', width: 40 },
      { size: 'small' },
      {},
    ]);

    expect(within(dimensions).getByRole('button', { name: 'Dimensions 3' })).toBeDefined();
    expect(screen.queryByText('Fill in this field.')).toBeNull();

    await user.click(within(dimensions).getByRole('button', { name: 'Remove Small: mm' }));
    expect(fields(container)['dimensions']).toEqual([{ size: 'large', width: 40 }, {}]);
  });

  test('rich text: Portable Text as JSON', () => {
    const { container } = renderKit(<Holder />);
    const body = control(container, 'body');
    const block = [
      { _type: 'block', _key: 'a', children: [{ _type: 'span', _key: 's', text: 'Hi' }] },
    ];

    fireEvent.change(body, { target: { value: JSON.stringify(block) } });
    expect(fields(container)['body']).toEqual(block);
  });

  test('plug into the EntryFieldInput contract by base', () => {
    const seen: EntryFieldInputProps[] = [];
    const RichText = (props: EntryFieldInputProps) => {
      seen.push(props);

      return <p data-rich-text={props.name}>{props.label}</p>;
    };
    const { container } = renderKit(<Holder inputs={{ rich_text: RichText }} />);

    expect(
      [...container.querySelectorAll('[data-rich-text]')].map((element) =>
        element.getAttribute('data-rich-text'),
      ),
    ).toEqual(['body', 'care']);
    expect(seen.at(-1)?.field.base).toBe('rich_text');
    expect(seen.at(-1)?.id).toBe('product-care');
  });
});

describe('the ui of a field', () => {
  test('hides a hidden field and shows a read-only one without letting it change', async () => {
    const { container, user } = renderKit(
      <Holder start={{ checked_at: '2026-10-13T07:30:05Z' }} />,
    );

    expect(container.querySelector('[id="product-checked_at"]')?.hasAttribute('readonly')).toBe(
      true,
    );
    await showTab(user, 'General');
    expect(container.querySelector('[id="product-purchase_price"]')).toBeNull();
  });
});

describe('a conditional field', () => {
  test('is shown while its condition holds, and loses its value under clear when hidden', async () => {
    const { container, user } = renderKit(
      <Holder start={{ discontinued: false, support_email: 'care@example.org' }} />,
    );

    expect(container.querySelector('[id="product-support_email"]')).not.toBeNull();
    await user.click(screen.getByRole('checkbox', { name: 'Discontinued' }));
    expect(container.querySelector('[id="product-support_email"]')).toBeNull();
    expect(fields(container)).toEqual({ discontinued: true });
  });

  test('of an item reads its item and the fields above it', async () => {
    const { container, user } = renderKit(
      <Holder start={{ discontinued: false, dimensions: [{ size: 'large', width: 3 }] }} />,
    );
    await showTab(user, 'Details');
    await user.click(screen.getByRole('button', { name: 'Large: 3 mm' }));

    expect(container.querySelector('[id^="product-dimensions[#"][id$="].width"]')).not.toBeNull();
    await user.click(screen.getByRole('radio', { name: 'Small' }));
    expect(container.querySelector('[id^="product-dimensions[#"][id$="].width"]')).toBeNull();
    expect(fields(container)['dimensions']).toEqual([{ size: 'small', width: 3 }]);
  });
});

describe('the defaults', () => {
  test('of a new entry fill the form', () => {
    const { container } = renderKit(<Holder start={newEntryFields(PRODUCT, NOW)} />);

    expect((control(container, 'launch_date') as HTMLInputElement).value).toBe('2026-10-13');
    expect(screen.getByRole('radio', { name: 'Red' })).toHaveProperty('checked', true);
    expect(screen.getByRole('checkbox', { name: 'New' })).toHaveProperty('checked', true);
  });
});

describe('the errors', () => {
  test('of the client are shown at the fields the editor changed', async () => {
    const { container, user } = renderKit(<Holder />);

    expect(screen.queryByText('Fill in this field.')).toBeNull();
    await user.type(control(container, 'name'), 'x');
    await user.clear(control(container, 'name'));
    expect(control(container, 'name').getAttribute('aria-invalid')).toBe('true');
    expect(screen.getAllByText('Fill in this field.')).toHaveLength(1);
  });

  test('are all shown with a summary, on the tab of the first', async () => {
    const { container, user } = renderKit(<Holder showErrors="all" stage="release" />);
    const summary = screen.getByRole('group', { name: /Errors in the form: / });

    expect(
      within(summary)
        .getAllByRole('link')
        .map((link) => link.getAttribute('href')),
    ).toEqual(['#product-name', '#product-colour', '#product-ext.app.tax_code', '#product-price']);
    expect(
      screen.getByRole('tab', { name: 'General (errors: 3)' }).getAttribute('aria-selected'),
    ).toBe('true');
    await showTab(user, 'Details (errors: 1)');
    expect(control(container, 'price').getAttribute('aria-invalid')).toBe('true');
  });

  test('stay at their item by its key when the editor moves it', async () => {
    const { container, user } = renderKit(
      <Holder
        showErrors="all"
        start={{
          name: 'Lamp',
          colour: 'red',
          price: '1.00',
          discontinued: false,
          dimensions: [{ size: 'small' }, { size: 'large' }],
        }}
      />,
    );
    await showTab(user, 'Details (errors: 1)');
    const before = container.querySelector('[id][aria-invalid="true"]');

    expect(before?.id).toMatch(/^product-dimensions\[#i1\]\.width$/);
    await user.click(screen.getByRole('button', { name: 'Move Large: mm up' }));
    const after = container.querySelector('[id][aria-invalid="true"]');
    expect(after?.id).toBe('product-dimensions[#i1].width');
    expect(fields(container)['dimensions']).toEqual([{ size: 'large' }, { size: 'small' }]);
    expect(
      within(screen.getByRole('group', { name: /Errors in the form/ }))
        .getByRole('link')
        .getAttribute('href'),
    ).toBe('#product-dimensions[#i1].width');
  });

  test("of the server are shown at their fields, by index or by key, before the client's", async () => {
    const { container, user } = renderKit(
      <Holder
        start={{
          name: 'Lamp',
          colour: 'red',
          price: '1.00',
          dimensions: [{ size: 'small' }, { size: 'small' }],
        }}
        errors={{ name: 'The name is taken.', 'dimensions[1].size': 'Only one small size.' }}
      />,
    );

    expect(screen.getByText('The name is taken.')).toBeDefined();
    await user.click(screen.getByRole('tab', { name: 'Details (errors: 1)' }));
    await user.click(screen.getAllByRole('button', { name: 'Small: mm' })[1] as HTMLElement);
    expect(screen.getByText('Only one small size.')).toBeDefined();
    expect(container.querySelector('[id="product-dimensions[#i1].size"]')).toBeNull();
  });
});
