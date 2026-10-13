// The inputs of an entry form, one per base of a field (PRD 11.12, 13.3): the core field type
// whose form a field's value takes, so an addon's field type is edited by the input of its base.
// Each is an EntryFieldInput, the contract EntryForm renders a leaf field through: it gets the
// field, the value and how to change it, and the texts and state of its control, and renders the
// control in the kit's own components. A caller swaps the input of a base through EntryForm's
// inputs, as the rich text editor (B11) replaces the JSON editor of rich text here.

import { useEffect, useRef, useState, type ReactNode } from 'react';

import type { EditorBase, EditorFieldV1 } from '../../generated/protocol/TypeEditorV1';
import { useKitTranslation } from '../../i18n/translations';
import { Checkbox } from '../Checkbox';
import { Field } from '../Field';
import { Fieldset } from '../Fieldset';
import { JsonEditor } from '../JsonEditor';
import { MultiSelect } from '../MultiSelect';
import { NumberInput } from '../NumberInput';
import { RadioGroup } from '../RadioGroup';
import type { JsonValue } from '../schema-form/model';
import { Select } from '../Select';
import { TextArea } from '../TextArea';
import { TextInput } from '../TextInput';
import { editorText } from './model';

/**
 * What an input of an entry form gets: the field as the descriptor has it, its value and how to
 * change it, and its control's id, name, texts in the reader's locale and state.
 *
 * @experimental
 */
export interface EntryFieldInputProps {
  /** The field, as the type editor descriptor has it. */
  readonly field: EditorFieldV1;
  /** The id of the control, `<idPrefix>-<path>`, which the form's ErrorSummary links to. */
  readonly id: string;
  /** The path of the value, with the keys of the items of repeated groups, such as `dimensions[#k1].width`. */
  readonly name: string;
  /** The field's label in the reader's locale. */
  readonly label: string;
  /** The field's instructions, or else its description, in the reader's locale. */
  readonly description: string | undefined;
  /** The hint the empty control shows, in the reader's locale. */
  readonly inputHint: string | undefined;
  /** What is wrong with the value, from the kit's or the caller's texts. */
  readonly error: string | undefined;
  /** Whether the field needs a value now. */
  readonly required: boolean;
  /** Whether the form shows the value without letting the editor change it. */
  readonly readOnly: boolean;
  /** Whether the control cannot be used, such as while the form submits. */
  readonly disabled: boolean;
  /** The reader's locale, which the labels of a select's choices are in. */
  readonly locale: string;
  /** The value, in the record's JSON form, or undefined when the field has none. */
  readonly value: JsonValue | undefined;
  /** Called with the new value, or undefined to leave the field without one. */
  readonly onChange: (next: JsonValue | undefined) => void;
}

/**
 * The contract of an input of an entry form: a component that renders a leaf field's control from
 * EntryFieldInputProps.
 *
 * @experimental
 */
export type EntryFieldInput = (props: EntryFieldInputProps) => ReactNode;

/**
 * A base of a field that an input renders: every base but group, whose fields the form renders
 * itself.
 *
 * @experimental
 */
export type EntryLeafBase = Exclude<EditorBase, 'group'>;

/**
 * The input of each base an entry form renders a leaf field through.
 *
 * @experimental
 */
export type EntryFieldInputs = { readonly [Base in EntryLeafBase]: EntryFieldInput };

/** The choices of a select field in the reader's locale. */
function choicesOf(field: EditorFieldV1, locale: string): readonly { id: string; label: string }[] {
  return field.choices.map((choice) => ({
    id: choice.value,
    label: editorText(choice.label, choice.labels, locale),
  }));
}

/** The format of a text field's value rule: email, url, or none for plain text. */
function textFormat(field: EditorFieldV1): 'email' | 'url' | undefined {
  const format = field.rule?.['format'];

  return format === 'email' || format === 'url' ? format : undefined;
}

/** The bound of a value rule, when it is a number. */
function numberBound(field: EditorFieldV1, key: 'min' | 'max'): number | undefined {
  const bound = field.rule?.[key];

  return typeof bound === 'number' && Number.isSafeInteger(bound) ? bound : undefined;
}

/** The most characters of a text field's value rule, when it has one. */
function maxLength(field: EditorFieldV1): number | undefined {
  const length = field.rule?.['maxLength'];

  return typeof length === 'number' ? length : undefined;
}

/** Text, an email address or a URL: one line, its keyboard by the format of the value rule. */
function TextEntryInput(props: EntryFieldInputProps) {
  const format = textFormat(props.field);

  return (
    <TextInput
      id={props.id}
      name={props.name}
      label={props.label}
      description={props.description}
      error={props.error}
      required={props.required}
      readOnly={props.readOnly}
      disabled={props.disabled}
      type={format ?? 'text'}
      placeholder={props.inputHint}
      maxLength={maxLength(props.field)}
      autoComplete="off"
      spellCheck={format === undefined}
      value={typeof props.value === 'string' ? props.value : ''}
      onChange={(event) => {
        props.onChange(event.target.value === '' ? undefined : event.target.value);
      }}
    />
  );
}

