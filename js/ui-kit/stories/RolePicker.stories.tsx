import { RolePicker, type PickerRole, type RolePickerProps } from '@cboxdk/cms-ui-kit';
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

const meta: StoryMeta<RolePickerProps> = {
  title: 'Components/Domain/RolePicker',
  component: RolePicker,
};

export default meta;

const TEXTS: Localized<{
  label: string;
  empty: string;
  loading: string;
  failed: string;
  editor: string;
  publisher: string;
}> = {
  da: {
    label: 'Rolle',
    empty: 'Ingen rolle passer.',
    loading: 'Henter rollerne',
    failed: 'Rollerne kunne ikke hentes. Prøv igen om lidt.',
    editor: 'Må oprette og redigere indhold.',
    publisher: 'Må udgive indhold.',
  },
  en: {
    label: 'Role',
    empty: 'No role matches.',
    loading: 'Loading the roles',
    failed: 'The roles could not be loaded. Try again in a moment.',
    editor: 'May create and edit content.',
    publisher: 'May publish content.',
  },
};

function roles(globals: Readonly<Record<string, unknown>>): PickerRole[] {
  const texts = textsOf(TEXTS, globals);

  return [
    { id: 'r1', handle: 'editor', description: texts.editor },
    { id: 'r2', handle: 'publisher', description: texts.publisher },
  ];
}

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
  const [value, setValue] = useState<string | null>('r1');

  return (
    <RolePicker
      label={texts.label}
      roles={roles(globals)}
      value={value}
      onChange={setValue}
      emptyLabel={texts.empty}
      {...(loading === true ? { loading: texts.loading } : {})}
      {...(failed === true ? { loadError: texts.failed } : {})}
      required
    />
  );
}

/** The roles open, each with what it may do. */
export const Default: Story = {
  render: (_args, { globals }) => <Picker globals={globals} />,
  play: async ({ canvasElement, userEvent }) => {
    const input = single(canvasElement, 'input', HTMLInputElement);

    check(input.value === 'editor', 'the chosen role is shown');
    await userEvent.click(single(canvasElement, 'button', HTMLButtonElement));
    await waitFor(() => document.querySelectorAll('[role="option"]').length === 2, 'it opens');
  },
};

/** While the roles load. */
export const Loading: Story = {
  render: (_args, { globals }) => <Picker globals={globals} loading />,
  play: async ({ canvasElement, userEvent }) => {
    await userEvent.click(single(canvasElement, 'button', HTMLButtonElement));
    await waitFor(() => document.querySelector('.cms-popover [role="status"]') !== null, 'waits');
  },
};

/** When the roles could not be loaded. */
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
