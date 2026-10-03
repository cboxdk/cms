import { Button, type ButtonProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  storyLocale,
  type Story,
  type StoryMeta,
} from './csf';
import { STORY_TEXTS } from './texts';

const meta: StoryMeta<ButtonProps> = {
  title: 'Components/Actions/Button',
  component: Button,
};

export default meta;

/** The default button: a plain button element of type button, reached and pressed by keyboard. */
export const Secondary: Story = {
  render: (_args, { globals }) => (
    <Button
      onClick={(event) => {
        event.currentTarget.dataset['pressed'] = String(
          Number(event.currentTarget.dataset['pressed'] ?? '0') + 1,
        );
      }}
    >
      {STORY_TEXTS[storyLocale(globals)].cancel}
    </Button>
  ),
  play: async ({ canvasElement, userEvent }) => {
    const button = single(canvasElement, 'button', HTMLButtonElement);

    check(button.type === 'button', 'a kit button is of type button unless asked otherwise');

    await userEvent.tab();
    check(document.activeElement === button, 'Tab reaches the button');

    await userEvent.keyboard('{Enter}');
    await userEvent.keyboard(' ');
    check(button.dataset['pressed'] === '2', 'Enter and Space press the button');
  },
};

/** The primary action of a form, such as saving it. */
export const Primary: Story = {
  render: (_args, { globals }) => (
    <Button variant="primary">{STORY_TEXTS[storyLocale(globals)].save}</Button>
  ),
};

/** An action that removes something. */
export const Danger: Story = {
  render: (_args, { globals }) => (
    <Button variant="danger">{STORY_TEXTS[storyLocale(globals)].delete}</Button>
  ),
};

/** A quiet button, and a button with an icon before its text. */
export const QuietAndIcon: Story = {
  render: (_args, { globals }) => {
    const texts = STORY_TEXTS[storyLocale(globals)];

    return (
      <div style={{ display: 'flex', gap: '0.5rem' }}>
        <Button variant="quiet">{texts.cancel}</Button>
        <Button icon="plus" variant="primary">
          {texts.save}
        </Button>
      </div>
    );
  },
};

export const Dark: Story = inDark(Primary);
export const ForcedColors: Story = inForcedColours(Primary);
export const Danish: Story = inDanish(Primary);
