import { Icon, type IconName, type IconProps } from '@cboxdk/cms-ui-kit';

import { check, inDanish, inDark, inForcedColours, type Story, type StoryMeta } from './csf';

const meta: StoryMeta<IconProps> = {
  title: 'Foundations/Icon',
  component: Icon,
};

export default meta;

const NAMES: readonly IconName[] = [
  'check',
  'chevron-down',
  'chevron-left',
  'chevron-right',
  'chevron-up',
  'close',
  'copy',
  'error',
  'eye',
  'eye-off',
  'info',
  'menu',
  'minus',
  'more',
  'plus',
  'search',
  'sort-ascending',
  'sort-descending',
  'success',
  'warning',
];

/** Every icon of the kit, in both sizes; each is hidden from a screen reader. */
export const All: Story = {
  render: () => (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '1rem' }}>
      {NAMES.map((name) => (
        <span key={name} style={{ display: 'inline-flex', gap: '0.25rem' }}>
          <Icon name={name} />
          <Icon name={name} size="sm" />
        </span>
      ))}
    </div>
  ),
  play: ({ canvasElement }) => {
    const icons = canvasElement.querySelectorAll('svg');

    check(icons.length === NAMES.length * 2, 'every icon is drawn');
    check(
      [...icons].every((icon) => icon.getAttribute('aria-hidden') === 'true'),
      'every icon is hidden from a screen reader',
    );
  },
};

export const Dark: Story = inDark(All);
export const ForcedColors: Story = inForcedColours(All);
export const Danish: Story = inDanish(All);
