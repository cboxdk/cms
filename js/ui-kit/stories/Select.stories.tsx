import { Select, type SelectProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<SelectProps> = {
  title: 'Components/Forms/Select',
  component: Select,
};

export default meta;

const TEXTS: Localized<{
  label: string;
  description: string;
  commit: string;
  origin: string;
  edge: string;
  error: string;
}> = {
  da: {
    label: 'Vent på',
    description: 'Hvor langt ændringen skal være nået, før svaret kommer.',
    commit: 'Gemt i databasen',
    origin: 'Opdateret på origin',
    edge: 'Opdateret i CDN’et',
    error: 'Vælg, hvad der skal ventes på.',
  },
  en: {
    label: 'Wait for',
    description: 'How far the change must reach before the answer comes.',
    commit: 'Saved in the database',
    origin: 'Updated at the origin',
    edge: 'Updated at the CDN',
    error: 'Choose what to wait for.',
  },
};

function options(globals: Readonly<Record<string, unknown>>) {
  const texts = textsOf(TEXTS, globals);

  return [
    { id: 'commit', label: texts.commit },
    { id: 'origin', label: texts.origin },
    { id: 'edge', label: texts.edge },
  ];
}

/** Closed, with an option chosen. Enter opens the list on it; Down and Enter choose another. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Select
        label={texts.label}
        description={texts.description}
        options={options(globals)}
        defaultValue="commit"
        name="wait_level"
      />
    );
  },
  play: async ({ canvasElement, globals, userEvent }) => {
    const texts = textsOf(TEXTS, globals);
    const button = single(canvasElement, 'button', HTMLButtonElement);

    await userEvent.tab();
    check(document.activeElement === button, 'Tab reaches the button');
    await userEvent.keyboard('{Enter}');
    await waitFor(() => document.querySelector('[role="listbox"]') !== null, 'Enter opens it');
    check(focused(HTMLElement).textContent === texts.commit, 'the chosen option has focus');
    await userEvent.keyboard('{ArrowDown}{Enter}');
    await waitFor(() => document.querySelector('[role="listbox"]') === null, 'Enter closes it');
    check(button.textContent.includes(texts.origin), 'the next option is chosen');
    await waitFor(() => document.activeElement === button, 'focus returns to the button');
  },
};

/** Open on its options. */
export const Open: Story = {
  render: Default.render,
  play: async ({ userEvent }) => {
    await userEvent.tab();
    await userEvent.keyboard('{ArrowDown}');
    await waitFor(() => document.querySelector('[role="listbox"]') !== null, 'Down opens it');
  },
};

/** Nothing chosen yet, refused with an error. */
export const Empty: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return <Select label={texts.label} options={options(globals)} error={texts.error} required />;
  },
  play: ({ canvasElement }) => {
    const button = single(canvasElement, 'button', HTMLButtonElement);

    check(button.dataset['invalid'] === 'true', 'the select shows that it is invalid');
  },
};

export const Dark: Story = inDark(Open);
export const ForcedColors: Story = inForcedColours(Open);
export const Danish: Story = inDanish(Default);
