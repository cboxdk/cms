// The model of the entry form (PRD 11.8, 11.12, gap G3): its client validation runs the generated
// validation runtime over the rules the descriptor carries, with the error catalog's codes and the
// kernel's FieldPaths, the write stage leaving a field required on release empty and the release
// stage not; a conditional field is required only while shown, and a value on a hidden field
// marked clear is refused. The defaults of a new entry are the descriptor's constants and the
// tokens today and now, and a hidden field marked clear loses its value. The paths with the keys
// of items read and write as the kernel's FieldPath does.

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, test } from 'vitest';

import { editorText } from '../../src/components/entry-form/model';
import { entryPathText, parseEntryPath } from '../../src/components/entry-form/paths';
import { validateEntry } from '../../src/components/entry-form/rules';
import {
  clearHidden,
  localDate,
  localDateTime,
  newEntryFields,
  newGroupValue,
} from '../../src/components/entry-form/values';
import { field, NOW, PRODUCT } from './fixtures';

/** A product that meets every rule. */
const VALID = {
  name: 'Lamp',
  colour: 'red',
  price: '12.50',
  discontinued: false,
};

/** The issues as `path: code`. */
function issues(value: Record<string, unknown>, stage: 'write' | 'release' = 'write'): string[] {
  return validateEntry(PRODUCT, value as never, stage).map(
    (issue) => `${issue.path ?? '(fields)'}: ${issue.code}`,
  );
}

describe('the client validation', () => {
  test('passes fields that meet every rule', () => {
    expect(issues(VALID)).toEqual([]);
  });

  test('gives every value that breaks a rule, each with its path and code', () => {
    expect(
      issues({
        ...VALID,
        name: '',
        stock: -1,
        price: '1.234',
        tags: ['new', 'new'],
        support_email: 'nobody',
        supplier: { website: 'ftp://example.org' },
      }),
    ).toEqual([
      'name: validation_too_short',
      'price: validation_too_many_digits',
      'stock: validation_below_minimum',
      'supplier.company: validation_required',
      'supplier.website: validation_invalid_format',
      'support_email: validation_invalid_format',
      'tags[1]: validation_duplicate_item',
    ]);
  });

  test('requires a field required on release only at the release stage', () => {
    expect(issues(VALID)).toEqual([]);
    expect(issues(VALID, 'release')).toEqual(['ext.app.tax_code: validation_required']);
    expect(issues({ ...VALID, ext: { app: { tax_code: 'DK-25' } } }, 'release')).toEqual([]);
  });

  test('requires a conditional field only while it is shown, at the path of its item', () => {
    const items = [{ size: 'small' }, { size: 'large' }];

    expect(issues({ ...VALID, dimensions: items })).toEqual([
      'dimensions[1].width: validation_required',
    ]);
    expect(issues({ ...VALID, discontinued: null, dimensions: items })).toEqual([]);
  });

  test('refuses a value on a hidden field marked clear, and keeps one marked keep', () => {
    expect(issues({ ...VALID, discontinued: true, support_email: 'care@example.org' })).toEqual([
      'support_email: validation_hidden_field',
    ]);
    expect(
      issues({ ...VALID, discontinued: null, dimensions: [{ size: 'small', width: 40 }] }),
    ).toEqual([]);
  });

  test('refuses a field the type does not have', () => {
    expect(issues({ ...VALID, colour_code: 'x' })).toEqual(['(fields): validation_unknown_field']);
  });

  test('checks the rules of the repeated group, as the record does', () => {
    expect(issues({ ...VALID, dimensions: [] })).toEqual(['dimensions: validation_too_few_items']);
    expect(issues({ ...VALID, dimensions: [{ size: 'medium' }] })).toEqual([
      'dimensions[0].size: validation_not_an_option',
    ]);
  });
});

describe('the defaults', () => {
  test('give a new entry the constants and the tokens today and now', () => {
    const fields = newEntryFields(PRODUCT, NOW);

    expect(fields).toEqual({
      stock: 0,
      price: '0.00',
      discontinued: false,
      launch_date: '2026-10-13',
      checked_at: localDateTime(NOW),
      colour: 'red',
      tags: ['new'],
    });
    expect(localDate(NOW)).toBe('2026-10-13');
    expect(localDateTime(NOW)).toMatch(/^2026-10-13T09:30:05(Z|[+-][0-9]{2}:[0-9]{2})$/);
  });

  test('give a new item of a repeated group the defaults of its fields', () => {
    expect(newGroupValue(PRODUCT, field(PRODUCT, 'dimensions'), NOW)).toEqual({});
  });
});

describe('a hidden field marked clear', () => {
  test('loses its value while its condition does not hold', () => {
    const shown = { ...VALID, support_email: 'care@example.org' };

    expect(clearHidden(PRODUCT, shown)).toBe(shown);
    expect(clearHidden(PRODUCT, { ...shown, discontinued: true })).toEqual({
      ...VALID,
      discontinued: true,
    });
  });

  test('keeps its value when marked keep', () => {
    const kept = { ...VALID, discontinued: null, dimensions: [{ size: 'small', width: 40 }] };

    expect(clearHidden(PRODUCT, kept)).toBe(kept);
  });
});

describe('the texts of the descriptor', () => {
  test("are in the reader's locale, its language, or the blueprint's own", () => {
    const name = field(PRODUCT, 'name');

    expect(editorText(name.label, name.labels, 'da')).toBe('Navn');
    expect(editorText(name.label, name.labels, 'da-DK')).toBe('Navn');
    expect(editorText(name.label, name.labels, 'en-GB')).toBe('Name');
    expect(editorText(name.label, name.labels, 'de')).toBe('Name');
  });
});

describe('the paths of the values', () => {
  test("read and write the examples of the kernel's FieldPath", () => {
    const examples = JSON.parse(
      readFileSync(
        join(import.meta.dirname, '../../../../packages/contracts/resources/field-paths.json'),
        'utf8',
      ),
    ) as {
      readonly paths: readonly {
        readonly written: string;
        readonly segments: readonly Readonly<Record<string, string | number>>[];
      }[];
      readonly refused: readonly { readonly written: string }[];
    };

    for (const example of examples.paths) {
      const expected = example.segments.map((segment) =>
        'key' in segment ? { key: segment['key'] } : (segment['name'] ?? segment['index']),
      );

      expect(parseEntryPath(example.written), example.written).toEqual(expected);
      expect(entryPathText(parseEntryPath(example.written) ?? [])).toBe(example.written);
    }

    for (const example of examples.refused) {
      expect(parseEntryPath(example.written), example.written).toBeNull();
    }
  });

  test('name an item by its key', () => {
    expect(parseEntryPath('dimensions[#k3].width')).toEqual(['dimensions', { key: 'k3' }, 'width']);
    expect(entryPathText(['dimensions', 1, 'width'])).toBe('dimensions[1].width');
  });
});
