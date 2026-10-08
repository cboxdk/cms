import {
  AppShell,
  Badge,
  Button,
  Page,
  PageHeader,
  SideNav,
  type AppShellProps,
} from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<AppShellProps> = {
  title: 'Components/Layout/AppShell',
  component: AppShell,
};

export default meta;

const TEXTS: Localized<{
  brand: string;
  navigation: string;
  content: string;
  start: string;
  me: string;
  roles: string;
  grants: string;
  access: string;
  title: string;
  description: string;
  palette: string;
  publish: string;
  active: string;
}> = {
  da: {
    brand: 'Cbox CMS',
    navigation: 'Panel',
    content: 'Indhold',
    start: 'Start',
    me: 'Hvem er jeg',
    roles: 'Roller',
    grants: 'Adgange',
    access: 'Adgang',
    title: 'Hvem er jeg',
    description: 'Din profil og de adgange, du har.',
    palette: 'Kommandoer',
    publish: 'Udgiv hele sektionen igen',
    active: 'Aktiv',
  },
  en: {
    brand: 'Cbox CMS',
    navigation: 'Panel',
    content: 'Content',
    start: 'Start',
    me: 'Who am I',
    roles: 'Roles',
    grants: 'Grants',
    access: 'Access',
    title: 'Who am I',
    description: 'Your profile and the access you have.',
    palette: 'Commands',
    publish: 'Publish the whole section again',
    active: 'Active',
  },
};

function render(
  _args: unknown,
  { globals }: { readonly globals: Readonly<Record<string, unknown>> },
  crowded = false,
) {
  const texts = textsOf(TEXTS, globals);

  return (
    <AppShell
      brand={texts.brand}
      actions={
        <>
          <Button icon="search">{texts.palette}</Button>
          {crowded ? <Button icon="plus">{texts.publish}</Button> : null}
        </>
      }
      navigation={
        <SideNav
          label={texts.navigation}
          groups={[
            {
              id: 'main',
              items: [
                { id: 'start', label: texts.start, href: '#start' },
                { id: 'me', label: texts.me, href: '#me', current: true },
              ],
            },
            {
              id: 'access',
              label: texts.access,
              items: [
                { id: 'roles', label: texts.roles, href: '#roles' },
                { id: 'grants', label: texts.grants, href: '#grants' },
              ],
            },
          ]}
        />
      }
    >
      <Page
        header={
          <PageHeader
            title={texts.title}
            description={texts.description}
            meta={<Badge tone="success">{texts.active}</Badge>}
          />
        }
      >
        <p>{texts.content}</p>
      </Page>
    </AppShell>
  );
}

/**
 * The frame of every page on a wide screen: the top bar, the navigation beside the content and the
 * page in the main landmark. Tab first reaches the skip link, which moves focus to the content.
 */
export const Wide: Story = {
  render,
  play: async ({ canvasElement, userEvent }) => {
    const main = single(canvasElement, 'main', HTMLElement);
    single(canvasElement, 'header', HTMLElement);
    single(canvasElement, 'nav', HTMLElement);

    await userEvent.tab();
    const skip = focused(HTMLAnchorElement);
    check(skip.getAttribute('href') === `#${main.id}`, 'the first stop is the skip link');

    await userEvent.keyboard('{Enter}');
    check(document.activeElement === main, 'the skip link moves focus to the main landmark');
  },
};

/**
 * On a narrow screen the navigation hides behind the button in the top bar; the button shows it,
 * and Escape hides it again and returns focus to the button. The bar holds more than fits on one
 * line here, an action with a long text among it, so the actions take a line of their own: the
 * bar stays inside the screen, and the page never scrolls sideways.
 */
export const Narrow: Story = {
  render: (args, context) => (
    <div style={{ inlineSize: '22rem' }}>{render(args, context, true)}</div>
  ),
  play: async ({ canvasElement, userEvent }) => {
    const bar = single(canvasElement, 'header', HTMLElement);
    const toggle = single(canvasElement, 'button[aria-expanded]', HTMLButtonElement);

    check(bar.scrollWidth <= bar.clientWidth, 'the bar fits the narrow shell');
    check(
      single(canvasElement, 'main', HTMLElement).scrollWidth <=
        single(canvasElement, 'main', HTMLElement).clientWidth,
      'the content fits the narrow shell',
    );
    check(toggle.getAttribute('aria-expanded') === 'false', 'the navigation starts hidden');
    await userEvent.click(toggle);
    check(toggle.getAttribute('aria-expanded') === 'true', 'the button shows the navigation');
    await userEvent.keyboard('{Escape}');
    check(toggle.getAttribute('aria-expanded') === 'false', 'Escape hides the navigation');
    check(document.activeElement === toggle, 'focus returns to the button');
  },
};

export const Dark: Story = inDark(Wide);
export const ForcedColors: Story = inForcedColours(Wide);
export const Danish: Story = inDanish(Wide);
