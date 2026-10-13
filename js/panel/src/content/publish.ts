// What the editor's publish panel works with (PRD 5.6, 5.7, 5.9, 6.4): the entry's placements as
// entry.get read them, each locale of a placement with its path below the node it sits under; the
// documents of the commands the panel sends, placement.create for a new placement, entry.publish
// for the home placement, variant.release followed by placement.set_window for any other, and
// entry.unpublish, each checked by its generated validator before it goes; and a window from the
// browser's local date and time controls. Nothing here names a type or a field (GUARDRAILS 2.4).

import {
  validateCreatePlacementV1,
  type CreatePlacementV1,
} from '../generated/protocol/CreatePlacementV1';
import type {
  EntryDetailV1,
  EntryHeadV1,
  EntryPlacementLocaleV1,
  EntryPlacementV1,
} from '../generated/protocol/EntryDetailV1';
import type { ListedNodeV1 } from '../generated/protocol/NodeListV1';
import { validatePublishEntryV1, type PublishEntryV1 } from '../generated/protocol/PublishEntryV1';
import {
  validateReleaseVariantV1,
  type ReleaseVariantV1,
} from '../generated/protocol/ReleaseVariantV1';
import {
  validateSetPlacementWindowV1,
  type SetPlacementWindowV1,
} from '../generated/protocol/SetPlacementWindowV1';
import type { TypeEditorV1 } from '../generated/protocol/TypeEditorV1';
import {
  validateUnpublishEntryV1,
  type UnpublishEntryV1,
} from '../generated/protocol/UnpublishEntryV1';

/** The command that creates a placement, and its version. */
export const PLACE = { name: 'placement.create', version: 1 } as const;

/** The command that releases the draft and puts the home placement live, and its version. */
export const PUBLISH = { name: 'entry.publish', version: 1 } as const;

/** The command that releases a revision of the shared variant, and its version. */
export const RELEASE = { name: 'variant.release', version: 1 } as const;

/** The command that sets a placement's window in a locale, and its version. */
export const WINDOW = { name: 'placement.set_window', version: 1 } as const;

/** The command that takes the release back and closes every visible placement, and its version. */
export const UNPUBLISH = { name: 'entry.unpublish', version: 1 } as const;

/** A command the panel names as `<name>@<version>`. */
export interface CommandOf {
  readonly name: string;
  readonly version: number;
}

/** The form of a command the host's transport takes, `<name>@<version>`. */
export function commandName(command: CommandOf): string {
  return `${command.name}@${String(command.version)}`;
}

/** One locale of a placement, as the panel lists it. */
export interface PlacementRow {
  /** The placement, with its id and version. */
  readonly placement: EntryPlacementV1;
  /** The placement in the locale, with its slug, visibility and window. */
  readonly locale: EntryPlacementLocaleV1;
  /** The path label of the node the placement sits under, then the slug, such as north/news/rowing. */
  readonly path: string;
  /** Whether the placement sits under the entry's home node, which entry.publish puts live. */
  readonly home: boolean;
}

/** Every locale of every placement of the entry, in the order entry.get gives them. */
export function placementRows(
  entry: EntryDetailV1,
  nodes: readonly ListedNodeV1[],
): readonly PlacementRow[] {
  const labels = new Map(nodes.map((node) => [node.id, node.label]));

  return entry.placements.flatMap((placement) =>
    placement.locales.map((locale) => ({
      placement,
      locale,
      path: `${labels.get(placement.node) ?? placement.node}/${locale.slug}`,
      home: placement.node === entry.home,
    })),
  );
}

/** The path of a placement in a locale, by the placement's id, for the dry run's list of what becomes visible. */
export function rowPath(
  rows: readonly PlacementRow[],
  placement: string,
  locale: string,
): string | undefined {
  return rows.find((row) => row.placement.id === placement && row.locale.locale === locale)?.path;
}

/**
 * The revision a publish releases: the draft, or the released revision when nothing was written
 * after the release, whose content is the draft's, so the kernel leaves the release out; null for
 * a type without stages, which releases nothing.
 */
export function revisionToRelease(descriptor: TypeEditorV1, head: EntryHeadV1): number | null {
  if (descriptor.capabilities.stages === 'none') {
    return null;
  }

  return head.released !== null && head.released === head.latest ? head.released : head.revision;
}

/** Whether a release is needed before a placement shows the draft: the type has stages and the draft is not the released content. */
export function needsRelease(descriptor: TypeEditorV1, head: EntryHeadV1): boolean {
  const revision = revisionToRelease(descriptor, head);

  return revision !== null && (head.state !== 'released' || revision !== head.released);
}

/** When a placement is live: from and until, each RFC 3339 with an offset, or null for no bound. */
export interface Window {
  readonly live_from: string | null;
  readonly live_until: string | null;
}

/** A step of a publish: the command and its document. */
export interface Step {
  readonly command: CommandOf;
  readonly document: object;
}

