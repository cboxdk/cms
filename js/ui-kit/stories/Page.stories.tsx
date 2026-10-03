import {
  Breadcrumbs,
  Button,
  Card,
  Page,
  PageHeader,
  Section,
  type PageProps,
} from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<PageProps> = {
  title: 'Components/Layout/Page',
  component: Page,
};

export default meta;

const TEXTS: Localized<{
  trail: string;
  panel: string;
  roles: string;
  title: string;
  description: string;
  add: string;
  section: string;
  sectionDescription: string;
  card: string;
  body: string;
}> = {
  da: {
    trail: 'Brødkrummer',
    panel: 'Panel',
    roles: 'Roller',
    title: 'Roller',
    description: 'Hvad hver rolle må, og hvor den gælder.',
    add: 'Opret rolle',
    section: 'Redaktører',
    sectionDescription: 'Roller, der må redigere indhold.',
    card: 'Redaktør',
    body: 'Må oprette og redigere indhold i sine områder.',
  },
  en: {
    trail: 'Breadcrumbs',
    panel: 'Panel',
    roles: 'Roles',
    title: 'Roles',
    description: 'What each role may do, and where it applies.',
    add: 'Create a role',
    section: 'Editors',
    sectionDescription: 'Roles that may edit content.',
    card: 'Editor',
    body: 'May create and edit content in its areas.',
  },
};

/** A page with its header, a section and a card: one h1, and a heading for every part. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Page
        header={
          <PageHeader
            title={texts.title}
            description={texts.description}
            breadcrumbs={
              <Breadcrumbs
                label={texts.trail}
                items={[
                  { id: 'panel', label: texts.panel, href: '#panel' },
                  { id: 'roles', label: texts.roles },
                ]}
              />
            }
            actions={<Button variant="primary">{texts.add}</Button>}
          />
        }
      >
        <Section title={texts.section} description={texts.sectionDescription}>
          <Card title={texts.card} headingLevel={3}>
            <p>{texts.body}</p>
          </Card>
        </Section>
      </Page>
    );
  },
  play: ({ canvasElement }) => {
    single(canvasElement, 'h1', HTMLHeadingElement);
    check(canvasElement.querySelectorAll('h2').length === 1, 'the section has an h2');
    check(canvasElement.querySelectorAll('h3').length === 1, 'the card has an h3');
  },
};

/** A narrow page keeps a form at a readable width. */
export const Narrow: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Page width="narrow" header={<PageHeader title={texts.add} />}>
        <p>{texts.body}</p>
      </Page>
    );
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
