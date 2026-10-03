import { MultiSelect, type MultiSelectProps } from '@cboxdk/cms-ui-kit';
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

const meta: StoryMeta<MultiSelectProps> = {
  title: 'Components/Forms/MultiSelect',
  component: MultiSelect,
};

export default meta;

const TEXTS: Localized<{
  label: string;
  description: string;
  none: string;
  danish: string;
  english: string;
  german: string;
}> = {
  da: {
    label: 'Sprog',
    description: 'Vælg ingen for alle sprog.',
    none: 'Alle sprog',
    danish: 'Dansk',
    english: 'Engelsk',
    german: 'Tysk',
  },
  en: {
    label: 'Locales',
    description: 'Choose none for every locale.',
    none: 'Every locale',
    danish: 'Danish',
    english: 'English',
    german: 'German',
  },
};

function Locales({
  globals,
  initial,
}: {
  readonly globals: Readonly<Record<string, unknown>>;
  readonly initial: readonly string[];
}) {
  const texts = textsOf(TEXTS, globals);
  const [value, setValue] = useState<readonly string[]>(initial);

  return (
    <MultiSelect
      label={texts.label}
      description={texts.description}
      noneLabel={texts.none}
      options={[
        { id: 'da', label: texts.danish },
        { id: 'en', label: texts.english },
        { id: 'de', label: texts.german },
      ]}
      value={value}
      onChange={setValue}
      name="locales"
    />
  );
}

/**
 * Two locales chosen. Enter opens the list; Space chooses another and keeps it open; Escape closes
 * it. Each tag's button removes its locale.
 */
export const Default: Story = {
  render: (_args, { globals }) => <Locales globals={globals} initial={['da', 'en']} />,
  play: async ({ canvasElement, userEvent }) => {
    const button = single(canvasElement, 'button[aria-haspopup]', HTMLButtonElement);

    check(canvasElement.querySelectorAll('input[type="hidden"]').length === 2, 'two are chosen');
    await userEvent.tab();
    await userEvent.keyboard('{Enter}');
    await waitFor(() => document.querySelector('[role="listbox"]') !== null, 'Enter opens it');
    await userEvent.keyboard('{ArrowDown}{ArrowDown} ');
    check(canvasElement.querySelectorAll('input[type="hidden"]').length === 3, 'Space chooses');
    check(document.querySelector('[role="listbox"]') !== null, 'the list stays open');
    await userEvent.keyboard('{Escape}');
    await waitFor(() => document.querySelector('[role="listbox"]') === null, 'Escape closes it');
    await waitFor(() => document.activeElement === button, 'focus returns to the button');

    await userEvent.tab();
    focused(HTMLButtonElement).click();
    await waitFor(
      () => canvasElement.querySelectorAll('input[type="hidden"]').length === 2,
      'a tag’s button removes its option',
    );
  },
};

/** None chosen: the button says what that means. */
export const NoneChosen: Story = {
  render: (_args, { globals }) => <Locales globals={globals} initial={[]} />,
  play: ({ canvasElement, globals }) => {
    const button = single(canvasElement, 'button[aria-haspopup]', HTMLButtonElement);

    check(button.textContent.includes(textsOf(TEXTS, globals).none), 'none means every locale');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
