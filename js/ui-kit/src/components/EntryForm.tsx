import { useId, useMemo, useState, type ReactNode } from 'react';

import type {
  EditorFieldV1,
  EditorSectionV1,
  TypeEditorV1,
} from '../generated/protocol/TypeEditorV1';
import type { IssueCode } from '../generated/protocol/validation';
import { useKitTranslation, type KitTranslate, type KitTranslationKey } from '../i18n/translations';
import { Button } from './Button';
import { ENTRY_FIELD_INPUTS, type EntryFieldInputs } from './entry-form/inputs';
import {
  editorText,
  fieldAt,
  fieldsBelow,
  isObject,
  isRepeated,
  objectAt,
  optionalEditorText,
  topLevelKeys,
  valueAt,
  withValueAt,
} from './entry-form/model';
import { entryPathText, parseEntryPath, type EntryPathSegment } from './entry-form/paths';
import { validateEntry, type EntryIssue, type EntryStage } from './entry-form/rules';
import { clearHidden, isShown, newGroupValue } from './entry-form/values';
import { ErrorSummary, type ErrorSummaryItem } from './ErrorSummary';
import { Fieldset } from './Fieldset';
import { Icon } from './Icon';
import { IconButton } from './IconButton';
import type { JsonObject, JsonValue } from './schema-form/model';
import { Tabs } from './Tabs';

import './entry-form.css';

/**
 * Which errors of the client's validation an EntryForm shows: `touched`, those of the fields the
 * editor changed, so a new entry does not open full of errors, or `all`, every error with an
 * ErrorSummary above the form, as after the editor tried to save.
 *
 * @experimental
 */
export type EntryFormErrors = 'touched' | 'all';

/**
 * The props of EntryForm.
 *
 * @experimental
 */
export interface EntryFormProps {
  /** The type editor descriptor of the entry's type, as type.describe answers it. */
  readonly descriptor: TypeEditorV1;
  /** The entry's fields in the input form the type's validator reads; the form is controlled. */
  readonly value: JsonObject;
  /** Called with the fields after every change. */
  readonly onChange: (value: JsonObject) => void;
  /** The reader's locale, such as da or en-GB, which the descriptor's labels are shown in. */
  readonly locale: string;
  /** Which presence the client checks: `write`, the default, or `release`. */
  readonly stage?: EntryStage | undefined;
  /** Which errors of the client's validation are shown: `touched`, the default, or `all`. */
  readonly showErrors?: EntryFormErrors | undefined;
  /**
   * What the server said is wrong, from the caller's translations, by the path of the value in the
   * fields as the kernel's FieldPath writes it, with list indexes, such as `dimensions[1].width`,
   * or with the keys of the form's items. Each is shown at its field, before the client's own.
   */
  readonly errors?: Readonly<Record<string, string>> | undefined;
  /** The start of each control's id, `<idPrefix>-<path>`; one of the form's own if left out. */
  readonly idPrefix?: string | undefined;
  /** Whether the controls cannot be used, such as while the form submits. */
  readonly disabled?: boolean | undefined;
  /** The input of a base in place of the kit's, such as a rich text editor. */
  readonly inputs?: Partial<EntryFieldInputs> | undefined;
  /** The clock a new item's defaults of today and now are taken from; the system's by default. */
  readonly now?: (() => Date) | undefined;
}

/**
 * The keys of the items of each repeated group the editor changed in the form, by the path of its
 * list with the keys above it, such as `dimensions` or `sections[#k2].rows`.
 */
type KeyLists = ReadonlyMap<string, readonly string[]>;

/**
 * The keys of a list's items, one per item: the key the form gave it when the editor added or
 * moved it, else a key of its place, `i<index>`, which no key the form gives has.
 */
function keysOf(lists: KeyLists, list: string, length: number): readonly string[] {
  const known = lists.get(list) ?? [];

  return Array.from({ length }, (_item, index) => known[index] ?? `i${String(index)}`);
}

