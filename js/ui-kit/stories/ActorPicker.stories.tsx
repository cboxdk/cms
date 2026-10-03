import { ActorPicker, type ActorPickerProps, type PickerActor } from '@cboxdk/cms-ui-kit';
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

const meta: StoryMeta<ActorPickerProps> = {
  title: 'Components/Domain/ActorPicker',
  component: ActorPicker,
};

export default meta;

const ACTORS: readonly PickerActor[] = [
  { id: 'a1', name: 'Ada Lovelace', email: 'ada@example.com' },
  { id: 'a2', name: 'Alan Turing', email: 'alan@example.com' },
  { id: 'a3', name: 'Grace Hopper', email: 'grace@example.com' },
];

const TEXTS: Localized<{
  label: string;
  empty: string;
  loading: string;
  failed: string;
}> = {
  da: {
    label: 'Medarbejder',
    empty: 'Ingen medarbejder passer.',
    loading: 'Finder medarbejdere',
    failed: 'Medarbejderne kunne ikke hentes. Prøv igen om lidt.',
  },
  en: {
    label: 'Member of staff',
    empty: 'No member of staff matches.',
    loading: 'Finding members of staff',
    failed: 'The members of staff could not be loaded. Try again in a moment.',
  },
};

function Picker({
  globals,
  loading,
  failed,
}: {
  readonly globals: Readonly<Record<string, unknown>>;
  readonly loading?: boolean;
  readonly failed?: boolean;
}) {
  const texts = textsOf(TEXTS, globals);
  const [search, setSearch] = useState('');
  const [value, setValue] = useState<string | null>(null);
  const found = ACTORS.filter((actor) => actor.name.toLowerCase().includes(search.toLowerCase()));

  return (
    <ActorPicker
      label={texts.label}
      actors={found}
      value={value}
      onChange={setValue}
      search={search}
      onSearchChange={setSearch}
      emptyLabel={texts.empty}
      {...(loading === true ? { loading: texts.loading } : {})}
      {...(failed === true ? { loadError: texts.failed } : {})}
    />
  );
}

/** Typing finds actors by name, each with the email that tells them apart. */
export const Default: Story = {
  render: (_args, { globals }) => <Picker globals={globals} />,
  play: async ({ canvasElement, userEvent }) => {
    const input = single(canvasElement, 'input', HTMLInputElement);

    await userEvent.click(input);
    await userEvent.type(input, 'a');
    await waitFor(() => document.querySelector('[role="listbox"]') !== null, 'the list opens');
    check(
      document.querySelectorAll('[role="option"]').length === 3,
      'each actor that matches is an option',
    );
  },
};

/** While the actors load. */
export const Loading: Story = {
  render: (_args, { globals }) => <Picker globals={globals} loading />,
  play: async ({ canvasElement, userEvent }) => {
    await userEvent.click(single(canvasElement, 'button', HTMLButtonElement));
    await waitFor(() => document.querySelector('.cms-popover [role="status"]') !== null, 'waits');
  },
};

/** When the actors could not be loaded. */
export const LoadFailed: Story = {
  render: (_args, { globals }) => <Picker globals={globals} failed />,
  play: async ({ canvasElement, userEvent }) => {
    await userEvent.click(single(canvasElement, 'button', HTMLButtonElement));
    await waitFor(() => document.querySelector('.cms-popover [role="alert"]') !== null, 'fails');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
