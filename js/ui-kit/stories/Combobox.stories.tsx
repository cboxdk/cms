import { Combobox, type ComboboxProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<ComboboxProps> = {
  title: 'Components/Forms/Combobox',
  component: Combobox,
};

export default meta;

const TEXTS: Localized<{
  label: string;
  description: string;
  empty: string;
  loading: string;
  failed: string;
  news: string;
  sport: string;
  culture: string;
}> = {
  da: {
    label: 'Sektion',
    description: 'Skriv for at finde en sektion.',
    empty: 'Ingen sektion passer.',
    loading: 'Henter sektionerne',
    failed: 'Sektionerne kunne ikke hentes. Prøv igen om lidt.',
    news: 'Nyheder',
    sport: 'Sport',
    culture: 'Kultur',
  },
  en: {
    label: 'Section',
    description: 'Type to find a section.',
    empty: 'No section matches.',
    loading: 'Loading the sections',
    failed: 'The sections could not be loaded. Try again in a moment.',
    news: 'News',
    sport: 'Sport',
    culture: 'Culture',
  },
};

function options(globals: Readonly<Record<string, unknown>>) {
  const texts = textsOf(TEXTS, globals);

  return [
    { id: 'news', label: texts.news },
    { id: 'sport', label: texts.sport },
    { id: 'culture', label: texts.culture },
  ];
}

const listbox = () => document.querySelector('[role="listbox"]');

/** Typing filters the list; Down moves in it while focus stays in the input, Enter chooses. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Combobox
        label={texts.label}
        description={texts.description}
        options={options(globals)}
        emptyLabel={texts.empty}
      />
    );
  },
  play: async ({ canvasElement, globals, userEvent }) => {
    const texts = textsOf(TEXTS, globals);
    const input = single(canvasElement, 'input[role="combobox"]', HTMLInputElement);

    await userEvent.click(input);
    await userEvent.type(input, texts.sport.slice(0, 2));
    await waitFor(() => listbox() !== null, 'typing opens the list');
    check(listbox()?.querySelectorAll('[role="option"]').length === 1, 'the list is filtered');
    await userEvent.keyboard('{ArrowDown}');
    check(document.activeElement === input, 'focus stays in the input');
    check(input.getAttribute('aria-activedescendant') !== null, 'the option is active');
    await userEvent.keyboard('{Enter}');
    check(input.value === texts.sport, 'Enter chooses the option');
  },
};

/** Open on every option, as the button at the edge shows them. */
export const Open: Story = {
  render: Default.render,
  play: async ({ canvasElement, userEvent }) => {
    const button = single(canvasElement, 'button', HTMLButtonElement);

    await userEvent.click(button);
    await waitFor(() => listbox() !== null, 'the button opens the list');
  },
};

/** No option matches what was typed. */
export const NoMatch: Story = {
  render: Default.render,
  play: async ({ canvasElement, globals, userEvent }) => {
    const input = single(canvasElement, 'input[role="combobox"]', HTMLInputElement);

    await userEvent.click(input);
    await userEvent.type(input, 'zz');
    await waitFor(
      () => document.body.textContent.includes(textsOf(TEXTS, globals).empty),
      'the list says that nothing matches',
    );
  },
};

/** While the options load, the list says what it waits for. */
export const Loading: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Combobox
        label={texts.label}
        options={[]}
        emptyLabel={texts.empty}
        loading={texts.loading}
        onInputChange={() => undefined}
        inputValue=""
      />
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    await userEvent.click(single(canvasElement, 'button', HTMLButtonElement));
    await waitFor(
      () => document.querySelector('.cms-popover [role="status"]') !== null,
      'the list says what it waits for',
    );
  },
};

/** When the options could not be loaded, the list says so. */
export const LoadFailed: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Combobox
        label={texts.label}
        options={[]}
        emptyLabel={texts.empty}
        loadError={texts.failed}
        onInputChange={() => undefined}
        inputValue=""
      />
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    await userEvent.click(single(canvasElement, 'button', HTMLButtonElement));
    await waitFor(
      () => document.querySelector('.cms-popover [role="alert"]') !== null,
      'the list says the options failed',
    );
  },
};

export const Dark: Story = inDark(Open);
export const ForcedColors: Story = inForcedColours(Open);
export const Danish: Story = inDanish(Open);
