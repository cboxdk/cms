import { EmptyState, ErrorState, Tree, type TreeProps } from '@cboxdk/cms-ui-kit';
import { useState } from 'react';

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
import { NODES } from './nodes';

const meta: StoryMeta<TreeProps> = {
  title: 'Components/Data display/Tree',
  component: Tree,
};

export default meta;

const TEXTS: Localized<{
  label: string;
  empty: string;
  emptyDescription: string;
  loading: string;
  failed: string;
  failedDescription: string;
}> = {
  da: {
    label: 'Noder',
    empty: 'Ingen noder',
    emptyDescription: 'Opret et site for at få dets rodnode.',
    loading: 'Henter noderne',
    failed: 'Noderne kunne ikke hentes',
    failedDescription: 'Prøv igen om lidt.',
  },
  en: {
    label: 'Nodes',
    empty: 'No nodes',
    emptyDescription: 'Create a site to get its root node.',
    loading: 'Loading the nodes',
    failed: 'The nodes could not be loaded',
    failedDescription: 'Try again in a moment.',
  },
};

function Nodes({ globals }: { readonly globals: Readonly<Record<string, unknown>> }) {
  const [selected, setSelected] = useState<string | null>('news');

  return (
    <Tree
      label={textsOf(TEXTS, globals).label}
      nodes={NODES}
      selectionMode="single"
      selected={selected}
      onSelectionChange={setSelected}
      defaultExpanded={['site', 'news']}
      empty={<EmptyState title={textsOf(TEXTS, globals).empty} headingLevel={3} />}
    />
  );
}

/** The tree open down to a chosen node. */
export const Default: Story = {
  render: (_args, { globals }) => <Nodes globals={globals} />,
  play: ({ canvasElement }) => {
    const tree = single(canvasElement, '[role="treegrid"]', HTMLDivElement);
    const chosen = single(tree, '[aria-selected="true"]', HTMLDivElement);

    check(chosen.textContent.includes('News'), 'the chosen node is marked');
  },
};

/** Tab reaches the chosen node; Left closes it, Right opens it, Down moves and Space chooses. */
export const Keyboard: Story = {
  render: Default.render,
  play: async ({ canvasElement, userEvent }) => {
    const tree = single(canvasElement, '[role="treegrid"]', HTMLDivElement);

    await userEvent.tab();
    check(tree.contains(document.activeElement), 'Tab moves into the tree');
    check(focused(HTMLElement).textContent.includes('News'), 'Tab reaches the chosen node');
    await userEvent.keyboard('{ArrowLeft}');
    check(focused(HTMLElement).getAttribute('aria-expanded') === 'false', 'Left closes it');
    await userEvent.keyboard('{ArrowRight}');
    check(focused(HTMLElement).getAttribute('aria-expanded') === 'true', 'Right opens it');
    await userEvent.keyboard('{ArrowDown}');
    check(focused(HTMLElement).textContent.includes('Sport'), 'Down moves into it');
    await userEvent.keyboard(' ');
    check(focused(HTMLElement).getAttribute('aria-selected') === 'true', 'Space chooses');
  },
};

/** No nodes. */
export const Empty: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Tree
        label={texts.label}
        nodes={[]}
        empty={<EmptyState title={texts.empty} description={texts.emptyDescription} />}
      />
    );
  },
};

/** While the nodes load. */
export const Loading: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return <Tree label={texts.label} nodes={[]} loading={texts.loading} empty={null} />;
  },
  play: ({ canvasElement }) => {
    single(canvasElement, '[role="status"]', HTMLSpanElement);
  },
};

/** When the nodes could not be loaded. */
export const LoadFailed: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Tree
        label={texts.label}
        nodes={[]}
        error={<ErrorState title={texts.failed} description={texts.failedDescription} />}
        empty={null}
      />
    );
  },
  play: ({ canvasElement }) => {
    single(canvasElement, '[role="alert"]', HTMLDivElement);
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
