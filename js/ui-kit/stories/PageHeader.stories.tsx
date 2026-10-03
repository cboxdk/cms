import { ActionBar, Badge, PageHeader, type PageHeaderProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<PageHeaderProps> = {
  title: 'Components/Layout/PageHeader',
  component: PageHeader,
};

export default meta;

const TEXTS: Localized<{
  title: string;
  description: string;
  state: string;
  actions: string;
  edit: string;
  deactivate: string;
}> = {
  da: {
    title: 'Ada Lovelace',
    description: 'Medarbejder med adgang til nyhederne.',
    state: 'Aktiv',
    actions: 'Handlinger for medarbejderen',
    edit: 'Redigér',
    deactivate: 'Deaktivér',
  },
  en: {
    title: 'Ada Lovelace',
    description: 'A member of staff with access to the news.',
    state: 'Active',
    actions: 'Actions for the member of staff',
    edit: 'Edit',
    deactivate: 'Deactivate',
  },
};

/** The top of a page: its one h1, a state next to it, what it is for, and its actions. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <PageHeader
        title={texts.title}
        description={texts.description}
        meta={<Badge tone="success">{texts.state}</Badge>}
        actions={
          <ActionBar
            label={texts.actions}
            actions={[
              { id: 'edit', label: texts.edit },
              { id: 'deactivate', label: texts.deactivate, variant: 'danger' },
            ]}
            onAction={() => undefined}
          />
        }
      />
    );
  },
  play: ({ canvasElement }) => {
    const heading = single(canvasElement, 'h1', HTMLHeadingElement);

    check(heading.textContent === 'Ada Lovelace', 'the h1 is the title');
    single(canvasElement, '[role="toolbar"]', HTMLDivElement);
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