/** A key no item of the form has had: k and a number past every key the form gave. */
function freshKey(lists: KeyLists): string {
  let highest = 0;

  for (const keys of lists.values()) {
    for (const key of keys) {
      const number = /^k([0-9]+)$/.exec(key)?.[1];
      highest = Math.max(highest, number === undefined ? 0 : Number(number));
    }
  }

  return `k${String(highest + 1)}`;
}

/** The kit's text of each code of the client's issues. */
const ISSUE_TEXTS: Readonly<Record<IssueCode, KitTranslationKey>> = {
  validation_required: 'kit.entry_form.issue.validation_required',
  validation_wrong_type: 'kit.entry_form.issue.validation_wrong_type',
  validation_unknown_field: 'kit.entry_form.issue.validation_unknown_field',
  validation_too_short: 'kit.entry_form.issue.validation_too_short',
  validation_too_long: 'kit.entry_form.issue.validation_too_long',
  validation_invalid_format: 'kit.entry_form.issue.validation_invalid_format',
  validation_below_minimum: 'kit.entry_form.issue.validation_below_minimum',
  validation_above_maximum: 'kit.entry_form.issue.validation_above_maximum',
  validation_too_many_digits: 'kit.entry_form.issue.validation_too_many_digits',
  validation_not_an_option: 'kit.entry_form.issue.validation_not_an_option',
  validation_too_few_items: 'kit.entry_form.issue.validation_too_few_items',
  validation_too_many_items: 'kit.entry_form.issue.validation_too_many_items',
  validation_duplicate_item: 'kit.entry_form.issue.validation_duplicate_item',
  validation_invalid_rich_text: 'kit.entry_form.issue.validation_invalid_rich_text',
  validation_rich_text_not_allowed: 'kit.entry_form.issue.validation_rich_text_not_allowed',
  validation_hidden_field: 'kit.entry_form.issue.validation_hidden_field',
};

/** The kit's text of a client's issue. */
function issueText(t: KitTranslate, issue: EntryIssue): string {
  return t(ISSUE_TEXTS[issue.code]);
}

/**
 * The path with each list index of a repeated group's items written as the item's key, so an error
 * is shown at its item wherever the editor moves it; the indexes of other lists, such as the
 * choices of a multiple select, stay.
 */
function keyed(
  descriptor: TypeEditorV1,
  lists: KeyLists,
  segments: readonly EntryPathSegment[],
): EntryPathSegment[] {
  const done: EntryPathSegment[] = [];

  for (const segment of segments) {
    const field = fieldAt(descriptor, fieldPathOf(done));

    if (typeof segment === 'number' && field !== undefined && isRepeated(field)) {
      const key = keysOf(lists, entryPathText(done), segment + 1)[segment] ?? String(segment);
      done.push({ key });
    } else {
      done.push(segment);
    }
  }

  return done;
}

/** The descriptor's path of the field a value path is at: its names, without items. */
function fieldPathOf(segments: readonly EntryPathSegment[]): string {
  return segments.filter((segment): segment is string => typeof segment === 'string').join('.');
}

/** The path of the top-level field a value path is below: its handle, or ext.<namespace>.<handle>. */
function topLevelOf(segments: readonly EntryPathSegment[]): string {
  const names = segments.filter((segment): segment is string => typeof segment === 'string');

  return (names[0] === 'ext' ? names.slice(0, 3) : names.slice(0, 1)).join('.');
}

interface FormContext {
  readonly descriptor: TypeEditorV1;
  readonly locale: string;
  readonly stage: EntryStage;
  readonly idPrefix: string;
  readonly disabled: boolean;
  readonly inputs: EntryFieldInputs;
  readonly errors: ReadonlyMap<string, string>;
  readonly keys: KeyLists;
  readonly setKeys: (list: string, keys: readonly string[]) => void;
  readonly now: () => Date;
  readonly touch: (path: string) => void;
}

