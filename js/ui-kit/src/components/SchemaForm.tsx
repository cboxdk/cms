import { useEffect, useId, useRef, useState, type ReactNode } from 'react';

import { useKitTranslation } from '../i18n/translations';
import { Button } from './Button';
import { Checkbox } from './Checkbox';
import { Fieldset } from './Fieldset';
import { IconButton } from './IconButton';
import { JsonEditor } from './JsonEditor';
import { NumberInput } from './NumberInput';
import {
  emptied,
  fieldPathText,
  initialDocument,
  type FieldPath,
  type FieldShape,
  type FormMember,
  type FormModel,
  type JsonObject,
  type JsonValue,
} from './schema-form/model';
import { Select } from './Select';
import { TextInput } from './TextInput';

import './schema-form.css';

/**
 * The texts of a SchemaForm's fields, from the caller's translations, each given the keys of the
 * member from the document to it, without list indexes, and what the schema says as the fallback.
 *
 * @experimental
 */
export interface SchemaFormTexts {
  /** The label of a member; the fallback is the schema's title, or the member's key. */
  readonly label: (keys: readonly string[], fallback: string) => string;
  /** The description of a member; the fallback is the schema's description, or none. */
  readonly description: (
    keys: readonly string[],
    fallback: string | undefined,
  ) => string | undefined;
  /** The text of one value of an enum member; the fallback is the value itself. */
  readonly option: (keys: readonly string[], value: string) => string;
}

/**
 * One field of a SchemaForm, as the caller's renderInput gets it beside the default input: the
 * member and its path, the value and how to change it, what the member is when its field is
 * emptied, and what the default input shows: its id and name, label, description and error,
 * whether it is required and whether it is disabled.
 *
 * @experimental
 */
export interface SchemaFormField {
  /** The path of the value in the document, as the kernel writes it, such as `window.live_from`. */
  readonly path: string;
  /** The keys of the path, without list indexes, which the texts are looked up by. */
  readonly keys: readonly string[];
  readonly member: FormMember;
  readonly value: JsonValue | undefined;
  /** Called with the member's value, or undefined to leave the key out. */
  readonly onChange: (next: JsonValue | undefined) => void;
  /** What the member is when its field is emptied: null, or undefined to leave the key out. */
  readonly emptied: JsonValue | undefined;
  /** The id of the control, `<idPrefix>-<path>`, which an ErrorSummary links to. */
  readonly id: string;
  /** The name the control is submitted under: the path. */
  readonly name: string;
  readonly label: string;
  readonly description: string | undefined;
  readonly error: string | undefined;
  readonly required: boolean;
  readonly disabled: boolean;
}

/**
 * The props of SchemaForm.
 *
 * @experimental
 */
export interface SchemaFormProps {
  /** The form, read from the command's JSON Schema with readCommandSchema(). */
  readonly model: FormModel;
  /** The document the form edits; the form is controlled. */
  readonly value: JsonObject;
  /** Called with the document after every change. */
  readonly onChange: (value: JsonObject) => void;
  /**
   * What is wrong with a value, from the caller's translations, by the path of the value in the
   * document as the kernel writes it, such as `window.live_from` or `slugs[0].slug`; '' is the
   * document itself. Each is shown at its field.
   */
  readonly errors?: Readonly<Record<string, string>> | undefined;
  /** The texts of the fields; the schema's own texts when left out. */
  readonly texts?: Partial<SchemaFormTexts> | undefined;
  /**
   * The start of each control's id, `<idPrefix>-<path>`, such as for an ErrorSummary's links; one
   * of the form's own if left out.
   */
  readonly idPrefix?: string | undefined;
  /**
   * Checks the value of a member that holds the fields of a revision, once it reads as JSON, such
   * as with the generated runtime's fields rule, and returns what is wrong with it, from the
   * caller's translations.
   */
  readonly checkJson?: ((value: unknown) => readonly string[]) | undefined;
  /** Whether the controls cannot be used, such as while the form submits. */
  readonly disabled?: boolean | undefined;
  /**
   * Renders a field's input in place of the default: given the field and the default input, it
   * returns what to render, the default input itself for a field it leaves alone. The panel hands
   * a field whose member is bound to a value class to the replacement point of the command form.
   */
  readonly renderInput?: ((field: SchemaFormField, input: ReactNode) => ReactNode) | undefined;
}

const DEFAULT_TEXTS: SchemaFormTexts = {
  label: (_keys, fallback) => fallback,
  description: (_keys, fallback) => fallback,
  option: (_keys, value) => value,
};

