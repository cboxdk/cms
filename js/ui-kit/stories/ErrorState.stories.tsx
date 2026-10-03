import { Button, ErrorState, type ErrorStateProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<ErrorStateProps> = {
  title: 'Components/Feedback/ErrorState',
  component: ErrorState,
};

export default meta;

const TEXTS: Localized<{ title: string; description: string; retry: string }> = {
  da: {
    title: 'Adgangene kunne ikke hentes',
    description: 'Læsningen ville koste mere end budgettet tillader. Afgræns den og prøv igen.',
    retry: 'Prøv igen',
  },
  en: {
    title: 'The grants could not be loaded',
    description: 'The read would cost more than its budget allows. Narrow it and try again.',
    retry: 'Try again',
  },
};

/** What failed, why, the catalog code and what to do. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <ErrorState
        title={texts.title}
        description={texts.description}
        code="query_over_budget"
        action={<Button>{texts.retry}</Button>}
      />
    );
  },
  play: ({ canvasElement }) => {
    single(canvasElement, '[role="alert"]', HTMLDivElement);
    check(canvasElement.textContent.includes('query_over_budget'), 'the code is shown');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
