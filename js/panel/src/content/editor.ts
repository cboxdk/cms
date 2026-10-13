// What the entry editor works with (PRD 5.4, 11.12, 13.4): the page's reads, type.describe for the
// type's editor descriptor, entry.get for the entry it edits and, for a new entry, node.list for
// the nodes its home may be, each checked by its generated validator; the documents a save sends,
// entry.create or entry.revise version 1, checked the same way before they go; the errors of a
// rejected save at the fields they are about; and what a save's receipt says. Nothing here names a
// type or a field (GUARDRAILS 2.4): the form is the descriptor's.

import type { JsonObject } from '@cboxdk/cms-ui-kit';

import type { ContentEditorPageV1, ReadAnswerV1 } from '../generated/pages/ContentEditorPageV1';
import { validateCreateEntryV1, type CreateEntryV1 } from '../generated/protocol/CreateEntryV1';
import {
  validateEntryDetailV1,
  type EntryDetailV1,
  type EntryHeadV1,
} from '../generated/protocol/EntryDetailV1';
import { validateNodeListV1, type NodeListV1 } from '../generated/protocol/NodeListV1';
import type { ProblemV1 } from '../generated/protocol/ProblemV1';
import type { ReceiptV1 } from '../generated/protocol/ReceiptV1';
import { validateReviseEntryV1, type ReviseEntryV1 } from '../generated/protocol/ReviseEntryV1';
import { validateTypeDescriptionV1 } from '../generated/protocol/TypeDescriptionV1';
import type { TypeEditorV1 } from '../generated/protocol/TypeEditorV1';
import { validateTypeEditorV1 } from '../generated/protocol/TypeEditorV1';
import { readOf, type ReadState } from '../access/reads';
import { lookup, type Locale, type TranslationKey } from '../i18n/translations';
import type { PaletteState } from '../shell/palette';

/** The command a save of a new entry runs, and its version. */
export const CREATE = { name: 'entry.create', version: 1 } as const;

/** The command a save of an entry runs, and its version. */
export const REVISE = { name: 'entry.revise', version: 1 } as const;

/** The variant of an entry that holds every field of a type without localization. */
export const SHARED = 'shared';

/** The member of a command's document that holds the revision's fields. */
const FIELDS = 'fields';

/** What the page's reads gave: the form it can show, or why it cannot. */
export type EditorState =
  | {
      readonly status: 'ready';
      readonly descriptor: TypeEditorV1;
      /** The entry the page edits, or null for a new entry. */
      readonly entry: EntryDetailV1 | null;
      /** The shared variant's head of the entry, whose draft the form shows, or null for a new entry. */
      readonly head: EntryHeadV1 | null;
      /** The nodes a new entry's home may be, or null for the page of an entry. */
      readonly nodes: ReadState<NodeListV1> | null;
    }
  /** The installation has no type of the address's name the person may see. */
  | { readonly status: 'missing_type' }
  /** The type's entries hold a variant per language, which the editor's language tabs edit. */
  | { readonly status: 'localized'; readonly descriptor: TypeEditorV1 }
  /** A read was rejected, with its problem details. */
  | { readonly status: 'rejected'; readonly problem: ProblemV1 }
  /** A read gave a document the panel cannot read. */
  | { readonly status: 'unreadable' };

/** The type's editor descriptor from the read of type.describe, checked by its validator. */
function descriptorOf(read: ReadAnswerV1): ReadState<TypeEditorV1 | null> {
  const described = readOf(read.result, read.rejection, validateTypeDescriptionV1);

  if (described.status !== 'ready') {
    return described;
  }

  if (described.value.type === null) {
    return { status: 'ready', value: null };
  }

  const editor = validateTypeEditorV1(described.value.type);

  return editor.valid ? { status: 'ready', value: editor.value } : { status: 'unreadable' };
}

/** What the page's props give the editor to show. */
export function editorStateOf(props: ContentEditorPageV1): EditorState {
  const described = descriptorOf(props.type_read);

  if (described.status === 'rejected') {
    return { status: 'rejected', problem: described.problem };
  }

  if (described.status === 'unreadable') {
    return { status: 'unreadable' };
  }

  const descriptor = described.value;

  if (descriptor === null || descriptor.name !== props.type) {
    return { status: 'missing_type' };
  }

  if (descriptor.capabilities.localization !== 'none') {
    return { status: 'localized', descriptor };
  }

  if (props.entry_read === null) {
    return {
      status: 'ready',
      descriptor,
      entry: null,
      head: null,
      nodes:
        props.nodes_read === null
          ? { status: 'unreadable' }
          : readOf(props.nodes_read.result, props.nodes_read.rejection, validateNodeListV1),
    };
  }

  const read = readOf(props.entry_read.result, props.entry_read.rejection, validateEntryDetailV1);

  if (read.status === 'rejected') {
    return { status: 'rejected', problem: read.problem };
  }

  if (read.status === 'unreadable' || read.value.type !== descriptor.id) {
    return { status: read.status === 'unreadable' ? 'unreadable' : 'missing_type' };
  }

  const head = read.value.heads.find((candidate) => candidate.variant === SHARED);

  return head === undefined
    ? { status: 'unreadable' }
    : { status: 'ready', descriptor, entry: read.value, head, nodes: null };
}

