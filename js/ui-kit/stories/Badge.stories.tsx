import { Badge, type BadgeProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  textsOf,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<BadgeProps> = {
  title: 'Components/Feedback/Badge',
  component: Badge,
};

export default meta;

const TEXTS: Localized<{
  neutral: string;
  info: string;
  success: string;
  warning: string;
  danger: string;
}> = {
  da: {
    neutral: 'Kladde',
    info: 'Planlagt',
    success: 'Aktiv',
    warning: 'Afventer',
    danger: 'Afvist',
  },
  en: {
    neutral: 'Draft',
    info: 'Scheduled',
    success: 'Active',
    warning: 'Pending',
    danger: 'Refused',
  },
};

/** Every tone; the text says the state, the colour only adds to it. */
export const Tones: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem' }}>
        <Badge>{texts.neutral}</Badge>
        <Badge tone="info">{texts.info}</Badge>
        <Badge tone="success">{texts.success}</Badge>
        <Badge tone="warning">{texts.warning}</Badge>
        <Badge tone="danger">{texts.danger}</Badge>
      </div>
    );
  },
  play: ({ canvasElement }) => {
    check(canvasElement.querySelectorAll('.cms-badge').length === 5, 'every tone is shown');
  },
};

export const Dark: Story = inDark(Tones);
export const ForcedColors: Story = inForcedColours(Tones);
export const Danish: Story = inDanish(Tones);