/** Long text: lines of text in a text area. */
function LongTextEntryInput(props: EntryFieldInputProps) {
  return (
    <TextArea
      id={props.id}
      name={props.name}
      label={props.label}
      description={props.description}
      error={props.error}
      required={props.required}
      readOnly={props.readOnly}
      disabled={props.disabled}
      placeholder={props.inputHint}
      maxLength={maxLength(props.field)}
      rows={5}
      value={typeof props.value === 'string' ? props.value : ''}
      onChange={(event) => {
        props.onChange(event.target.value === '' ? undefined : event.target.value);
      }}
    />
  );
}

/** A whole number within the bounds of the value rule. */
function IntegerEntryInput(props: EntryFieldInputProps) {
  return (
    <NumberInput
      id={props.id}
      name={props.name}
      label={props.label}
      description={props.description}
      error={props.error}
      required={props.required}
      disabled={props.disabled || props.readOnly}
      minValue={numberBound(props.field, 'min')}
      maxValue={numberBound(props.field, 'max')}
      value={typeof props.value === 'number' ? props.value : null}
      onChange={(next) => {
        props.onChange(next ?? undefined);
      }}
    />
  );
}

/**
 * A decimal number, written as the record holds it: a string, such as 12.50, so no digit is lost
 * to a floating point number.
 */
function DecimalEntryInput(props: EntryFieldInputProps) {
  return (
    <TextInput
      id={props.id}
      name={props.name}
      label={props.label}
      description={props.description}
      error={props.error}
      required={props.required}
      readOnly={props.readOnly}
      disabled={props.disabled}
      inputMode="decimal"
      placeholder={props.inputHint}
      autoComplete="off"
      spellCheck={false}
      value={typeof props.value === 'string' ? props.value : ''}
      onChange={(event) => {
        const text = event.target.value.trim();
        props.onChange(text === '' ? undefined : text);
      }}
    />
  );
}

/** Yes or no: a check box, which leaves the field without a value only through its default. */
function BooleanEntryInput(props: EntryFieldInputProps) {
  return (
    <Checkbox
      name={props.name}
      label={props.label}
      description={props.description}
      error={props.error}
      disabled={props.disabled || props.readOnly}
      checked={props.value === true}
      onChange={(checked) => {
        props.onChange(checked);
      }}
    />
  );
}

/** A date, YYYY-MM-DD, in the browser's date control. */
function DateEntryInput(props: EntryFieldInputProps) {
  const min = props.field.rule?.['min'];
  const max = props.field.rule?.['max'];

  return (
    <Field
      id={props.id}
      label={props.label}
      description={props.description}
      error={props.error}
      required={props.required}
    >
      {(control) => (
        <input
          {...control}
          className="cms-input"
          type="date"
          name={props.name}
          readOnly={props.readOnly}
          disabled={props.disabled}
          min={typeof min === 'string' ? min : undefined}
          max={typeof max === 'string' ? max : undefined}
          value={typeof props.value === 'string' ? props.value : ''}
          onChange={(event) => {
            props.onChange(event.target.value === '' ? undefined : event.target.value);
          }}
        />
      )}
    </Field>
  );
}

function twoDigits(value: number): string {
  return String(value).padStart(2, '0');
}

/**
 * The value of the browser's local date and time control for a date-time of RFC 3339: the instant
 * in the reader's time zone, to the second; empty for a value that is not one.
 */
export function localControlValue(value: JsonValue | undefined): string {
  if (typeof value !== 'string') {
    return '';
  }

  const instant = new Date(value);

  if (Number.isNaN(instant.getTime())) {
    return '';
  }

  return `${String(instant.getFullYear()).padStart(4, '0')}-${twoDigits(instant.getMonth() + 1)}-${twoDigits(instant.getDate())}T${twoDigits(instant.getHours())}:${twoDigits(instant.getMinutes())}:${twoDigits(instant.getSeconds())}`;
}

/**
 * The date-time of RFC 3339 for a value of the browser's local date and time control: that local
 * time with the reader's offset at it, such as 2026-10-13T09:30:00+02:00; undefined when empty.
 */
export function fromLocalControlValue(text: string): string | undefined {
  if (text === '') {
    return undefined;
  }

  const local = text.length === 16 ? `${text}:00` : text;
  const instant = new Date(local);

  if (Number.isNaN(instant.getTime())) {
    return undefined;
  }

  const offset = -instant.getTimezoneOffset();
  const minutes = Math.abs(offset);

  return `${local}${offset === 0 ? 'Z' : `${offset < 0 ? '-' : '+'}${twoDigits(Math.floor(minutes / 60))}:${twoDigits(minutes % 60)}`}`;
}

/** A date and a time, in the browser's local date and time control, stored with the offset. */
function DatetimeEntryInput(props: EntryFieldInputProps) {
  return (
    <Field
      id={props.id}
      label={props.label}
      description={props.description}
      error={props.error}
      required={props.required}
    >
      {(control) => (
        <input
          {...control}
          className="cms-input"
          type="datetime-local"
          step={1}
          name={props.name}
          readOnly={props.readOnly}
          disabled={props.disabled}
          value={localControlValue(props.value)}
          onChange={(event) => {
            props.onChange(fromLocalControlValue(event.target.value));
          }}
        />
      )}
    </Field>
  );
}

