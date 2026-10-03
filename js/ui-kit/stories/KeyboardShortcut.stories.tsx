import { KeyboardShortcut, type KeyboardShortcutProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<KeyboardShortcutProps> = {
  title: 'Components/Actions/KeyboardShortcut',
  component: KeyboardShortcut,
};

export default meta;

/** The shortcut of the command palette, written for the reader's system. */
export const Default: Story = {
  render: () => <KeyboardShortcut keys={['Mod', 'K']} />,
  play: ({ canvasElement }) => {
    const outer = single(canvasElement, 'kbd.cms-keyboard-shortcut', HTMLElement);

    check(outer.querySelectorAll('kbd').length === 2, 'each key is a kbd element');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