/**
 * A form rendered from a type editor descriptor (PRD 11.12, 14.1; GUARDRAILS 2.4), type.describe's
 * answer, so an entry of any type is edited without a form of its own: the layout's tabs, its
 * sections, collapsible where the blueprint says so, with their instructions, and its sidebar, each
 * field at the width its placement gives, labelled in the reader's locale. A leaf field is rendered
 * by the input of its base, an EntryFieldInput a caller can replace; a group is a fieldset of its
 * fields, and a repeated group a list of items the editor adds, removes and moves, each collapsed to
 * its summary template. A conditional field is shown while its condition holds, and while it does
 * not, a field marked when_hidden clear loses its value; the ui's hidden fields are not shown, and
 * its read-only ones cannot be changed. The form validates the fields on the client with the
 * generated validation runtime over the descriptor's rules, and shows each error at its field by a
 * path with the keys of the items, such as `dimensions[#k1].width`, with the server's errors before
 * its own. The form is controlled: newEntryFields() gives a new entry its defaults.
 *
 * @experimental
 */
export function EntryForm({
  descriptor,
  value,
  onChange,
  locale,
  stage = 'write',
  showErrors = 'touched',
  errors = {},
  idPrefix,
  disabled = false,
  inputs,
  now = () => new Date(),
}: EntryFormProps) {
  const t = useKitTranslation();
  const ownId = useId();
  const prefix = idPrefix ?? ownId;
  const [keys, setKeyLists] = useState<KeyLists>(() => new Map());
  const [touched, setTouched] = useState<ReadonlySet<string>>(() => new Set());
  const issues = useMemo(() => validateEntry(descriptor, value, stage), [descriptor, value, stage]);

  const shown = new Map<string, string>();

  for (const [written, message] of Object.entries(errors)) {
    const segments = parseEntryPath(written);
    shown.set(
      segments === null ? written : entryPathText(keyed(descriptor, keys, segments)),
      message,
    );
  }

  for (const issue of issues) {
    const segments = issue.path === null ? [] : (parseEntryPath(issue.path) ?? []);
    const path = entryPathText(keyed(descriptor, keys, segments));
    // An error is shown at a value the editor changed, and at a group or list the changed value
    // is in, such as a repeated group with too few items, but not yet at the other values of an
    // item the editor just added.
    const visible =
      showErrors === 'all' ||
      [...touched].some(
        (done) =>
          done === path ||
          (path !== '' && (done.startsWith(`${path}.`) || done.startsWith(`${path}[`))),
      );

    if (visible && !shown.has(path)) {
      shown.set(path, issueText(t, issue));
    }
  }

  const context: FormContext = {
    descriptor,
    locale,
    stage,
    idPrefix: prefix,
    disabled,
    inputs: { ...ENTRY_FIELD_INPUTS, ...inputs },
    errors: shown,
    keys,
    setKeys: (list, next) => {
      setKeyLists((current) => new Map([...current, [list, next]]));
    },
    now,
    touch: (path) => {
      setTouched((current) => (current.has(path) ? current : new Set([...current, path])));
    },
  };

  const change = (field: EditorFieldV1, next: JsonValue | undefined) => {
    onChange(clearHidden(descriptor, withValueAt(value, topLevelKeys(field), next)));
  };

  const tabs = descriptor.layout.tabs;
  const order = placementOrder(descriptor);
  const tabOf = tabOfField(descriptor);
  const listed = [...shown.entries()]
    .map(([path, message]) => {
      const segments = parseEntryPath(path) ?? [];
      const field = fieldAt(descriptor, fieldPathOf(segments));
      const label = field === undefined ? path : editorText(field.label, field.labels, locale);

      return {
        order: order.get(topLevelOf(segments)) ?? Number.MAX_SAFE_INTEGER,
        path,
        tab: tabOf.get(topLevelOf(segments)),
        item: {
          target: `${prefix}-${path}`,
          message: t('kit.entry_form.summary_item', { label, message }),
        },
      };
    })
    .sort((a, b) => a.order - b.order || (a.path < b.path ? -1 : a.path > b.path ? 1 : 0));
  const summary: readonly ErrorSummaryItem[] = listed.map((entry) => entry.item);
  const firstErrorTab = listed[0]?.tab;
  const [selected, setSelected] = useState(tabs[0]?.handle);
  // The tab of the first error while every error is shown, so the ErrorSummary's first link leads
  // to a field that is on the page; the editor's own choice of tab holds until that tab changes.
  const followed = showErrors === 'all' ? firstErrorTab : undefined;
  const [following, setFollowing] = useState<string | undefined>(undefined);

  if (followed !== following) {
    setFollowing(followed);

    if (followed !== undefined) {
      setSelected(followed);
    }
  }

  const sections = (list: readonly EditorSectionV1[], level: 2 | 3) =>
    list.map((section) => (
      <SectionView
        key={section.handle}
        section={section}
        level={level}
        root={value}
        context={context}
        onChange={change}
      />
    ));

  const errorsIn = (handle: string) => listed.filter((entry) => entry.tab === handle).length;

  return (
    <div className="cms-entry-form">
      {showErrors === 'all' && summary.length > 0 ? (
        <ErrorSummary
          title={t('kit.entry_form.errors', { count: summary.length })}
          errors={summary}
        />
      ) : null}
      <div
        className="cms-entry-form__layout"
        data-sidebar={descriptor.layout.sidebar.length > 0 ? 'true' : 'false'}
      >
        <div className="cms-entry-form__main">
          {tabs.length === 1 && tabs[0] !== undefined ? (
            sections(tabs[0].sections, 2)
          ) : (
            <Tabs
              label={t('kit.entry_form.tabs', {
                type: editorText(descriptor.label, descriptor.labels, locale),
              })}
              selected={selected}
              onChange={setSelected}
              tabs={tabs.map((tab) => {
                const label = editorText(tab.label, tab.labels, locale);
                const count = errorsIn(tab.handle);

                return {
                  id: tab.handle,
                  label: count === 0 ? label : t('kit.entry_form.tab_errors', { label, count }),
                  content: <div className="cms-entry-form__tab">{sections(tab.sections, 2)}</div>,
                };
              })}
            />
          )}
        </div>
        {descriptor.layout.sidebar.length > 0 ? (
          <aside className="cms-entry-form__sidebar" aria-label={t('kit.entry_form.sidebar')}>
            {sections(descriptor.layout.sidebar, 2)}
          </aside>
        ) : null}
      </div>
    </div>
  );
}

