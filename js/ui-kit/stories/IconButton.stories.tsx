import { IconButton, Tooltip, type IconButtonProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  focused,
  inDanish,
  inDark,
  inForcedColours,
  single,
  textsOf,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<IconButtonProps> = {
  title: 'Components/Actions/IconButton',
  component: IconButton,
};

export default meta;

const TEXTS: Localized<{ close: string; copy: string }> = {
  da: { close: 'Luk', copy: 'Kopiér id' },
  en: { close: 'Close', copy: 'Copy the id' },
};

/** The label is the button's accessible name; Enter presses it. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <div style={{ display: 'flex', gap: '0.5rem' }}>
        <IconButton
          label={texts.close}
          icon="close"
          onClick={(event) => {
            event.currentTarget.dataset['pressed'] = 'true';
          }}
        />
        <IconButton label={texts.copy} icon="copy" variant="secondary" />
      </div>
    );
  },
  play: async ({ canvasElement, globals, userEvent }) => {
    const [close] = canvasElement.querySelectorAll('button');

    check(close?.getAttribute('aria-label') === textsOf(TEXTS, globals).close, 'it is named');
    await userEvent.tab();
    await userEvent.keyboard('{Enter}');
    check(focused(HTMLButtonElement).dataset['pressed'] === 'true', 'Enter presses it');
  },
};

/** With a Tooltip around it, focus shows the label as a hint too. */
export const WithTooltip: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <div style={{ paddingBlockStart: '3rem' }}>
        <Tooltip content={texts.copy}>
          <IconButton label={texts.copy} icon="copy" variant="secondary" />
        </Tooltip>
      </div>
    );
  },
  play: async ({ userEvent }) => {
    await userEvent.tab();
    const button = focused(HTMLButtonElement);
    const tooltip = single(document.body, '[role="tooltip"]', HTMLDivElement);

    check(
      button.getAttribute('aria-describedby') === tooltip.id,
      'the tooltip describes the button',
    );
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
