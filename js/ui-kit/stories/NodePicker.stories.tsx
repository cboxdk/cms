import { EmptyState, ErrorState, NodePicker, type NodePickerProps } from '@cboxdk/cms-ui-kit';
import { useState } from 'react';

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
import { NODES } from './nodes';

const meta: StoryMeta<NodePickerProps> = {
  title: 'Components/Domain/NodePicker',
  component: NodePicker,
};

export default meta;

const TEXTS: Localized<{
  label: string;
  description: string;
  empty: string;
  loading: string;
  failed: string;
  failedDescription: string;
}> = {
  da: {
    label: 'Node',
    description: 'Adgangen gælder noden og alt under den.',
    empty: 'Ingen noder at vælge',
    loading: 'Henter noderne',
    failed: 'Noderne kunne ikke hentes',
    failedDescription: 'Prøv igen om lidt.',
  },
  en: {
    label: 'Node',
    description: 'The grant applies to the node and everything below it.',
    empty: 'No nodes to choose',
    loading: 'Loading the nodes',
    failed: 'The nodes could not be loaded',
    failedDescription: 'Try again in a moment.',
  },
};

function Picker({
  globals,
  initial,
  loading,
  failed,
}: {
  readonly globals: Readonly<Record<string, unknown>>;
  readonly initial: string | null;
  readonly loading?: boolean;
  readonly failed?: boolean;
}) {
  const texts = textsOf(TEXTS, globals);
  const [value, setValue] = useState<string | null>(initial);

  return (
    <NodePicker
      label={texts.label}
      description={texts.description}
      nodes={NODES}
      value={value}
      onChange={setValue}
      {...(loading === true ? { loading: texts.loading } : {})}
      loadError={
        failed === true ? (
          <ErrorState title={texts.failed} description={texts.failedDescription} />
        ) : undefined
      }
      empty={<EmptyState title={texts.empty} headingLevel={3} />}
      required
    />
  );
}

/** A node chosen, shown as its path. */
export const Chosen: Story = {
  render: (_args, { globals }) => <Picker globals={globals} initial="sport" />,
  play: ({ canvasElement }) => {
    check(canvasElement.textContent.includes('Sport'), 'the chosen node is shown');
  },
};

/**
 * Enter on the button opens the tree in a dialog; Down and Space mark a node, and the dialog's
 * Choose button takes it.
 */
export const Keyboard: Story = {
  render: (_args, { globals }) => <Picker globals={globals} initial={null} />,
  play: async ({ canvasElement, userEvent }) => {
    const button = single(canvasElement, 'button', HTMLButtonElement);

    button.focus();
    await userEvent.keyboard('{Enter}');
    await waitFor(() => document.querySelector('[role="treegrid"]') !== null, 'the tree opens');
    const tree = single(document.body, '[role="treegrid"]', HTMLDivElement);

    tree.querySelector<HTMLElement>('[role="row"]')?.focus();
    await userEvent.keyboard('{ArrowRight}{ArrowDown} ');
    const [, choose] = document.querySelectorAll<HTMLButtonElement>('.cms-dialog__footer button');
    check(choose?.disabled === false, 'a marked node can be chosen');
    choose.click();
    await waitFor(() => document.querySelector('[role="dialog"]') === null, 'the dialog closes');
    check(canvasElement.textContent.includes('News'), 'the node is chosen');
  },
};

/** The dialog open, as the tree loads. */
export const Loading: Story = {
  render: (_args, { globals }) => <Picker globals={globals} initial={null} loading />,
  play: async ({ canvasElement }) => {
    single(canvasElement, 'button', HTMLButtonElement).click();
    await waitFor(
      () => document.querySelector('[role="dialog"] [role="status"]') !== null,
      'the dialog says what it waits for',
    );
  },
};

/** The dialog open, when the tree could not be loaded. */
export const LoadFailed: Story = {
  render: (_args, { globals }) => <Picker globals={globals} initial={null} failed />,
  play: async ({ canvasElement }) => {
    single(canvasElement, 'button', HTMLButtonElement).click();
    await waitFor(
      () => document.querySelector('[role="dialog"] [role="alert"]') !== null,
      'the dialog says the tree failed',
    );
  },
};

export const Dark: Story = inDark(Chosen);
export const ForcedColors: Story = inForcedColours(Chosen);
export const Danish: Story = inDanish(Chosen);
