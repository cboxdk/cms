import { Breadcrumbs, type BreadcrumbsProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<BreadcrumbsProps> = {
  title: 'Components/Navigation/Breadcrumbs',
  component: Breadcrumbs,
};

export default meta;

const TEXTS: Localized<{ label: string; panel: string; access: string; role: string }> = {
  da: { label: 'Brødkrummer', panel: 'Panel', access: 'Adgang', role: 'Redaktør' },
  en: { label: 'Breadcrumbs', panel: 'Panel', access: 'Access', role: 'Editor' },
};

/** The pages above as links, and the page that is shown last, marked as the current page. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Breadcrumbs
        label={texts.label}
        items={[
          { id: 'panel', label: texts.panel, href: '#panel' },
          { id: 'access', label: texts.access, href: '#access' },
          { id: 'role', label: texts.role },
        ]}
      />
    );
  },
  play: ({ canvasElement, globals }) => {
    single(canvasElement, 'nav[aria-label]', HTMLElement);
    const current = single(canvasElement, '[aria-current="page"]', HTMLElement);

    check(
      current.textContent === textsOf(TEXTS, globals).role,
      'the last item is the current page',
    );
    check(canvasElement.querySelectorAll('a[href]').length === 2, 'the pages above are links');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
