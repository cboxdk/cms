import { Switch, type SwitchProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  textsOf,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<SwitchProps> = {
  title: 'Components/Forms/Switch',
  component: Switch,
};

export default meta;

const TEXTS: Localized<{ label: string; description: string; on: string }> = {
  da: {
    label: 'Vis tekniske detaljer',
    description: 'Viser id’er og positioner ved hver kvittering.',
    on: 'Mørkt tema',
  },
  en: {
    label: 'Show technical details',
    description: 'Shows ids and positions with each receipt.',
    on: 'Dark theme',
  },
};

/** Space turns the switch on and off; a screen reader hears it as a switch. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <div style={{ display: 'grid', gap: '1rem' }}>
        <Switch label={texts.label} description={texts.description} />
        <Switch label={texts.on} defaultOn />
      </div>
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    const [first] = canvasElement.querySelectorAll('input');

    check(first?.getAttribute('role') === 'switch', 'the input has the role switch');
    await userEvent.tab();
    await userEvent.keyboard(' ');
    check(first.checked, 'Space turns it on');
  },
};

/** A switch that cannot be changed. */
export const Disabled: Story = {
  render: (_args, { globals }) => <Switch label={textsOf(TEXTS, globals).label} disabled />,
  play: ({ canvasElement }) => {
    check(single(canvasElement, 'input', HTMLInputElement).disabled, 'the switch is disabled');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