/** The keys of a path, without the list indexes, which the texts are looked up by. */
function keysOf(path: FieldPath): string[] {
  return path.filter((segment): segment is string => typeof segment === 'string');
}

function isRecord(value: unknown): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** A member of a list as the items are rendered: required, never null, with the item's shape. */
function itemMember(index: number, shape: FieldShape): FormMember {
  return {
    key: String(index),
    title: undefined,
    description: undefined,
    required: true,
    nullable: false,
    hasDefault: false,
    defaultValue: undefined,
    shape,
  };
}

interface FormContext {
  readonly texts: SchemaFormTexts;
  readonly errors: Readonly<Record<string, string>>;
  readonly idPrefix: string;
  readonly checkJson: ((value: unknown) => readonly string[]) | undefined;
  readonly disabled: boolean;
  readonly renderInput: ((field: SchemaFormField, input: ReactNode) => ReactNode) | undefined;
}

/**
 * A form rendered from a command's JSON Schema (GUARDRAILS 2.2, 8; PRD 6.1): a field per member of
 * the command's document, as the schema describes it, so any command the panel exposes can be run
 * with the keyboard without a page of its own. A string is a text field with the schema's example as
 * its hint, an integer a number field within its bounds, a boolean a check box, an enum a select,
 * a nested object a fieldset of its members, a list a fieldset of items with a button to add one and
 * one to remove each, and the fields of a revision a JSON editor the caller checks. A member that
 * may be null, or may be left out, is left out or null while its field is empty; a nullable object
 * or list has a check box that sets a value. The form is controlled: it renders the document it is
 * given and calls onChange with the document after every change, and the caller validates the
 * document, with the generated validator of the command, and gives the errors back by path. Labels
 * and descriptions come from the schema's title and description, or from the caller's texts. Each
 * control is named by the path of its value in the document, such as `window.live_from`, and has
 * the id `<idPrefix>-<path>`. The caller renders a field's input in place of the default with
 * renderInput, given the field and the default input.
 *
 * @experimental
 */
export function SchemaForm({
  model,
  value,
  onChange,
  errors = {},
  texts,
  idPrefix,
  checkJson,
  disabled = false,
  renderInput,
}: SchemaFormProps) {
  const ownId = useId();
  const context: FormContext = {
    texts: { ...DEFAULT_TEXTS, ...texts },
    errors,
    idPrefix: idPrefix ?? ownId,
    checkJson,
    disabled,
    renderInput,
  };

  return (
    <div className="cms-schema-form">
      <Members
        members={model.root.members}
        path={[]}
        value={value}
        onChange={onChange}
        context={context}
      />
    </div>
  );
}

function Members({
  members,
  path,
  value,
  onChange,
  context,
}: {
  readonly members: readonly FormMember[];
  readonly path: FieldPath;
  readonly value: JsonObject;
  readonly onChange: (value: JsonObject) => void;
  readonly context: FormContext;
}) {
  function set(key: string, next: JsonValue | undefined): void {
    const copy: Record<string, JsonValue> = Object.fromEntries(
      Object.entries(value).filter(([current]) => current !== key),
    );

    if (next !== undefined) {
      copy[key] = next;
    }

    onChange(copy);
  }

  return (
    <>
      {members.map((member) => (
        <MemberField
          key={member.key}
          member={member}
          path={[...path, member.key]}
          value={value[member.key]}
          onChange={(next) => {
            set(member.key, next);
          }}
          context={context}
        />
      ))}
    </>
  );
}

interface MemberFieldProps {
  readonly member: FormMember;
  readonly path: FieldPath;
  readonly value: JsonValue | undefined;
  /** Called with the member's value, or undefined to leave the key out. */
  readonly onChange: (next: JsonValue | undefined) => void;
  readonly context: FormContext;
  /** The label, when the caller names it, such as an item of a list. */
  readonly label?: string | undefined;
}

function MemberField({ member, path, value, onChange, context, label }: MemberFieldProps) {
  const keys = keysOf(path);
  const text = label ?? context.texts.label(keys, member.title ?? member.key);
  const description = context.texts.description(keys, member.description);
  const name = fieldPathText(path);
  const error = context.errors[name];
  const id = `${context.idPrefix}-${name}`;
  const required = member.required && !member.nullable;
  const input = (
    <DefaultInput
      member={member}
      path={path}
      value={value}
      onChange={onChange}
      context={context}
      keys={keys}
      text={text}
      description={description}
      name={name}
      error={error}
      id={id}
      required={required}
    />
  );

  if (context.renderInput === undefined) {
    return input;
  }

  return (
    <>
      {context.renderInput(
        {
          path: name,
          keys,
          member,
          value,
          onChange,
          emptied: emptied(member),
          id,
          name,
          label: text,
          description,
          error,
          required,
          disabled: context.disabled,
        },
        input,
      )}
    </>
  );
}

