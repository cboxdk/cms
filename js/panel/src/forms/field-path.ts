// The panel's twin of the kernel's field path, Cbox\Cms\Contracts\Results\FieldPath (PRD 6.1,
// 11.10; decision D3 of the editing experience proposal): the parser and the writer of the form
// FieldPath::toString() writes, so an error the server answers and a path a contribution gives
// read the same here as they do there.
//
// A path is a name, then any number of a dot and a name, a list index in brackets, or the key of
// one item of a list in brackets after a hash: `title`, `blocks[2].text`,
// `fields.body[#k3f9].heading`. A key names the item of a list that carries that key instead of
// its place, so a path survives a reorder of the list.
//
// Both parsers are held to the same examples, packages/contracts/resources/field-paths.json, by
// packages/contracts/tests/Results/FieldPathTest.php and js/panel/tests/forms/field-path.test.ts.
// PHP reads a list index of up to 18 digits; a number holds only whole numbers up to
// Number.MAX_SAFE_INTEGER exactly, so this parser refuses a longer one rather than give a place
// that is not the one written.

/** The key of one item of a list, which a path writes as the segment `[#<key>]`. */
export interface ItemKey {
  readonly key: string;
}

/** One segment of a path: a key of an object, a place in a list, or the key of an item of a list. */
export type FieldPathSegment = string | number | ItemKey;

/** The whole written form: the first name, then the segments after it. */
const WRITTEN =
  /^([A-Za-z_][A-Za-z0-9_]*)((?:\.[A-Za-z_][A-Za-z0-9_]*|\[(?:0|[1-9][0-9]{0,17})\]|\[#[A-Za-z0-9_-]{1,64}\])*)$/;

/** One segment after the first, as the written form holds it: `.name`, `[0]` or `[#key]`. */
const SEGMENT = /\.[A-Za-z_][A-Za-z0-9_]*|\[#[A-Za-z0-9_-]+\]|\[[0-9]+\]/g;

/** Whether the segment names an item of a list by its key rather than by its place. */
export function isItemKey(segment: FieldPathSegment): segment is ItemKey {
  return typeof segment === 'object';
}

/** The segments of the written form of a path, or null when the text is not a path. */
export function parseFieldPath(written: string): readonly FieldPathSegment[] | null {
  const matched = WRITTEN.exec(written);
  const first = matched?.[1];
  const rest = matched?.[2];

  if (first === undefined || rest === undefined) {
    return null;
  }

  const segments: FieldPathSegment[] = [first];

  for (const match of rest.matchAll(SEGMENT)) {
    const segment = match[0];

    if (segment.startsWith('.')) {
      segments.push(segment.slice(1));
    } else if (segment.startsWith('[#')) {
      segments.push({ key: segment.slice(2, -1) });
    } else {
      const index = Number(segment.slice(1, -1));

      if (!Number.isSafeInteger(index)) {
        return null;
      }

      segments.push(index);
    }
  }

  return segments;
}

/** The segments as the written form holds them, the way FieldPath::toString() writes it. */
export function fieldPathText(segments: readonly FieldPathSegment[]): string {
  let text = '';

  for (const segment of segments) {
    text +=
      typeof segment === 'number'
        ? `[${String(segment)}]`
        : isItemKey(segment)
          ? `[#${segment.key}]`
          : (text === '' ? '' : '.') + segment;
  }

  return text;
}

/** The segments of the written form of a path, or none when the text is not a path. */
export function pathSegments(written: string): readonly FieldPathSegment[] {
  return parseFieldPath(written) ?? [];
}
