import { Pagination, type PaginationProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<PaginationProps> = {
  title: 'Components/Navigation/Pagination',
  component: Pagination,
};

export default meta;

const TEXTS: Localized<{ label: string; middle: string; first: string }> = {
  da: { label: 'Sider af adgange', middle: 'Række 21 til 40', first: 'Række 1 til 20' },
  en: { label: 'Pages of grants', middle: 'Rows 21 to 40', first: 'Rows 1 to 20' },
};

/** A page in the middle of the list: both ways are open. */
export const Middle: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Pagination
        label={texts.label}
        status={texts.middle}
        onPrevious={() => undefined}
        onNext={() => {
          document.body.dataset['paged'] = 'next';
        }}
      />
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    single(canvasElement, 'nav[aria-label]', HTMLElement);

    const [, next] = canvasElement.querySelectorAll('button');

    await userEvent.tab();
    await userEvent.tab();
    check(document.activeElement === next, 'Tab reaches Next after Previous');
    await userEvent.keyboard('{Enter}');
    check(document.body.dataset['paged'] === 'next', 'Enter goes to the next page');
    delete document.body.dataset['paged'];
  },
};

/** The first page: Previous is disabled, so the keyboard skips it. */
export const FirstPage: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return <Pagination label={texts.label} status={texts.first} onNext={() => undefined} />;
  },
  play: async ({ canvasElement, userEvent }) => {
    const [previous, next] = canvasElement.querySelectorAll('button');

    check(previous?.disabled === true, 'Previous is disabled on the first page');
    await userEvent.tab();
    check(document.activeElement === next, 'Tab skips the disabled Previous');
  },
};

export const Dark: Story = inDark(Middle);
export const ForcedColors: Story = inForcedColours(Middle);
export const Danish: Story = inDanish(Middle);
