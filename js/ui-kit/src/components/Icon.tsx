import './icon.css';

/**
 * The name of an icon of the kit.
 *
 * @experimental
 */
export type IconName =
  | 'check'
  | 'chevron-down'
  | 'chevron-left'
  | 'chevron-right'
  | 'chevron-up'
  | 'close'
  | 'copy'
  | 'error'
  | 'eye'
  | 'eye-off'
  | 'info'
  | 'menu'
  | 'minus'
  | 'more'
  | 'plus'
  | 'search'
  | 'sort-ascending'
  | 'sort-descending'
  | 'success'
  | 'warning';

/**
 * The strokes of each icon, drawn on a 24 by 24 grid with round caps, in the current text colour,
 * so an icon follows the tone of the text it stands by and the system colours in forced colours.
 */
const PATHS: Readonly<Record<IconName, readonly string[]>> = {
  check: ['M5 12.5l4.5 4.5L19 7.5'],
  'chevron-down': ['M6 9l6 6 6-6'],
  'chevron-left': ['M15 6l-6 6 6 6'],
  'chevron-right': ['M9 6l6 6-6 6'],
  'chevron-up': ['M6 15l6-6 6 6'],
  close: ['M6 6l12 12', 'M18 6L6 18'],
  copy: ['M9 9h10v10H9z', 'M5 15V5h10'],
  error: ['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z', 'M9 9l6 6', 'M15 9l-6 6'],
  eye: ['M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z', 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z'],
  'eye-off': [
    'M3 3l18 18',
    'M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3 3.9',
    'M6.6 6.6C3.7 8.4 2 12 2 12s3.5 7 10 7a9.6 9.6 0 0 0 5.4-1.6',
    'M9.9 9.9a3 3 0 0 0 4.2 4.2',
  ],
  info: ['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z', 'M12 11v5', 'M12 8h.01'],
  menu: ['M4 7h16', 'M4 12h16', 'M4 17h16'],
  minus: ['M5 12h14'],
  more: ['M5 12h.01', 'M12 12h.01', 'M19 12h.01'],
  plus: ['M12 5v14', 'M5 12h14'],
  search: ['M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14z', 'M20 20l-4-4'],
  'sort-ascending': ['M12 19V5', 'M6 11l6-6 6 6'],
  'sort-descending': ['M12 5v14', 'M6 13l6 6 6-6'],
  success: ['M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z', 'M8 12.5l2.5 2.5L16 9.5'],
  warning: ['M12 3.5L2.5 20h19z', 'M12 10v4', 'M12 17h.01'],
};

/**
 * The size of an Icon.
 *
 * @experimental
 */
export type IconSize = 'sm' | 'md';

/**
 * The props of Icon.
 *
 * @experimental
 */
export interface IconProps {
  /** Which icon to draw. */
  readonly name: IconName;
  /** sm is the height of small text; md, the default, the height of body text. */
  readonly size?: IconSize;
}

/**
 * An icon of the kit. It is decoration only, hidden from a screen reader: the text or the label of
 * the control it stands in says what it means, so an icon never carries meaning alone.
 *
 * @experimental
 */
export function Icon({ name, size = 'md' }: IconProps) {
  return (
    <svg
      className="cms-icon"
      data-size={size}
      viewBox="0 0 24 24"
      aria-hidden="true"
      focusable="false"
    >
      {PATHS[name].map((path) => (
        <path key={path} d={path} />
      ))}
    </svg>
  );
}
