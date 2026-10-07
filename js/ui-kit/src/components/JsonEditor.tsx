import { useId, useState } from 'react';

import { useKitTranslation } from '../i18n/translations';
import { describedBy, FieldDescription, FieldErrorText, FieldLabel } from './internal/FieldParts';

import './field.css';

/**
 * The props of JsonEditor.
 *
 * @experimental
 */
export interface JsonEditorProps {
  /** The text area's id, such as for an ErrorSummary's link to it; one of its own if left out. */
  readonly id?: string | undefined;
  /** The field's label, from the caller's translations. */
  readonly label: string;
  /** What the value must be, from the caller's translations. */
  readonly description?: string | undefined;
  /** The JSON text; the editor is controlled. */
  readonly value: string;
  /**
   * Called with the text on every change, and with the value it reads as, or undefined while it is
   * not JSON.
   */
  readonly onChange: (text: string, value: unknown) => void;
  /**
   * Checks a value that reads as JSON, such as with a generated validator, and returns what is
   * wrong with it, from the caller's translations: an empty list when nothing is.
   */
  readonly validate?: ((value: unknown) => readonly string[]) | undefined;
  /** What the server said is wrong, from the caller's translations, shown below the editor. */
  readonly error?: string | undefined;
  /** The name the value is submitted under in a form. */
  readonly name?: string | undefined;
  /** Whether a value is required; the label is marked in the kit's own text. */
  readonly required?: boolean | undefined;
  /** How many lines of text the editor shows; 8 by default. */
  readonly rows?: number | undefined;
}

/** Where the text stops reading as JSON, or null when it reads; and the value it reads as. */
function read(text: string): { readonly value: unknown; readonly failure: string | null } {
  if (text.trim() === '') {
    return { value: undefined, failure: null };
  }

  try {
    return { value: JSON.parse(text), failure: null };
  } catch (error) {
    return { value: undefined, failure: error instanceof Error ? error.message : String(error) };
  }
}

/**
 * A field for a JSON value, such as the fields of a revision: a monospaced text area that checks
 * the text as it is typed. While the text is not JSON, the editor says so in the kit's own text with
 * the parser's reason; once it is, the caller's validate function checks the value, and each of its
 * messages is shown below. Enter makes a new line and Tab leaves the editor, as in any text area.
 *
 * @experimental
 */
export function JsonEditor({
  id: given,
  label,
  description,
  value,
  onChange,
  validate,
  error,
  name,
  required = false,
  rows = 8,
}: JsonEditorProps) {
  const t = useKitTranslation();
  const generated = useId();
  const id = given ?? generated;
  const [touched, setTouched] = useState(false);
  const parsed = read(value);
  const problems =
    parsed.failure !== null
      ? [t('kit.json.invalid', { reason: parsed.failure })]
      : parsed.value === undefined || validate === undefined
        ? []
        : validate(parsed.value);
  const shown = [...(touched ? problems : []), ...(error === undefined ? [] : [error])];

  return (
    <div className="cms-field">
      <FieldLabel htmlFor={id} required={required}>
        {label}
      </FieldLabel>
      {description === undefined ? null : (
        <FieldDescription id={`${id}-description`}>{description}</FieldDescription>
      )}
      <textarea
        id={id}
        name={name}
        rows={rows}
        value={value}
        required={required}
        spellCheck={false}
        autoCapitalize="none"
        className="cms-input"
        data-monospace="true"
        aria-invalid={shown.length > 0 ? true : undefined}
        aria-describedby={describedBy(
          description !== undefined && `${id}-description`,
          shown.length > 0 && `${id}-problems`,
        )}
        onChange={(event) => {
          const text = event.target.value;
          setTouched(true);
          onChange(text, read(text).value);
        }}
        onBlur={() => {
          setTouched(true);
        }}
      />
      {shown.length === 0 ? null : (
        <div id={`${id}-problems`} className="cms-field__problems">
          {shown.map((problem) => (
            <FieldErrorText key={problem}>{problem}</FieldErrorText>
          ))}
        </div>
      )}
    </div>
  );
}
