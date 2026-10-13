// The paths of an entry form's values (PRD 6.1, 11.10; decision D3 of the editing experience
// proposal), in the form the kernel's FieldPath::toString() writes: a name, then any number of a
// dot and a name, a list index in brackets, or the key of one item of a list in brackets after a
// hash: `title`, `dimensions[2].width`, `dimensions[#k3].width`. A key names the item of a list
// that carries that key instead of its place, so an error stays at its item when the editor moves
// the items. The panel's twin of the same form is js/panel/src/forms/field-path.ts; both read the
// examples of packages/contracts/resources/field-paths.json the same way.

/**
 * The key of one item of a list, which a path writes as the segment `[#<key>]`.
 *
 * @experimental
 */
export interface EntryItemKey {
  readonly key: string;
}

/**
 * One segment of an entry path: a key of an object, a place in a list, or the key of an item of a
 * list.
 *
 * @experimental
 */
export type EntryPathSegment = string | number | EntryItemKey;

/** The whole written form: the first name, then the segments after it. */
const WRITTEN =
  /^([A-Za-z_][A-Za-z0-9_]*)((?:\.[A-Za-z_][A-Za-z0-9_]*|\[(?:0|[1-9][0-9]{0,17})\]|\[#[A-Za-z0-9_-]{1,64}\])*)$/;

/** One segment after the first, as the written form holds it: `.name`, `[0]` or `[#key]`. */
const SEGMENT = /\.[A-Za-z_][A-Za-z0-9_]*|\[#[A-Za-z0-9_-]+\]|\[[0-9]+\]/g;

/**
 * Whether the segment names an item of a list by its key rather than by its place.
 *
 * @experimental
 */
export function isEntryItemKey(segment: EntryPathSegment): segment is EntryItemKey {
  return typeof segment === 'object';
}

/**
 * The segments of the written form of a path, or null when the text is not a path.
 *
 * @experimental
 */
export function parseEntryPath(written: string): readonly EntryPathSegment[] | null {
  const matched = WRITTEN.exec(written);
  const first = matched?.[1];
  const rest = matched?.[2];

  if (first === undefined || rest === undefined) {
    return null;
  }

  const segments: EntryPathSegment[] = [first];

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

/**
 * The segments as the written form holds them, the way FieldPath::toString() writes it; '' for no
 * segments, the document itself.
 *
 * @experimental
 */
export function entryPathText(segments: readonly EntryPathSegment[]): string {
  let text = '';

  for (const segment of segments) {
    text +=
      typeof segment === 'number'
        ? `[${String(segment)}]`
        : isEntryItemKey(segment)
          ? `[#${segment.key}]`
          : (text === '' ? '' : '.') + segment;
  }

  return text;
}