/** The address of the editor of an entry of the type, below the address of the type's list. */
export function entryUrl(list: string, entry: string): string {
  return `${list.replace(/\/+$/, '')}/${entry}`;
}

/** The address of the editor of a new entry of the type, below the address of the type's list. */
export function newEntryUrl(list: string): string {
  return `${list.replace(/\/+$/, '')}/new`;
}

/** The document of entry.create version 1 for a new entry, or undefined when it is not one. */
export function createDocument(
  entry: string,
  descriptor: TypeEditorV1,
  home: string,
  fields: JsonObject,
): CreateEntryV1 | undefined {
  const checked = validateCreateEntryV1({ entry, type: descriptor.id, home, fields });

  return checked.valid ? checked.value : undefined;
}

/** The document of entry.revise version 1 for the entry's shared variant at the version it was read at. */
export function reviseDocument(
  entry: EntryDetailV1,
  head: EntryHeadV1,
  fields: JsonObject,
): ReviseEntryV1 | undefined {
  const checked = validateReviseEntryV1({ entry: entry.id, version: head.version, fields });

  return checked.valid ? checked.value : undefined;
}

/** The errors of a rejected save, at the paths the editor shows them at. */
export interface SaveErrors {
  /** By the path of the value in the fields, as EntryForm takes them, such as `title` or `items[1].name`. */
  readonly fields: Readonly<Record<string, string>>;
  /** The error at the new entry's home, or undefined. */
  readonly home: string | undefined;
  /** How many errors of the rejection are about the document as a whole, or a member the form has no control for. */
  readonly other: number;
}

/**
 * The path of an error in the command's document, without the surface's `command.` in front of
 * it, such as `fields.title` or `version`.
 */
function ownPath(path: string): string {
  return path.startsWith('command.') ? path.slice('command.'.length) : path;
}

/**
 * The errors of the problem details of a rejected save at the fields of the form: an error below
 * the document's fields is shown at its field, in the panel's text for its catalog code when the
 * catalogue has one and as the server's detail otherwise; an error at the home is shown at the
 * home's picker; every other error is counted, and the page shows the problem itself.
 */
export function saveErrorsOf(problem: ProblemV1 | null, locale: Locale): SaveErrors {
  const fields: Record<string, string> = {};
  let home: string | undefined;
  let other = 0;

  for (const error of problem?.errors ?? []) {
    const path = error.field === null ? null : ownPath(error.field);
    const text = lookup(locale, issueKey(error.code)) ?? error.detail;

    if (path !== null && path.startsWith(`${FIELDS}.`)) {
      fields[path.slice(FIELDS.length + 1)] ??= text;
    } else if (path !== null && path.startsWith(`${FIELDS}[`)) {
      fields[path.slice(FIELDS.length)] ??= text;
    } else if (path === 'home') {
      home ??= text;
    } else {
      other += 1;
    }
  }

  return { fields, home, other };
}

/** The key of the panel's text of a catalog code an error of a field has. */
export function issueKey(code: string): string {
  return `panel.editor.issue.${code}`;
}

/** What a save's receipt says, as the editor's status shows it. */
export interface SaveStatus {
  readonly tone: 'neutral' | 'info' | 'success' | 'warning' | 'danger';
  readonly key: TranslationKey;
}

/**
 * The status of the receipt of a save: saved once it committed, saved and still on its way to the
 * site when the wait level was not reached in time, which is no failure, and not saved when it was
 * rejected.
 */
export function saveStatusOf(receipt: ReceiptV1): SaveStatus {
  switch (receipt.outcome) {
    case 'committed':
      return { tone: 'success', key: 'panel.editor.status.saved' };
    case 'committed_wait_timeout':
      return { tone: 'info', key: 'panel.editor.status.saved_pending' };
    case 'dry_run':
      return { tone: 'neutral', key: 'panel.editor.status.dry_run' };
    case 'rejected':
      return { tone: 'danger', key: 'panel.editor.status.rejected' };
  }
}

/** Whether the receipt is of a save that committed a changeset. */
export function committed(receipt: ReceiptV1): boolean {
  return receipt.outcome === 'committed' || receipt.outcome === 'committed_wait_timeout';
}

/** Whether the person may run the command, as the palette, the read of action.list, lists it. */
export function mayRun(
  palette: PaletteState,
  command: { readonly name: string; readonly version: number },
): boolean {
  return (
    palette.status === 'ready' &&
    palette.list.actions.some(
      (action) =>
        action.kind === 'command' &&
        action.name === command.name &&
        action.version === command.version,
    )
  );
}

/** Whether a key press is the shortcut that saves, ⌘↵ on Apple's systems and Ctrl+Enter elsewhere. */
export function isSaveShortcut(event: {
  readonly key: string;
  readonly metaKey: boolean;
  readonly ctrlKey: boolean;
  readonly altKey: boolean;
  readonly shiftKey: boolean;
}): boolean {
  return (
    event.key === 'Enter' && (event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey
  );
}

/** The entry's title, the value of the title field its type's descriptor names, or null. */
export function editorTitleOf(descriptor: TypeEditorV1, fields: JsonObject): string | null {
  const field = descriptor.title_field;
  const value = field === null ? undefined : fields[field];

  return typeof value === 'string' && value.trim() !== '' ? value : null;
}
