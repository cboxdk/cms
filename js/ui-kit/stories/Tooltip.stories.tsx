import { IconButton, Tooltip, type TooltipProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  textsOf,
  waitFor,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<TooltipProps> = {
  title: 'Components/Overlays/Tooltip',
  component: Tooltip,
};

export default meta;

const TEXTS: Localized<{ label: string; hint: string }> = {
  da: { label: 'Kopiér id', hint: 'Kopiér medarbejderens id' },
  en: { label: 'Copy the id', hint: 'Copy the id of the member of staff' },
};

/** Focus shows the tooltip at once; Escape hides it. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <div style={{ paddingBlock: '3rem', paddingInline: '6rem' }}>
        <Tooltip content={texts.hint}>
          <IconButton label={texts.label} icon="copy" variant="secondary" />
        </Tooltip>
      </div>
    );
  },
  play: async ({ userEvent }) => {
    const tooltip = () => document.querySelector('[role="tooltip"]');

    await userEvent.tab();
    await waitFor(() => tooltip() !== null, 'focus shows the tooltip');
    await userEvent.keyboard('{Escape}');
    await waitFor(() => tooltip() === null, 'Escape hides it');
    await userEvent.tab({ shift: true });
    await userEvent.tab();
    await waitFor(() => tooltip() !== null, 'focus shows it again');
    check(
      document.activeElement?.getAttribute('aria-describedby') === tooltip()?.id,
      'the tooltip describes the button',
    );
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
