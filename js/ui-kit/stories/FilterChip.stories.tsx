import { FilterChip, type FilterChipProps } from '@cboxdk/cms-ui-kit';
import { useState } from 'react';

import {
  check,
  focused,
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

const meta: StoryMeta<FilterChipProps> = {
  title: 'Components/Data display/FilterChip',
  component: FilterChip,
};

export default meta;

const TEXTS: Localized<{
  type: string;
  state: string;
  article: string;
  event: string;
  draft: string;
  released: string;
}> = {
  da: {
    type: 'Type',
    state: 'Status',
    article: 'Artikel',
    event: 'Begivenhed',
    draft: 'Kladde',
    released: 'Udgivet',
  },
  en: {
    type: 'Type',
    state: 'State',
    article: 'Article',
    event: 'Event',
    draft: 'Draft',
    released: 'Released',
  },
};

function Filters({
  globals,
  initial,
}: {
  readonly globals: Readonly<Record<string, unknown>>;
  readonly initial: string | null;
}) {
  const texts = textsOf(TEXTS, globals);
  const [type, setType] = useState<string | null>(initial);
  const [state, setState] = useState<string | null>(null);

  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem' }}>
      <FilterChip
        label={texts.type}
        options={[
          { id: 'article', label: texts.article },
          { id: 'event', label: texts.event },
        ]}
        value={type}
        onChange={setType}
      />
      <FilterChip
        label={texts.state}
        options={[
          { id: 'unreleased', label: texts.draft },
          { id: 'released', label: texts.released },
        ]}
        value={state}
        onChange={setState}
      />
    </div>
  );
}

/** A type chosen and a state not: the solid chip with its value and its clear button, and the dashed one. */
export const Default: Story = {
  render: (_args, { globals }) => <Filters globals={globals} initial="article" />,
  play: ({ canvasElement }) => {
    check(canvasElement.querySelectorAll('.cms-filter-chip').length === 2, 'both chips are shown');
    single(canvasElement, '.cms-filter-chip[data-set="true"]', HTMLSpanElement);
    single(canvasElement, '.cms-filter-chip__clear', HTMLButtonElement);
  },
};

/** Enter opens the values, the arrow keys move between them and Enter chooses one. */
export const Keyboard: Story = {
  render: (_args, { globals }) => <Filters globals={globals} initial={null} />,
  play: async ({ canvasElement, userEvent }) => {
    await userEvent.tab();
    check(
      focused(HTMLButtonElement).classList.contains('cms-filter-chip__trigger'),
      'Tab reaches a chip',
    );
    await userEvent.keyboard('{Enter}');
    await waitFor(() => document.querySelector('[role="menu"]') !== null, 'Enter opens the values');
    await userEvent.keyboard('{ArrowDown}{Enter}');
    await waitFor(
      () => canvasElement.querySelector('.cms-filter-chip[data-set="true"]') !== null,
      'Enter chooses a value',
    );
    check(document.querySelector('[role="menu"]') === null, 'the menu closes');
  },
};

/** The clear button takes the value away. */
export const Clear: Story = {
  render: (_args, { globals }) => <Filters globals={globals} initial="event" />,
  play: async ({ canvasElement, userEvent }) => {
    await userEvent.click(single(canvasElement, '.cms-filter-chip__clear', HTMLButtonElement));
    await waitFor(
      () => canvasElement.querySelector('.cms-filter-chip[data-set="true"]') === null,
      'the chip has no value',
    );
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
