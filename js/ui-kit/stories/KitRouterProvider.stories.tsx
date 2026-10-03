import { KitRouterProvider, SideNav, type KitRouterProviderProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  textsOf,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<KitRouterProviderProps> = {
  title: 'Foundations/KitRouterProvider',
  component: KitRouterProvider,
};

export default meta;

const TEXTS: Localized<{ label: string; roles: string }> = {
  da: { label: 'Panel', roles: 'Roller' },
  en: { label: 'Panel', roles: 'Roles' },
};

/** A press on a kit link goes through the application's router instead of loading the page. */
export const ClientRouting: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <KitRouterProvider
        navigate={(href) => {
          document.body.dataset['visited'] = href;
        }}
      >
        <SideNav
          label={texts.label}
          groups={[{ id: 'main', items: [{ id: 'roles', label: texts.roles, href: '/roles' }] }]}
        />
      </KitRouterProvider>
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    const link = single(canvasElement, 'a', HTMLAnchorElement);

    await userEvent.click(link);
    check(document.body.dataset['visited'] === '/roles', 'the router got the address');
    delete document.body.dataset['visited'];
  },
};

export const Dark: Story = inDark(ClientRouting);
export const ForcedColors: Story = inForcedColours(ClientRouting);
export const Danish: Story = inDanish(ClientRouting);