/** Where each top-level field is in the form: its place among the placements of tabs and sidebar. */
function placementOrder(descriptor: TypeEditorV1): ReadonlyMap<string, number> {
  const order = new Map<string, number>();

  for (const section of [
    ...descriptor.layout.tabs.flatMap((tab) => tab.sections),
    ...descriptor.layout.sidebar,
  ]) {
    for (const placement of section.fields) {
      order.set(placement.path, order.size);
    }
  }

  return order;
}

/** The tab of each top-level field placed in a tab; a field of the sidebar has none. */
function tabOfField(descriptor: TypeEditorV1): ReadonlyMap<string, string> {
  const tabs = new Map<string, string>();

  for (const tab of descriptor.layout.tabs) {
    for (const section of tab.sections) {
      for (const placement of section.fields) {
        tabs.set(placement.path, tab.handle);
      }
    }
  }

  return tabs;
}

/** The objects a top-level field's condition reads: the fields, and `ext` and its namespace for an extension field. */
function topLevelScopes(root: JsonObject, field: EditorFieldV1): readonly JsonObject[] {
  const holder = topLevelKeys(field).slice(0, -1);

  return [root, ...holder.map((_key, index) => objectAt(root, holder.slice(0, index + 1)))];
}

function SectionView({
  section,
  level,
  root,
  context,
  onChange,
}: {
  readonly section: EditorSectionV1;
  readonly level: 2 | 3;
  readonly root: JsonObject;
  readonly context: FormContext;
  readonly onChange: (field: EditorFieldV1, next: JsonValue | undefined) => void;
}) {
  const [open, setOpen] = useState(true);
  const id = useId();
  const label = optionalEditorText(section.label, section.labels, context.locale);
  const instructions = optionalEditorText(
    section.instructions,
    section.instructions_by_locale,
    context.locale,
  );
  const Heading = `h${String(level)}` as 'h2' | 'h3';
  const cells = section.fields.flatMap((placement) => {
    const field = fieldAt(context.descriptor, placement.path);

    if (field === undefined) {
      return [];
    }

    const scopes = topLevelScopes(root, field);

    if (field.ui.hidden || !isShown(field, scopes)) {
      return [];
    }

    const keys = topLevelKeys(field);

    return [
      <div key={placement.path} className="cms-entry-form__cell" data-width={placement.width}>
        <FieldView
          field={field}
          scopes={scopes}
          path={keys}
          value={valueAt(root, keys)}
          context={context}
          onChange={(next) => {
            onChange(field, next);
          }}
        />
      </div>,
    ];
  });

  return (
    <section
      className="cms-entry-form__section"
      aria-labelledby={label === undefined ? undefined : `${id}-title`}
      aria-label={label === undefined ? section.handle : undefined}
    >
      {label === undefined ? null : (
        <Heading id={`${id}-title`} className="cms-entry-form__section-title">
          {section.collapsible ? (
            <button
              type="button"
              className="cms-entry-form__toggle"
              aria-expanded={open}
              aria-controls={`${id}-body`}
              onClick={() => {
                setOpen(!open);
              }}
            >
              <Icon name={open ? 'chevron-down' : 'chevron-right'} />
              {label}
            </button>
          ) : (
            label
          )}
        </Heading>
      )}
      {instructions === undefined ? null : (
        <p className="cms-entry-form__instructions">{instructions}</p>
      )}
      <div id={`${id}-body`} className="cms-entry-form__grid" hidden={section.collapsible && !open}>
        {cells}
      </div>
    </section>
  );
}

