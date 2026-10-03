import {
  Badge,
  DescriptionList,
  EmptyState,
  ErrorState,
  type DescriptionListProps,
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

/** An actor's id, as the kernel writes it. */
const ACTOR_ID = '0199a6b2-5c3e-7f10-8a4b-1c2d3e4f5a6b';

const meta: StoryMeta<DescriptionListProps> = {
  title: 'Components/Data display/DescriptionList',
  component: DescriptionList,
};

export default meta;

const TEXTS: Localized<{
  email: string;
  id: string;
  actorClass: string;
  staff: string;
  state: string;
  active: string;
  loading: string;
  failed: string;
  failedDescription: string;
  empty: string;
}> = {
  da: {
    email: 'E-mail',
    id: 'Id',
    actorClass: 'Klasse',
    staff: 'Medarbejder',
    state: 'Tilstand',
    active: 'Aktiv',
    loading: 'Henter profilen',
    failed: 'Profilen kunne ikke hentes',
    failedDescription: 'Prøv at genindlæse siden.',
    empty: 'Ingen oplysninger',
  },
  en: {
    email: 'Email',
    id: 'Id',
    actorClass: 'Class',
    staff: 'Staff',
    state: 'State',
    active: 'Active',
    loading: 'Loading the profile',
    failed: 'The profile could not be loaded',
    failedDescription: 'Try loading the page again.',
    empty: 'No details',
  },
};

/** Facts about an actor, each term beside its value on a wide screen. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <DescriptionList
        items={[
          { id: 'email', term: texts.email, description: 'ada@example.com' },
          {
            id: 'id',
            term: texts.id,
            description: <code>{ACTOR_ID}</code>,
          },
          { id: 'class', term: texts.actorClass, description: texts.staff },
          {
            id: 'state',
            term: texts.state,
            description: <Badge tone="success">{texts.active}</Badge>,
          },
        ]}
      />
    );
  },
  play: ({ canvasElement }) => {
    const list = single(canvasElement, 'dl', HTMLDListElement);

    check(list.querySelectorAll('dt').length === 4, 'each fact has a term');
  },
};

/** While the values load. */
export const Loading: Story = {
  render: (_args, { globals }) => (
    <DescriptionList items={[]} loading={textsOf(TEXTS, globals).loading} />
  ),
  play: ({ canvasElement }) => {
    single(canvasElement, '[role="status"]', HTMLSpanElement);
  },
};

/** When the values could not be loaded. */
export const LoadFailed: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <DescriptionList
        items={[]}
        error={<ErrorState title={texts.failed} description={texts.failedDescription} />}
      />
    );
  },
  play: ({ canvasElement }) => {
    single(canvasElement, '[role="alert"]', HTMLDivElement);
  },
};

/** No facts to show. */
export const Empty: Story = {
  render: (_args, { globals }) => (
    <DescriptionList items={[]} empty={<EmptyState title={textsOf(TEXTS, globals).empty} />} />
  ),
  play: ({ canvasElement }) => {
    single(canvasElement, 'h2', HTMLHeadingElement);
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