/**
 * The steps that put the row live in its locale with the window, or from now when it is null: for
 * the home placement entry.publish, which releases and opens the window in one changeset; for any
 * other placement variant.release when the draft is not released yet, then placement.set_window.
 * Undefined when a document is not one the kernel takes.
 */
export function publishSteps(
  descriptor: TypeEditorV1,
  entry: EntryDetailV1,
  head: EntryHeadV1,
  row: PlacementRow,
  window: Window | null,
  now: Date,
): readonly Step[] | undefined {
  if (row.home) {
    const publish = validatePublishEntryV1({
      entry: entry.id,
      version: head.version,
      revision: revisionToRelease(descriptor, head),
      placement: row.placement.id,
      placement_version: row.placement.version,
      locale: row.locale.locale,
      window,
    } satisfies PublishEntryV1);

    return publish.valid ? [{ command: PUBLISH, document: publish.value }] : undefined;
  }

  const shown = validateSetPlacementWindowV1({
    placement: row.placement.id,
    version: row.placement.version,
    locale: row.locale.locale,
    window: window ?? { live_from: now.toISOString(), live_until: null },
  } satisfies SetPlacementWindowV1);

  if (!shown.valid) {
    return undefined;
  }

  const revision = revisionToRelease(descriptor, head);

  if (!needsRelease(descriptor, head) || revision === null) {
    return [{ command: WINDOW, document: shown.value }];
  }

  const release = validateReleaseVariantV1({
    entry: entry.id,
    version: head.version,
    revision,
  } satisfies ReleaseVariantV1);

  return release.valid
    ? [
        { command: RELEASE, document: release.value },
        { command: WINDOW, document: shown.value },
      ]
    : undefined;
}

/** The step that takes the release back and closes every placement that shows the entry, or undefined. */
export function unpublishStep(entry: EntryDetailV1, head: EntryHeadV1): Step | undefined {
  const checked = validateUnpublishEntryV1({
    entry: entry.id,
    version: head.version,
  } satisfies UnpublishEntryV1);

  return checked.valid ? { command: UNPUBLISH, document: checked.value } : undefined;
}

/** The step that places the entry under the node with the slug in the locale, or undefined when it is not one. */
export function placeStep(
  placement: string,
  entry: EntryDetailV1,
  node: ListedNodeV1,
  slug: string,
  locale: string,
): Step | undefined {
  if (node.site === null) {
    return undefined;
  }

  const checked = validateCreatePlacementV1({
    placement,
    entry: entry.id,
    node: node.id,
    site: node.site,
    slugs: [{ locale: locale.trim(), slug: slug.trim() }],
  } satisfies CreatePlacementV1);

  return checked.valid ? { command: PLACE, document: checked.value } : undefined;
}

/** The nodes a placement may sit under: every node of a site's tree that is no mount. */
export function placeableNodes(nodes: readonly ListedNodeV1[]): readonly ListedNodeV1[] {
  return nodes.filter((node) => node.site !== null && node.kind !== 'mount');
}

/** Two digits, with a leading zero. */
function twoDigits(value: number): string {
  return String(value).padStart(2, '0');
}

/**
 * The value of the browser's local date and time control, `YYYY-MM-DDTHH:MM` or with seconds, as
 * RFC 3339 with the browser's offset, or null when it is empty or no date and time.
 */
export function fromLocalDateTime(value: string): string | null {
  const text = value.trim();

  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/.test(text)) {
    return null;
  }

  const local = text.length === 16 ? `${text}:00` : text;
  const instant = new Date(local);

  if (Number.isNaN(instant.getTime())) {
    return null;
  }

  const offset = -instant.getTimezoneOffset();
  const minutes = Math.abs(offset);

  return `${local}${offset === 0 ? 'Z' : `${offset < 0 ? '-' : '+'}${twoDigits(Math.floor(minutes / 60))}:${twoDigits(minutes % 60)}`}`;
}

/** What is wrong with a window the person gave, or null when it is one the kernel takes. */
export type WindowProblem = 'missing' | 'order' | null;

/** The window of the controls' values, or why it is none: neither bound given, or the start not before the end. */
export function windowOf(
  from: string,
  until: string,
): { window: Window | null; problem: WindowProblem } {
  const start = fromLocalDateTime(from);
  const end = fromLocalDateTime(until);

  if (start === null && end === null) {
    return { window: null, problem: 'missing' };
  }

  if (start !== null && end !== null && new Date(start).getTime() >= new Date(end).getTime()) {
    return { window: null, problem: 'order' };
  }

  return { window: { live_from: start, live_until: end }, problem: null };
}

/** A slug the kernel takes: one path segment, without white space or a slash, and not `.` or `..`. */
export function isSlug(value: string): boolean {
  const text = value.trim();

  return text.length > 0 && text.length <= 255 && /^(?!\.\.?$)[^/\s]+$/.test(text);
}

/** A locale the kernel takes: a language, an optional script and an optional region, such as da or en-GB. */
export function isLocale(value: string): boolean {
  return /^[a-zA-Z]{2,3}(-[a-zA-Z]{4})?(-([a-zA-Z]{2}|[0-9]{3}))?$/.test(value.trim());
}