/** At most this many choices are shown as radio buttons or check boxes when the ui leaves it open. */
const FEW_CHOICES = 4;

/**
 * One choice or several, as the field's ui display says: radio, buttons (the choices in a row) or
 * dropdown; a field whose ui leaves it open shows a few choices at once and more in a dropdown.
 * Several choices are check boxes or a multiple select.
 */
function SelectEntryInput(props: EntryFieldInputProps) {
  const t = useKitTranslation();
  const choices = choicesOf(props.field, props.locale);
  const display = props.field.ui.display ?? (choices.length <= FEW_CHOICES ? 'radio' : 'dropdown');
  const disabled = props.disabled || props.readOnly;

  if (props.field.rule?.['kind'] === 'list') {
    const chosen = Array.isArray(props.value)
      ? props.value.filter((item): item is string => typeof item === 'string')
      : [];
    const changed = (next: readonly string[]) => {
      props.onChange(next.length === 0 ? undefined : [...next]);
    };

    if (display === 'dropdown') {
      return (
        <MultiSelect
          name={props.name}
          label={props.label}
          description={props.description}
          error={props.error}
          required={props.required}
          disabled={disabled}
          options={choices}
          noneLabel={t('kit.entry_form.none')}
          value={chosen}
          onChange={changed}
        />
      );
    }

    return (
      <Fieldset
        id={props.id}
        legend={props.label}
        description={props.description}
        error={props.error}
      >
        <div className="cms-entry-form__choices" data-display={display}>
          {choices.map((choice) => (
            <Checkbox
              key={choice.id}
              name={props.name}
              value={choice.id}
              label={choice.label}
              disabled={disabled}
              checked={chosen.includes(choice.id)}
              onChange={(checked) => {
                changed(
                  choices
                    .map((option) => option.id)
                    .filter((id) => (id === choice.id ? checked : chosen.includes(id))),
                );
              }}
            />
          ))}
        </div>
      </Fieldset>
    );
  }

  const value = typeof props.value === 'string' ? props.value : null;

  if (display === 'dropdown') {
    return (
      <Select
        id={props.id}
        name={props.name}
        label={props.label}
        description={props.description}
        error={props.error}
        required={props.required}
        disabled={disabled}
        options={choices}
        value={value}
        onChange={props.onChange}
      />
    );
  }

  return (
    <RadioGroup
      name={props.name}
      label={props.label}
      description={props.description}
      error={props.error}
      required={props.required}
      disabled={disabled}
      orientation={display === 'buttons' ? 'horizontal' : 'vertical'}
      options={choices.map((choice) => ({ value: choice.id, label: choice.label }))}
      value={value}
      onChange={props.onChange}
    />
  );
}

/** The value as the editor prints it: pretty JSON, or nothing for a field without one. */
function printed(value: JsonValue | undefined): string {
  return value === undefined ? '' : JSON.stringify(value, null, 2);
}

/**
 * Rich text, Portable Text blocks, edited as JSON until the rich text editor (B11) takes the base's
 * place behind the same contract.
 */
function RichTextEntryInput(props: EntryFieldInputProps) {
  const [text, setText] = useState(() => printed(props.value));
  // The value the text was last read into, so a value set from outside the editor shows in it,
  // while what is typed does not print again.
  const read = useRef<JsonValue | undefined>(props.value);

  useEffect(() => {
    if (JSON.stringify(props.value) !== JSON.stringify(read.current)) {
      read.current = props.value;
      setText(printed(props.value));
    }
  }, [props.value]);

  if (props.readOnly || props.disabled) {
    return (
      <TextArea
        id={props.id}
        name={props.name}
        label={props.label}
        description={props.description}
        error={props.error}
        readOnly
        monospace
        rows={8}
        value={text}
      />
    );
  }

  return (
    <JsonEditor
      id={props.id}
      name={props.name}
      label={props.label}
      description={props.description}
      error={props.error}
      required={props.required}
      value={text}
      onChange={(next, parsed) => {
        const value = next.trim() === '' ? undefined : (parsed as JsonValue | undefined);
        read.current = value;
        setText(next);

        if (next.trim() === '' || parsed !== undefined) {
          props.onChange(value);
        }
      }}
    />
  );
}

/**
 * The kit's input of each base: text, email and URL fields as one line, long text as a text area,
 * integers as a number field within their bounds, decimals as text, booleans as a check box, dates
 * and date-times in the browser's controls, selects as radio buttons, buttons, a dropdown or check
 * boxes by their ui display, and rich text as JSON.
 *
 * @experimental
 */
export const ENTRY_FIELD_INPUTS: EntryFieldInputs = {
  text: TextEntryInput,
  long_text: LongTextEntryInput,
  integer: IntegerEntryInput,
  decimal: DecimalEntryInput,
  boolean: BooleanEntryInput,
  date: DateEntryInput,
  datetime: DatetimeEntryInput,
  select: SelectEntryInput,
  rich_text: RichTextEntryInput,
};
