import { SwitchButton, SwitchField } from 'react-aria-components/Switch';
import { Text } from 'react-aria-components/Text';

import { defined } from './internal/defined';

import './checkbox.css';

/**
 * The props of Switch.
 *
 * @experimental
 */
export interface SwitchProps {
  /** What the switch turns on, from the caller's translations. */
  readonly label: string;
  /** What turning it on does, below the label, from the caller's translations. */
  readonly description?: string | undefined;
  /** Whether the switch is on; with onChange, the switch is controlled. */
  readonly on?: boolean | undefined;
  /** Whether the switch starts on, when it is not controlled. */
  readonly defaultOn?: boolean | undefined;
  /** Called with whether the switch is on when the reader turns it. */
  readonly onChange?: ((on: boolean) => void) | undefined;
  /** The name the value is submitted under in a form. */
  readonly name?: string | undefined;
  /** The value submitted under the name while the switch is on; "on" by default. */
  readonly value?: string | undefined;
  /** Whether the control cannot be used; it is shown dimmed and the keyboard skips it. */
  readonly disabled?: boolean | undefined;
}

/**
 * A switch for a setting that takes effect at once, such as showing more detail: a native checkbox
 * input with the role switch, inside its label, so Space turns it on and off and a screen reader
 * says whether it is on. Its state is shown by the thumb's place as well as its colour.
 *
 * @experimental
 */
export function Switch({
  label,
  description,
  on,
  defaultOn,
  onChange,
  name,
  value,
  disabled = false,
}: SwitchProps) {
  return (
    <SwitchField
      {...defined({ isSelected: on, defaultSelected: defaultOn, onChange, name, value })}
      isDisabled={disabled}
      className="cms-choice"
    >
      <SwitchButton className="cms-choice__control">
        <span className="cms-switch__track" aria-hidden="true">
          <span className="cms-switch__thumb" />
        </span>
        <span className="cms-choice__label">{label}</span>
      </SwitchButton>
      {description === undefined ? null : (
        <Text slot="description" className="cms-choice__description">
          {description}
        </Text>
      )}
    </SwitchField>
  );
}