function DefaultInput({
  member,
  path,
  value,
  onChange,
  context,
  keys,
  text,
  description,
  name,
  error,
  id,
  required,
}: MemberFieldProps & {
  readonly keys: readonly string[];
  readonly text: string;
  readonly description: string | undefined;
  readonly name: string;
  readonly error: string | undefined;
  readonly id: string;
  readonly required: boolean;
}) {
  const shape = member.shape;

  switch (shape.kind) {
    case 'string':
      return (
        <TextInput
          id={id}
          name={name}
          label={text}
          description={description}
          error={error}
          required={required}
          disabled={context.disabled}
          value={typeof value === 'string' ? value : ''}
          maxLength={shape.maxLength}
          placeholder={shape.examples[0]}
          autoComplete="off"
          spellCheck={shape.pattern === undefined && shape.format === undefined}
          onChange={(event) => {
            onChange(event.target.value === '' ? emptied(member) : event.target.value);
          }}
        />
      );
    case 'integer':
      return (
        <NumberInput
          id={id}
          name={name}
          label={text}
          description={description}
          error={error}
          required={required}
          disabled={context.disabled}
          value={typeof value === 'number' ? value : null}
          minValue={shape.minimum}
          maxValue={shape.maximum}
          onChange={(next) => {
            onChange(next === null ? emptied(member) : next);
          }}
        />
      );
    case 'boolean':
      return (
        <Checkbox
          name={name}
          label={text}
          description={description}
          error={error}
          disabled={context.disabled}
          checked={value === true}
          onChange={onChange}
        />
      );
    case 'enum':
      return (
        <Select
          id={id}
          name={name}
          label={text}
          description={description}
          error={error}
          required={required}
          disabled={context.disabled}
          options={shape.values.map((option) => ({
            id: option,
            label: context.texts.option(keys, option),
          }))}
          value={typeof value === 'string' ? value : null}
          onChange={onChange}
        />
      );
    case 'fields':
      return (
        <JsonMember
          id={id}
          name={name}
          label={text}
          description={description}
          error={error}
          required={required}
          value={value}
          onChange={onChange}
          context={context}
        />
      );
    case 'object':
      return (
        <ObjectMember
          id={id}
          member={member}
          members={shape.members}
          label={text}
          description={description}
          error={error}
          path={path}
          value={value}
          onChange={onChange}
          context={context}
        />
      );
    case 'list':
      return (
        <ListMember
          id={id}
          member={member}
          item={shape.item}
          label={text}
          description={description}
          error={error}
          path={path}
          value={value}
          onChange={onChange}
          context={context}
        />
      );
  }
}

function JsonMember({
  id,
  name,
  label,
  description,
  error,
  required,
  value,
  onChange,
  context,
}: {
  readonly id: string;
  readonly name: string;
  readonly label: string;
  readonly description: string | undefined;
  readonly error: string | undefined;
  readonly required: boolean;
  readonly value: JsonValue | undefined;
  readonly onChange: (next: JsonValue | undefined) => void;
  readonly context: FormContext;
}) {
  const [text, setText] = useState(() => printed(value));
  // The value the text was last read into, so a value set from outside the editor, such as by a
  // step of the form's flow that patched the document, shows in it, while what is typed does not
  // print again.
  const read = useRef<JsonValue | undefined>(value);

  useEffect(() => {
    if (JSON.stringify(value) !== JSON.stringify(read.current)) {
      read.current = value;
      setText(printed(value));
    }
  }, [value]);

  return (
    <JsonEditor
      id={id}
      name={name}
      label={label}
      description={description}
      error={error}
      required={required}
      value={text}
      validate={context.checkJson}
      onChange={(next, parsed) => {
        const document = parsed === undefined || !isRecord(parsed) ? undefined : parsed;
        read.current = document;
        setText(next);
        onChange(document);
      }}
    />
  );
}

/** The value as the editor prints it: pretty JSON, or nothing for a value that is left out. */
function printed(value: JsonValue | undefined): string {
  return value === undefined ? '' : JSON.stringify(value, null, 2);
}

