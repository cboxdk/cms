import { ToggleButton } from 'react-aria-components/ToggleButton';
import { ToggleButtonGroup } from 'react-aria-components/ToggleButtonGroup';
import type { Key } from 'react-stately';

import './segmented-control.css';

/**
 * A choice of a SegmentedControl.
 *
 * @experimental
 */
export interface SegmentedOption {
  /** An id unique among the choices, which onChange gives back. */
  readonly id: string;
  /** The choice's name, from the caller's translations. */
  readonly label: string;
}

/**
 * The props of SegmentedControl.
 *
 * @experimental
 */
export interface SegmentedControlProps {
  /** What the choices set, such as "Density", from the caller's translations. */
  readonly label: string;
  /** The choices, in the order they are shown; two to five read best. */
  readonly options: readonly SegmentedOption[];
  /** The id of the choice made. */
  readonly value: string;
  /** Called with the id of the choice the reader makes. */
  readonly onChange: (value: string) => void;
}

/**
 * A row of buttons of which one is pressed, for a setting of how something is shown, such as the
 * density of a list. It is a radio group named by its label, each button a radio: Tab reaches the
 * pressed button, Left and Right move between the buttons, and Enter or Space presses one, which
 * is told to a screen reader as the checked radio and shown by more than colour, the pressed
 * button's raised surface and border. A pressed button stays pressed when it is pressed again, so
 * one choice is always made.
 *
 * @experimental
 */
export function SegmentedControl({ label, options, value, onChange }: SegmentedControlProps) {
  return (
    <ToggleButtonGroup
      aria-label={label}
      selectionMode="single"
      disallowEmptySelection
      selectedKeys={[value]}
      onSelectionChange={(keys: Set<Key>) => {
        const [chosen] = [...keys];

        if (chosen !== undefined) {
          onChange(String(chosen));
        }
      }}
      className="cms-segmented-control"
    >
      {options.map((option) => (
        <ToggleButton key={option.id} id={option.id} className="cms-segmented-control__option">
          {option.label}
        </ToggleButton>
      ))}
    </ToggleButtonGroup>
  );
}
