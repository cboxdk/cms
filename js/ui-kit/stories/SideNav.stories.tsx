import { SideNav, type SideNavProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<SideNavProps> = {
  title: 'Components/Navigation/SideNav',
  component: SideNav,
};

export default meta;

const TEXTS: Localized<{
  label: string;
  start: string;
  me: string;
  access: string;
  roles: string;
  grants: string;
}> = {
  da: {
    label: 'Panel',
    start: 'Start',
    me: 'Hvem er jeg',
    access: 'Adgang',
    roles: 'Roller',
    grants: 'Adgange',
  },
  en: {
    label: 'Panel',
    start: 'Start',
    me: 'Who am I',
    access: 'Access',
    roles: 'Roles',
    grants: 'Grants',
  },
};

/** The navigation with the current page marked; Tab moves through its links in order. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <div style={{ inlineSize: '16rem' }}>
        <SideNav
          label={texts.label}
          groups={[
            {
              id: 'main',
              items: [
                { id: 'start', label: texts.start, href: '#start' },
                { id: 'me', label: texts.me, href: '#me' },
              ],
            },
            {
              id: 'access',
              label: texts.access,
              items: [
                { id: 'roles', label: texts.roles, href: '#roles', current: true },
                { id: 'grants', label: texts.grants, href: '#grants' },
              ],
            },
          ]}
        />
      </div>
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    const nav = single(canvasElement, 'nav', HTMLElement);
    const current = single(canvasElement, '[aria-current="page"]', HTMLAnchorElement);

    check(nav.getAttribute('aria-label') !== null, 'the navigation is named');
    check(current.getAttribute('href') === '#roles', 'the current page is marked');

    await userEvent.tab();
    check(
      focused(HTMLAnchorElement).getAttribute('href') === '#start',
      'Tab reaches the first link',
    );
    await userEvent.tab();
    check(focused(HTMLAnchorElement).getAttribute('href') === '#me', 'Tab moves to the next link');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
