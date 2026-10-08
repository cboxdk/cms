// The panel's field path parser and writer against the kernel's, on the shared examples
// packages/contracts/resources/field-paths.json, which
// packages/contracts/tests/Results/FieldPathTest.php reads too (PRD 6.1, 11.10; decision D3 of the
// editing experience proposal). Every example names the segments its written form stands for, so
// the two parsers are held to the same grammar on the same input, refusals included.

import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, test } from 'vitest';

import {
  fieldPathText,
  isItemKey,
  parseFieldPath,
  pathSegments,
  type FieldPathSegment,
} from '../../src/forms/field-path';

/** The shared examples of the written form, the file the Pest test reads. */
const EXAMPLES = join(
  import.meta.dirname,
  '../../../../packages/contracts/resources/field-paths.json',
);

/** One segment of an example: an object key, a place in a list, or the key of an item of a list. */
interface ExampleSegment {
  readonly name?: string;
  readonly index?: number;
  readonly key?: string;
}

interface ExamplePath {
  readonly why: string;
  readonly written: string;
  readonly segments: readonly ExampleSegment[];
}

interface ExampleRefusal {
  readonly why: string;
  readonly written: string;
}

interface Examples {
  readonly paths: readonly ExamplePath[];
  readonly refused: readonly ExampleRefusal[];
}

function examples(): Examples {
  return JSON.parse(readFileSync(EXAMPLES, 'utf8')) as Examples;
}

/** The segment an example stands for, in the form the parser gives. */
function segmentOf(segment: ExampleSegment): FieldPathSegment {
  if (segment.name !== undefined) {
    return segment.name;
  }

  if (segment.index !== undefined) {
    return segment.index;
  }

  if (segment.key !== undefined) {
    return { key: segment.key };
  }

  throw new Error(`A segment of ${EXAMPLES} is not a name, an index or a key.`);
}

describe('the field path twin of the panel', () => {
  const { paths, refused } = examples();

  test('reads every example of the shared file into the segments it names', () => {
    expect(paths.length).toBeGreaterThan(0);

    for (const example of paths) {
      expect(parseFieldPath(example.written), example.why).toEqual(example.segments.map(segmentOf));
    }
  });

  test('writes the segments of every example back into its written form', () => {
    for (const example of paths) {
      expect(fieldPathText(example.segments.map(segmentOf)), example.why).toBe(example.written);
    }
  });

  test('refuses every string of the shared file that is not a path', () => {
    expect(refused.length).toBeGreaterThan(0);

    for (const example of refused) {
      expect(parseFieldPath(example.written), example.why).toBeNull();
      expect(pathSegments(example.written), example.why).toEqual([]);
    }
  });

  test('tells a key segment from a name and from an index', () => {
    const segments = parseFieldPath('fields.body[#k3f9].columns[1]') ?? [];

    expect(segments.map(isItemKey)).toEqual([false, false, true, false, false]);
  });

  test('refuses an index past the largest whole number a number holds exactly', () => {
    expect(parseFieldPath('list[9007199254740991]')).toEqual(['list', 9007199254740991]);
    expect(parseFieldPath('list[9007199254740992]')).toBeNull();
    expect(parseFieldPath('list[999999999999999999]')).toBeNull();
  });
});
