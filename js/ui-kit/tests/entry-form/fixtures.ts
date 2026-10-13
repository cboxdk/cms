// The type editor descriptors the entry form's tests render: the comprehensive example of the
// generators (MILESTONES M1 point 1), packages/generators/tests/Descriptor/Fixtures/Comprehensive,
// whose golden editor.json is what cms:generate compiles for a type with every core field type,
// every kind of select, a group, a repeated group with a summary template and conditional fields,
// tabs, a sidebar, every width, defaults, hidden and read-only fields and an extender, read and
// checked with the generated validator of type-editor.v1.json, and variants of it with one thing
// changed.

import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import {
  validateTypeEditorV1,
  type EditorFieldV1,
  type TypeEditorV1,
} from '../../src/generated/protocol/TypeEditorV1';

const FIXTURES = join(
  import.meta.dirname,
  '../../../../packages/generators/tests/Descriptor/Fixtures',
);

function read(name: string): TypeEditorV1 {
  const checked = validateTypeEditorV1(
    JSON.parse(readFileSync(join(FIXTURES, name, 'editor.json'), 'utf8')) as unknown,
  );

  if (!checked.valid) {
    throw new Error(`${name}/editor.json is not a type editor descriptor: ${checked.issue.reason}`);
  }

  return checked.value;
}

/** The comprehensive example's descriptor: the type shop:product. */
export const PRODUCT = read('Comprehensive');

/** The descriptor with the fields at the paths changed. */
export function changed(
  descriptor: TypeEditorV1,
  changes: Readonly<Record<string, (field: EditorFieldV1) => EditorFieldV1>>,
): TypeEditorV1 {
  return {
    ...descriptor,
    fields: descriptor.fields.map((field) => changes[field.path]?.(field) ?? field),
  };
}

/** The field of the descriptor at the path. */
export function field(descriptor: TypeEditorV1, path: string): EditorFieldV1 {
  const found = descriptor.fields.find((candidate) => candidate.path === path);

  if (found === undefined) {
    throw new Error(`${descriptor.name} has no field ${path}.`);
  }

  return found;
}

/** A clock at 13 October 2026, 09:30:05 in the test's time zone. */
export const NOW = new Date(2026, 9, 13, 9, 30, 5);
