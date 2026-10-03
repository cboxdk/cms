import {
  Button,
  createToastQueue,
  ToastRegion,
  type KitToastQueue,
  type ToastRegionProps,
} from '@cboxdk/cms-ui-kit';
import { useEffect, useState } from 'react';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  textsOf,
  waitFor,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<ToastRegionProps> = {
  title: 'Components/Feedback/ToastRegion',
  component: ToastRegion,
};

export default meta;

const TEXTS: Localized<{
  show: string;
  saved: string;
  savedDescription: string;
  failed: string;
}> = {
  da: {
    show: 'Vis en besked',
    saved: 'Rollen er gemt',
    savedDescription: 'Redaktør må nu udgive.',
    failed: 'Adgangen kunne ikke tilbagekaldes',
  },
  en: {
    show: 'Show a message',
    saved: 'The role is saved',
    savedDescription: 'Editor may now publish.',
    failed: 'The grant could not be revoked',
  },
};

function Toasts({ globals }: { readonly globals: Readonly<Record<string, unknown>> }) {
  const texts = textsOf(TEXTS, globals);
  const [queue] = useState<KitToastQueue>(() => createToastQueue());

  useEffect(() => {
    queue.add({ title: texts.saved, description: texts.savedDescription, tone: 'success' });
    queue.add({ title: texts.failed, tone: 'danger' });

    return () => {
      queue.clear();
    };
  }, [queue, texts.saved, texts.savedDescription, texts.failed]);

  return (
    <>
      <Button
        onClick={() => {
          queue.add({ title: texts.saved, tone: 'info' });
        }}
      >
        {texts.show}
      </Button>
      <ToastRegion queue={queue} />
    </>
  );
}

/** Two toasts in the region; each close button closes its toast. */
export const Default: Story = {
  render: (_args, { globals }) => <Toasts globals={globals} />,
  play: async ({ userEvent }) => {
    await waitFor(() => document.querySelectorAll('.cms-toast').length === 2, 'two toasts show');
    single(document.body, '.cms-toast-region', HTMLElement);
    const close = document.querySelector('.cms-toast__close');

    check(close instanceof HTMLButtonElement, 'a toast has a close button');
    check(close.getAttribute('aria-label') !== null, 'the close button is named');
    await userEvent.click(close);
    await waitFor(() => document.querySelectorAll('.cms-toast').length === 1, 'it closes');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