interface FieldViewProps {
  readonly field: EditorFieldV1;
  /** The objects the field's condition reads, the object that holds the field last. */
  readonly scopes: readonly JsonObject[];
  /** The path of the value, with the keys of the items above it. */
  readonly path: readonly EntryPathSegment[];
  readonly value: JsonValue | undefined;
  readonly context: FormContext;
  readonly onChange: (next: JsonValue | undefined) => void;
}

function FieldView({ field, scopes, path, value, context, onChange }: FieldViewProps) {
  const name = entryPathText(path);
  const id = `${context.idPrefix}-${name}`;
  const label = editorText(field.label, field.labels, context.locale);
  const description =
    optionalEditorText(field.ui.instructions, field.ui.instructions_by_locale, context.locale) ??
    optionalEditorText(field.description, field.descriptions, context.locale);
  const error = context.errors.get(name);
  const changed = (next: JsonValue | undefined) => {
    context.touch(name);
    onChange(next);
  };

  if (field.base === 'group') {
    return field.repeat === null ? (
      <GroupView
        field={field}
        scopes={scopes}
        path={path}
        value={value}
        context={context}
        onChange={changed}
        id={id}
        label={label}
        description={description}
        error={error}
      />
    ) : (
      <RepeatedView
        field={field}
        scopes={scopes}
        path={path}
        value={value}
        context={context}
        onChange={changed}
        id={id}
        label={label}
        description={description}
        error={error}
      />
    );
  }

  const Input = context.inputs[field.base];
  const required =
    field.presence === 'required' ||
    (context.stage === 'release' && field.presence === 'required_on_release');

  return (
    <Input
      field={field}
      id={id}
      name={name}
      label={label}
      description={description}
      inputHint={optionalEditorText(
        field.ui.input_hint,
        field.ui.input_hint_by_locale,
        context.locale,
      )}
      error={error}
      required={required}
      readOnly={field.ui.read_only}
      disabled={context.disabled}
      locale={context.locale}
      value={value}
      onChange={changed}
    />
  );
}