/** The check box of a nullable object or list: set, or null or left out. */
function SetValue({
  on,
  onChange,
  disabled,
}: {
  readonly on: boolean;
  readonly onChange: (on: boolean) => void;
  readonly disabled: boolean;
}) {
  const t = useKitTranslation();

  return (
    <Checkbox
      label={t('kit.schema_form.set')}
      checked={on}
      onChange={onChange}
      disabled={disabled}
    />
  );
}

function ObjectMember({
  id,
  member,
  members,
  label,
  description,
  error,
  path,
  value,
  onChange,
  context,
}: {
  readonly id: string;
  readonly member: FormMember;
  readonly members: readonly FormMember[];
  readonly label: string;
  readonly description: string | undefined;
  readonly error: string | undefined;
  readonly path: FieldPath;
  readonly value: JsonValue | undefined;
  readonly onChange: (next: JsonValue | undefined) => void;
  readonly context: FormContext;
}) {
  const set = isRecord(value);
  const toggle: ReactNode = member.nullable ? (
    <SetValue
      on={set}
      disabled={context.disabled}
      onChange={(on) => {
        onChange(on ? initialDocument({ kind: 'object', members }) : emptied(member));
      }}
    />
  ) : null;

  return (
    <Fieldset id={id} legend={label} description={description} error={error}>
      {toggle}
      {set || !member.nullable ? (
        <Members
          members={members}
          path={path}
          value={set ? value : {}}
          onChange={onChange}
          context={context}
        />
      ) : null}
    </Fieldset>
  );
}

function ListMember({
  id,
  member,
  item,
  label,
  description,
  error,
  path,
  value,
  onChange,
  context,
}: {
  readonly id: string;
  readonly member: FormMember;
  readonly item: FieldShape;
  readonly label: string;
  readonly description: string | undefined;
  readonly error: string | undefined;
  readonly path: FieldPath;
  readonly value: JsonValue | undefined;
  readonly onChange: (next: JsonValue | undefined) => void;
  readonly context: FormContext;
}) {
  const t = useKitTranslation();
  const items: readonly JsonValue[] = Array.isArray(value) ? value : [];
  const set = Array.isArray(value);
  const shape = member.shape;
  const full =
    shape.kind === 'list' && shape.maxItems !== undefined && items.length >= shape.maxItems;

  /** The list after a change: left out or null when it is empty and may be, else as it is. */
  function changed(next: readonly JsonValue[]): void {
    onChange(next.length === 0 && (!member.required || member.nullable) ? emptied(member) : next);
  }

  function added(): JsonValue {
    switch (item.kind) {
      case 'object':
        return initialDocument(item);
      case 'list':
        return [];
      case 'boolean':
        return false;
      case 'integer':
        return item.minimum ?? 0;
      case 'enum':
        return item.values[0] ?? '';
      case 'fields':
        return {};
      case 'string':
        return '';
    }
  }

  return (
    <Fieldset id={id} legend={label} description={description} error={error}>
      {member.nullable ? (
        <SetValue
          on={set}
          disabled={context.disabled}
          onChange={(on) => {
            onChange(on ? [] : emptied(member));
          }}
        />
      ) : null}
      {set || !member.nullable ? (
        <div className="cms-schema-form__list">
          {items.length === 0 ? (
            <p className="cms-schema-form__empty">{t('kit.schema_form.empty')}</p>
          ) : null}
          {items.map((entry, index) => {
            const itemLabel = t('kit.schema_form.item', { label, index: index + 1 });

            return (
              <div key={index} className="cms-schema-form__item">
                <div className="cms-schema-form__item-field">
                  <MemberField
                    member={itemMember(index, item)}
                    path={[...path, index]}
                    value={entry}
                    label={itemLabel}
                    onChange={(next) => {
                      changed(
                        next === undefined
                          ? items.filter((_entry, at) => at !== index)
                          : items.map((current, at) => (at === index ? next : current)),
                      );
                    }}
                    context={context}
                  />
                </div>
                <IconButton
                  type="button"
                  icon="close"
                  label={t('kit.schema_form.remove', { label: itemLabel })}
                  disabled={context.disabled}
                  onClick={() => {
                    changed(items.filter((_entry, at) => at !== index));
                  }}
                />
              </div>
            );
          })}
          <div>
            <Button
              type="button"
              icon="plus"
              disabled={context.disabled || full}
              onClick={() => {
                changed([...items, added()]);
              }}
            >
              {t('kit.schema_form.add', { label })}
            </Button>
          </div>
        </div>
      ) : null}
    </Fieldset>
  );
}
