import { Button, Callout, type CalloutProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<CalloutProps> = {
  title: 'Components/Feedback/Callout',
  component: Callout,
};

export default meta;

const TEXTS: Localized<{
  info: string;
  success: string;
  warningTitle: string;
  warning: string;
  dangerTitle: string;
  danger: string;
  retry: string;
}> = {
  da: {
    info: 'Du er logget ud.',
    success: 'Ændringerne er gemt.',
    warningTitle: 'Gemt, opdaterer stadig',
    warning: 'Siden viser den gamle udgave et øjeblik endnu.',
    dangerTitle: 'Formularen blev afvist',
    danger: 'Ret felterne markeret nedenfor.',
    retry: 'Prøv igen',
  },
  en: {
    info: 'You are signed out.',
    success: 'The changes are saved.',
    warningTitle: 'Saved, still updating',
    warning: 'The page shows the old version for a moment longer.',
    dangerTitle: 'The form was refused',
    danger: 'Correct the fields marked below.',
    retry: 'Try again',
  },
};

/**
 * Every tone: info and success are statuses, warning and danger alerts; each tone has its icon and
 * edge, so colour never carries it alone.
 */
export const Tones: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <div style={{ display: 'grid', gap: '0.75rem' }}>
        <Callout>{texts.info}</Callout>
        <Callout tone="success">{texts.success}</Callout>
        <Callout tone="warning" title={texts.warningTitle}>
          {texts.warning}
        </Callout>
        <Callout tone="danger" title={texts.dangerTitle} action={<Button>{texts.retry}</Button>}>
          {texts.danger}
        </Callout>
      </div>
    );
  },
  play: ({ canvasElement }) => {
    const roles = [...canvasElement.querySelectorAll('.cms-callout')].map((callout) =>
      callout.getAttribute('role'),
    );

    check(
      roles.join(' ') === 'status status alert alert',
      'info and success are statuses, warning and danger alerts',
    );
  },
};

export const Dark: Story = inDark(Tones);
export const ForcedColors: Story = inForcedColours(Tones);
export const Danish: Story = inDanish(Tones);
