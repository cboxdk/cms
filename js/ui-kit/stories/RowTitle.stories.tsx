import { RowTitle, type RowTitleProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  textsOf,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<RowTitleProps> = {
  title: 'Components/Data display/RowTitle',
  component: RowTitle,
};

export default meta;

const TEXTS: Localized<{ title: string; long: string; type: string; untitled: string }> = {
  da: {
    title: 'Stemmerne blev talt hurtigere end nogensinde i Aarhus',
    long: 'Valgnatten minut for minut, med resultaterne fra hver kommune, hver kreds og hvert eneste valgsted, efterhånden som de kom ind',
    type: 'Artikel',
    untitled: 'Uden titel',
  },
  en: {
    title: 'Ballots counted faster than ever in Aarhus',
    long: 'Results night minute by minute, with the figures from every municipality, every district and every single polling station as they came in',
    type: 'Article',
    untitled: 'Untitled',
  },
};

/** A title with what the row is below it, a long title cut with an ellipsis, and a row without a title. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <div style={{ display: 'grid', gap: '1rem', maxInlineSize: '24rem' }}>
        <RowTitle title={texts.title} detail={texts.type} />
        <RowTitle title={texts.long} detail={texts.type} />
        <RowTitle title={texts.untitled} detail={texts.type} fallback />
        <RowTitle title={texts.title} />
      </div>
    );
  },
  play: ({ canvasElement }) => {
    check(canvasElement.querySelectorAll('.cms-row-title').length === 4, 'every title is shown');
    check(
      canvasElement.querySelectorAll('.cms-row-title__detail').length === 3,
      'the detail is shown only when given',
    );
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
