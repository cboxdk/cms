import { Tabs, type TabsProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  focused,
  inDanish,
  inDark,
  inForcedColours,
  textsOf,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<TabsProps> = {
  title: 'Components/Navigation/Tabs',
  component: Tabs,
};

export default meta;

const TEXTS: Localized<{
  label: string;
  profile: string;
  grants: string;
  history: string;
  profileBody: string;
  grantsBody: string;
  historyBody: string;
}> = {
  da: {
    label: 'Medarbejder',
    profile: 'Profil',
    grants: 'Adgange',
    history: 'Historik',
    profileBody: 'Navn og e-mail.',
    grantsBody: 'Roller og noder.',
    historyBody: 'Ændringer over tid.',
  },
  en: {
    label: 'Member of staff',
    profile: 'Profile',
    grants: 'Grants',
    history: 'History',
    profileBody: 'Name and email.',
    grantsBody: 'Roles and nodes.',
    historyBody: 'Changes over time.',
  },
};

/** Tab reaches the shown tab; Right shows the next one, and End the last. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Tabs
        label={texts.label}
        tabs={[
          { id: 'profile', label: texts.profile, content: <p>{texts.profileBody}</p> },
          { id: 'grants', label: texts.grants, content: <p>{texts.grantsBody}</p> },
          { id: 'history', label: texts.history, content: <p>{texts.historyBody}</p> },
        ]}
      />
    );
  },
  play: async ({ globals, userEvent }) => {
    const texts = textsOf(TEXTS, globals);

    await userEvent.tab();
    check(focused(HTMLElement).getAttribute('role') === 'tab', 'Tab reaches the shown tab');
    check(focused(HTMLElement).getAttribute('aria-selected') === 'true', 'the first tab is shown');

    await userEvent.keyboard('{ArrowRight}');
    check(focused(HTMLElement).textContent === texts.grants, 'Right moves to the next tab');
    check(focused(HTMLElement).getAttribute('aria-selected') === 'true', 'and shows it');

    await userEvent.keyboard('{End}');
    check(focused(HTMLElement).textContent === texts.history, 'End moves to the last tab');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
