// What the entry editor compares after a version conflict (PRD 5.4, 6.1): the top-level fields of
// the type, the owner's and each extender's under ext, whose saved value and the person's edit
// differ, empty values counting as equal; a field's value at its path, set and removed there; and
// whether a problem is a version conflict and a head read again is newer.

import { rejectedProblem } from '@cboxdk/cms-panel/testing';
import { describe, expect, test } from 'vitest';

import {
  fieldDifferences,
  fieldValue,
  isEmptyValue,
  isNewerHead,
  isVersionConflict,
  sameFields,
  valueText,
  withFieldValue,
} from '../../src/content/conflict';
import type { EntryHeadV1 } from '../../src/generated/protocol/EntryDetailV1';
import type { EditorFieldV1, TypeEditorV1 } from '../../src/generated/protocol/TypeEditorV1';

function field(path: string, label: string, changes: Partial<EditorFieldV1> = {}): EditorFieldV1 {
  const segments = path.split('.');

  return {
    agents: true,
    base: 'text',
    choices: [],
    classification: 'public',
    default: null,
    description: null,
    descriptions: [],
    encrypted: false,
    fallback: 'none',
    handle: segments[segments.length - 1] ?? path,
    label,
    labels: [{ locale: 'da', text: `${label} (da)` }],
    namespace: segments[0] === 'ext' ? (segments[1] ?? null) : null,
    parent: null,
    path,
    presence: 'optional',
    repeat: null,
    rule: null,
    targets: [],
    translatable: false,
    ...changes,
  } as EditorFieldV1;
}

const DESCRIPTOR = {
  fields: [
    field('title', 'Title'),
    field('dimensions', 'Dimensions', { base: 'group' }),
    field('dimensions.width', 'Width', { parent: 'dimensions' }),
    field('tags', 'Tags'),
    field('ext.seo.slug', 'Slug'),
  ],
} as unknown as TypeEditorV1;

describe('the comparison', () => {
  test('lists the top-level fields that differ, in form order, labelled in the locale', () => {
    const theirs = {
      title: 'Saved',
      dimensions: { width: 2 },
      tags: [],
      ext: { seo: { slug: 'saved' } },
    };
    const mine = {
      title: 'Mine',
      dimensions: { width: 2 },
      tags: null,
      ext: { seo: { slug: 'mine' } },
    };

    expect(fieldDifferences(DESCRIPTOR, theirs, mine, 'en')).toEqual([
      { path: 'title', label: 'Title', theirs: 'Saved', mine: 'Mine' },
      { path: 'ext.seo.slug', label: 'Slug', theirs: 'saved', mine: 'mine' },
    ]);
    expect(fieldDifferences(DESCRIPTOR, { dimensions: { width: 3 } }, {}, 'da')).toEqual([
      { path: 'dimensions', label: 'Dimensions (da)', theirs: { width: 3 }, mine: undefined },
    ]);
  });

  test('counts none, null, an empty text, list or object as the same empty value', () => {
    expect(sameFields({ title: '', tags: [], dimensions: {} }, { ext: { seo: {} } })).toBe(true);
    expect(sameFields({ a: { x: 1, y: 2 } }, { a: { y: 2, x: 1 } })).toBe(true);
    expect(sameFields({ a: [1, 2] }, { a: [2, 1] })).toBe(false);
    expect(isEmptyValue(null)).toBe(true);
    expect(isEmptyValue(0)).toBe(false);
    expect(isEmptyValue(false)).toBe(false);
  });

  test('reads, sets and removes a value at its path, an extender’s below ext', () => {
    const fields = { title: 'A', ext: { seo: { slug: 'a', other: 1 } } };

    expect(fieldValue(fields, 'title')).toBe('A');
    expect(fieldValue(fields, 'ext.seo.slug')).toBe('a');
    expect(fieldValue(fields, 'ext.missing.slug')).toBeUndefined();
    expect(withFieldValue(fields, 'ext.seo.slug', 'b')).toEqual({
      title: 'A',
      ext: { seo: { slug: 'b', other: 1 } },
    });
    expect(withFieldValue(fields, 'ext.seo.slug', undefined)).toEqual({
      title: 'A',
      ext: { seo: { other: 1 } },
    });
    expect(withFieldValue(fields, 'title', undefined)).toEqual({ ext: fields.ext });
    expect(withFieldValue({}, 'ext.seo.slug', 'c')).toEqual({ ext: { seo: { slug: 'c' } } });
  });

  test('shows a text as it is, anything else as JSON, and an empty value as none', () => {
    expect(valueText('Oak')).toBe('Oak');
    expect(valueText({ width: 2 })).toBe('{"width":2}');
    expect(valueText(true)).toBe('true');
    expect(valueText('')).toBeNull();
    expect(valueText(undefined)).toBeNull();
  });
});

describe('the conflict', () => {
  test('is a rejection with version_conflict, as its code or one of its errors', () => {
    expect(isVersionConflict(rejectedProblem('version_conflict').problem)).toBe(true);
    expect(
      isVersionConflict(
        rejectedProblem('validation_failed', [{ code: 'version_conflict' }]).problem,
      ),
    ).toBe(true);
    expect(isVersionConflict(rejectedProblem('validation_failed').problem)).toBe(false);
    expect(isVersionConflict(null)).toBe(false);
  });

  test('has a newer head when the head read again has a higher version', () => {
    const head = { version: 4 } as EntryHeadV1;

    expect(isNewerHead({ version: 5 } as EntryHeadV1, head)).toBe(true);
    expect(isNewerHead(head, head)).toBe(false);
    expect(isNewerHead(null, head)).toBe(false);
    expect(isNewerHead(head, null)).toBe(false);
  });
});