/** The fields below a group in one of its objects: each shown one, at the full width. */
function FieldsOf({
  group,
  object,
  scopes,
  path,
  context,
  onChange,
}: {
  readonly group: EditorFieldV1;
  readonly object: JsonObject;
  readonly scopes: readonly JsonObject[];
  readonly path: readonly EntryPathSegment[];
  readonly context: FormContext;
  readonly onChange: (next: JsonObject) => void;
}): ReactNode {
  const inner = [...scopes, object];

  return (
    <div className="cms-entry-form__fields">
      {fieldsBelow(context.descriptor, group.path)
        .filter((field) => !field.ui.hidden && isShown(field, inner))
        .map((field) => (
          <FieldView
            key={field.handle}
            field={field}
            scopes={inner}
            path={[...path, field.handle]}
            value={Object.hasOwn(object, field.handle) ? object[field.handle] : undefined}
            context={context}
            onChange={(next) => {
              onChange(withValueAt(object, [field.handle], next));
            }}
          />
        ))}
    </div>
  );
}

interface ContainerProps extends FieldViewProps {
  readonly id: string;
  readonly label: string;
  readonly description: string | undefined;
  readonly error: string | undefined;
}

function GroupView({
  field,
  scopes,
  path,
  value,
  context,
  onChange,
  id,
  label,
  description,
  error,
}: ContainerProps) {
  const object = isObject(value) ? value : {};

  return (
    <Fieldset id={id} legend={label} description={description} error={error}>
      <FieldsOf
        group={field}
        object={object}
        scopes={scopes}
        path={path}
        context={context}
        onChange={(next) => {
          onChange(Object.keys(next).length === 0 ? undefined : next);
        }}
      />
    </Fieldset>
  );
}

/**
 * The text of an item's summary template, each `{handle}` replaced by the item's value of it, a
 * choice by its label; empty while the item has a value of none of the fields it names.
 */
function summaryOf(
  template: string,
  item: JsonObject,
  fields: readonly EditorFieldV1[],
  locale: string,
): string {
  const NAMED = /\{([a-z][a-z0-9_]*)\}/g;
  const shownOf = (handle: string): string => {
    const value = item[handle];
    const field = fields.find((candidate) => candidate.handle === handle);
    const choice = field?.choices.find((option) => option.value === value);

    if (choice !== undefined) {
      return editorText(choice.label, choice.labels, locale);
    }

    return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
      ? String(value)
      : '';
  };
  const filled = [...template.matchAll(NAMED)].some((match) => shownOf(match[1] ?? '') !== '');

  return filled ? template.replace(NAMED, (_match, handle: string) => shownOf(handle)).trim() : '';
}

