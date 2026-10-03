import { FieldError } from 'react-aria-components/FieldError';
import { Label } from 'react-aria-components/Label';
import {
  RadioButton,
  RadioField,
  RadioGroup as AriaRadioGroup,
} from 'react-aria-components/RadioGroup';
import { Text } from 'react-aria-components/Text';

import { useKitTranslation } from '../i18n/translations';
import { defined } from './internal/defined';
import { Icon } from './Icon';

import './checkbox.css';
import './field.css';

/**
 * An option of a RadioGroup.
 *
 * @experimental
 */
export interface RadioOption {
  /** The value the group gives back and submits when the option is chosen. */
  readonly value: string;
  /** What the option is called, from the caller's translations. */
  readonly label: string;
  /** More about the option, below its label, from the caller's translations. */
  readonly description?: string | undefined;
  /** Whether the option cannot be chosen; the arrow keys skip it. */
  readonly disabled?: boolean | undefined;
}

/**
 * The props of RadioGroup.
 *
 * @experimental
 */
export interface RadioGroupProps {
  /** The question the options answer, from the caller's translations. */
  readonly label: string;
  /** What the value must be, shown below the label, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong with the choice, from the caller's translations, shown below the options. */
  readonly error?: string | undefined;
  /** The options, in the order they are shown. */
  readonly options: readonly RadioOption[];
  /** The chosen option's value, or null for none; with onChange, the group is controlled. */
  readonly value?: string | null | undefined;
  /** The value of the option chosen at first, when the group is not controlled. */
  readonly defaultValue?: string | null | undefined;
  /** Called with the value of the option chosen. */
  readonly onChange?: ((value: string) => void) | undefined;
  /** The name the value is submitted under in a form. */
  readonly name?: string | undefined;
  /** Whether an option must be chosen; the label is marked in the kit's own text. */
  readonly required?: boolean | undefined;
  /** Whether the control cannot be used; it is shown dimmed and the keyboard skips it. */
  readonly disabled?: boolean | undefined;
  /** vertical, the default, stacks the options; horizontal puts them in a row that wraps. */
  readonly orientation?: 'vertical' | 'horizontal';
}

/**
 * A choice of one of a few options, each shown: a radio group whose label names it. Tab reaches the
 * chosen option, or the first when none is chosen, and the arrow keys move between the options and
 * choose them, as the platform's radio buttons do; the group is one stop of the Tab order.
 *
 * @experimental
 */
export function RadioGroup({
  label,
  description,
  error,
  options,
  value,
  defaultValue,
  onChange,
  name,
  required = false,
  disabled = false,
  orientation = 'vertical',
}: RadioGroupProps) {
  const t = useKitTranslation();

  return (
    <AriaRadioGroup
      {...defined({ value, defaultValue, onChange, name })}
      isRequired={required}
      isDisabled={disabled}
      isInvalid={error !== undefined}
      validationBehavior="aria"
      orientation={orientation}
      className="cms-radio-group"
    >
      <Label className="cms-field__label">
        {label}
        {required ? (
          <span className="cms-field__required" aria-hidden="true">
            {t('kit.field.required')}
          </span>
        ) : null}
      </Label>
      {description === undefined ? null : (
        <Text slot="description" className="cms-field__description">
          {description}
        </Text>
      )}
      <div className="cms-radio-group__options">
        {options.map((option) => (
          <RadioField
            key={option.value}
            value={option.value}
            isDisabled={option.disabled === true}
            className="cms-choice"
          >
            <RadioButton className="cms-choice__control">
              <span className="cms-radio__dot" aria-hidden="true" />
              <span className="cms-choice__label">{option.label}</span>
            </RadioButton>
            {option.description === undefined ? null : (
              <Text slot="description" className="cms-choice__description">
                {option.description}
              </Text>
            )}
          </RadioField>
        ))}
      </div>
      <FieldError className="cms-field__error">
        <Icon name="error" size="sm" />
        <span>{error}</span>
      </FieldError>
    </AriaRadioGroup>
  );
}
