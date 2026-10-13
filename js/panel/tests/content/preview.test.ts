// The editor's Preview (PRD 5.4, 8.9, 13.4): the record entry.preview answered with, as the
// document a person reads, labelled and written in the locale chosen, with every field the record
// holds, a group by its own fields, a list item by item, rich text by its paragraphs and an
// extender's field from below ext; a field the record leaves out is not shown; and the revisions
// and locales the preview offers.

import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, test } from 'vitest';

import { previewDocument, previewLocales, previewRevisions } from '../../src/content/preview';
import { validateTypeEditorV1, type TypeEditorV1 } from '../../src/generated/protocol/TypeEditorV1';

/** The comprehensive example's descriptor, shop:product, with every core field type. */
function product(): TypeEditorV1 {
  const checked = validateTypeEditorV1(
    JSON.parse(
      readFileSync(
        join(
          import.meta.dirname,
          '../../../../packages/generators/tests/Descriptor/Fixtures/Comprehensive/editor.json',
        ),
        'utf8',
      ),
    ) as unknown,
  );

  if (!checked.valid) {
    throw new Error('The comprehensive example has no editor descriptor.');
  }

  return checked.value;
}

const PRODUCT = product();

/** A record as the product's codec writes it at the public classification access. */
const RECORD = {
  cms_id: '0192a0c0-0000-7000-8000-0000000009e1',
  name: 'Oak table',
  weight: 12000,
  discontinued: false,
  launch_date: '2026-03-09',
  colour: 'green',
  tags: ['sale', 'eco'],
  body: [
    {
      _key: 'a',
      _type: 'block',
      children: [
        { _key: 'a1', _type: 'span', text: 'Solid ' },
        { _key: 'a2', _type: 'span', text: 'oak.' },
      ],
    },
    {
      _key: 'b',
      _type: 'block',
      children: [{ _key: 'b1', _type: 'span', text: 'Oiled by hand.' }],
    },
  ],
  dimensions: [{ size: 'large', width: 180 }],
  ext: { app: { name: 'Table' } },
};

describe('the document of a record', () => {
  test('gives the title and every field the record holds, in the descriptor order and the locale', () => {
    const document = previewDocument(PRODUCT, RECORD, 'en');

    expect(document.title).toBe('Oak table');
    expect(document.items.map((item) => item.id)).toEqual([
      'weight',
      'discontinued',
      'launch_date',
      'colour',
      'tags',
      'body',
      'dimensions',
      'ext.app.name',
    ]);
    expect(document.items.find((item) => item.id === 'weight')?.value).toEqual({
      kind: 'text',
      text: '12,000',
    });
    expect(document.items.find((item) => item.id === 'discontinued')?.value).toEqual({
      kind: 'yes_no',
      value: false,
    });
    expect(document.items.find((item) => item.id === 'launch_date')?.value).toEqual({
      kind: 'text',
      text: 'March 9, 2026',
    });
    expect(document.items.find((item) => item.id === 'body')?.value).toEqual({
      kind: 'paragraphs',
      paragraphs: ['Solid oak.', 'Oiled by hand.'],
    });
    expect(document.items.find((item) => item.id === 'ext.app.name')?.value).toEqual({
      kind: 'text',
      text: 'Table',
    });
  });

  test('writes a choice by its label, a list item by item and a repeated group by its own fields', () => {
    const document = previewDocument(PRODUCT, RECORD, 'en');
    const colour = PRODUCT.fields
      .find((field) => field.path === 'colour')
      ?.choices.find((choice) => choice.value === 'green');
    const dimensions = document.items.find((item) => item.id === 'dimensions')?.value;

    expect(document.items.find((item) => item.id === 'colour')?.value).toEqual({
      kind: 'text',
      text: colour?.label,
    });
    expect(document.items.find((item) => item.id === 'tags')?.value.kind).toBe('list');
    expect(dimensions?.kind === 'list' ? dimensions.items[0] : null).toMatchObject({
      kind: 'group',
      items: [
        { id: 'dimensions.size' },
        { id: 'dimensions.width', value: { kind: 'text', text: '180' } },
      ],
    });
  });

  test('leaves out what the record does not hold, and gives no title without one', () => {
    const document = previewDocument(PRODUCT, { cms_id: RECORD.cms_id }, 'da');

    expect(document).toEqual({ title: null, items: [] });
  });

  test('writes numbers and dates in the locale chosen', () => {
    const document = previewDocument(PRODUCT, RECORD, 'da');

    expect(document.items.find((item) => item.id === 'weight')?.value).toEqual({
      kind: 'text',
      text: '12.000',
    });
    expect(document.items.find((item) => item.id === 'launch_date')?.value).toEqual({
      kind: 'text',
      text: '9. marts 2026',
    });
  });
});

describe('what the preview offers', () => {
  test('the panel locale first, then every locale the descriptor labels in', () => {
    expect(previewLocales(PRODUCT, 'en')).toEqual(['en', 'da']);
    expect(previewLocales(PRODUCT, 'da')).toEqual(['da', 'en']);
  });

  test('every revision of a type with full history, the highest first, and the draft alone otherwise', () => {
    expect(previewRevisions(PRODUCT, 3, 3)).toEqual([3, 2, 1]);
    expect(
      previewRevisions(
        { ...PRODUCT, capabilities: { ...PRODUCT.capabilities, history: 'none' } },
        3,
        3,
      ),
    ).toEqual([3]);
  });
});