function RepeatedView({
  field,
  scopes,
  path,
  value,
  context,
  onChange,
  id,
  label,
  description,
  error,
}: ContainerProps) {
  const t = useKitTranslation();
  const items: readonly JsonValue[] = Array.isArray(value) ? value : [];
  const list = entryPathText(path);
  const keys = keysOf(context.keys, list, items.length);
  const [collapsed, setCollapsed] = useState<ReadonlySet<string>>(() =>
    field.ui.summary === null ? new Set() : new Set(keys),
  );
  const below = fieldsBelow(context.descriptor, field.path);
  const max = field.repeat?.max_items ?? null;
  const changed = (next: readonly JsonValue[]) => {
    onChange(next.length === 0 ? undefined : [...next]);
  };

  return (
    <Fieldset id={id} legend={label} description={description} error={error}>
      <ol className="cms-entry-form__items">
        {items.length === 0 ? (
          <li className="cms-entry-form__empty">{t('kit.entry_form.empty')}</li>
        ) : null}
        {items.map((entry, index) => {
          const key = keys[index] ?? String(index);
          const item = isObject(entry) ? entry : {};
          const itemPath = [...path, { key }];
          const itemName = entryPathText(itemPath);
          const hasErrors = [...context.errors.keys()].some(
            (path) =>
              path === itemName ||
              path.startsWith(`${itemName}.`) ||
              path.startsWith(`${itemName}[`),
          );
          const summary =
            field.ui.summary === null
              ? ''
              : summaryOf(field.ui.summary, item, below, context.locale);
          const title =
            summary === '' ? t('kit.entry_form.item', { label, index: index + 1 }) : summary;
          const open = !collapsed.has(key) || hasErrors;

          return (
            <li key={key} className="cms-entry-form__item" data-key={key}>
              <div className="cms-entry-form__item-header">
                {field.ui.summary === null ? (
                  <span className="cms-entry-form__item-title">{title}</span>
                ) : (
                  <button
                    type="button"
                    className="cms-entry-form__toggle"
                    aria-expanded={open}
                    onClick={() => {
                      const next = new Set(collapsed);

                      if (open) {
                        next.add(key);
                      } else {
                        next.delete(key);
                      }

                      setCollapsed(next);
                    }}
                  >
                    <Icon name={open ? 'chevron-down' : 'chevron-right'} />
                    {title}
                  </button>
                )}
                <div className="cms-entry-form__item-actions">
                  <IconButton
                    type="button"
                    icon="chevron-up"
                    label={t('kit.entry_form.move_up', { item: title })}
                    disabled={context.disabled || index === 0}
                    onClick={() => {
                      context.setKeys(list, swapped([...keys], index, index - 1));
                      changed(swapped([...items], index, index - 1));
                    }}
                  />
                  <IconButton
                    type="button"
                    icon="chevron-down"
                    label={t('kit.entry_form.move_down', { item: title })}
                    disabled={context.disabled || index === items.length - 1}
                    onClick={() => {
                      context.setKeys(list, swapped([...keys], index, index + 1));
                      changed(swapped([...items], index, index + 1));
                    }}
                  />
                  <IconButton
                    type="button"
                    icon="close"
                    label={t('kit.entry_form.remove', { item: title })}
                    disabled={context.disabled}
                    onClick={() => {
                      context.setKeys(
                        list,
                        keys.filter((_key, at) => at !== index),
                      );
                      changed(items.filter((_entry, at) => at !== index));
                    }}
                  />
                </div>
              </div>
              {open ? (
                <FieldsOf
                  group={field}
                  object={item}
                  scopes={scopes}
                  path={itemPath}
                  context={context}
                  onChange={(next) => {
                    changed(items.map((current, at) => (at === index ? next : current)));
                  }}
                />
              ) : null}
            </li>
          );
        })}
      </ol>
      <div>
        <Button
          type="button"
          icon="plus"
          disabled={context.disabled || (max !== null && items.length >= max)}
          onClick={() => {
            context.setKeys(list, [...keys, freshKey(context.keys)]);
            changed([...items, newGroupValue(context.descriptor, field, context.now())]);
          }}
        >
          {t('kit.entry_form.add', { label })}
        </Button>
      </div>
    </Fieldset>
  );
}

/** The list with the items at the two places swapped. */
function swapped<T>(list: T[], from: number, to: number): T[] {
  const a = list[from];
  const b = list[to];

  if (a === undefined || b === undefined) {
    return list;
  }

  list[from] = b;
  list[to] = a;

  return list;
}
