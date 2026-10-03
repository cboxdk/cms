import { CheckboxButton, CheckboxField } from 'react-aria-components/Checkbox';
import { Text } from 'react-aria-components/Text';

import { defined } from './internal/defined';
import { Icon } from './Icon';

import './checkbox.css';
import './field.css';

/**
 * The props of Checkbox.
 *
 * @experimental
 */
export interface CheckboxProps {
  /** What the box says yes to, from the caller's translations. */
  readonly label: string;
  /** More about the choice, below the label, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong, from the caller's translations, shown below the box. */
  readonly error?: string | undefined;
  /** Whether the box is checked; with onChange, the box is controlled. */
  readonly checked?: boolean | undefined;
  /** Whether the box starts checked, when it is not controlled. */
  readonly defaultChecked?: boolean | undefined;
  /** Whether the box is shown as partly checked, as for a group whose boxes differ. */
  readonly indeterminate?: boolean | undefined;
  /** Called with whether the box is checked when the reader changes it. */
  readonly onChange?: ((checked: boolean) => void) | undefined;
  /** The name the value is submitted under in a form. */
  readonly name?: string | undefined;
  /** The value submitted under the name when the box is checked; "on" by default. */
  readonly value?: string | undefined;
  /** Whether the box must be checked; the label is marked in the kit's own text. */
  readonly required?: boolean | undefined;
  /** Whether the control cannot be used; it is shown dimmed and the keyboard skips it. */
  readonly disabled?: boolean | undefined;
}

/**
 * A check box with its label: a native checkbox input inside a label, so a click on the text
 * checks it, Space checks it with the keyboard, and a form submits it as the platform does. The
 * box is drawn by the kit, with a border in every colour scheme, and the input keeps its focus
 * ring around it.
 *
 * @experimental
 */
export function Checkbox({
  label,
  description,
  error,
  checked,
  defaultChecked,
  indeterminate,
  onChange,
  name,
  value,
  required = false,
  disabled = false,
}: CheckboxProps) {
  return (
    <CheckboxField
      {...defined({
        isSelected: checked,
        defaultSelected: defaultChecked,
        isIndeterminate: indeterminate,
        onChange,
        name,
        value,
      })}
      isRequired={required}
      isDisabled={disabled}
      isInvalid={error !== undefined}
      validationBehavior="aria"
      className="cms-choice"
    >
      <CheckboxButton className="cms-choice__control">
        {({ isSelected, isIndeterminate }) => (
          <>
            <span className="cms-checkbox__box" aria-hidden="true">
              {isIndeterminate ? (
                <Icon name="minus" size="sm" />
              ) : isSelected ? (
                <Icon name="check" size="sm" />
              ) : null}
            </span>
            <span className="cms-choice__label">{label}</span>
          </>
        )}
      </CheckboxButton>
      {description === undefined ? null : (
        <Text slot="description" className="cms-choice__description">
          {description}
        </Text>
      )}
      {error === undefined ? null : (
        <Text slot="errorMessage" className="cms-field__error">
          <Icon name="error" size="sm" />
          <span>{error}</span>
        </Text>
      )}
    </CheckboxField>
  );
}
