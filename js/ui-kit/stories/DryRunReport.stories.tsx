import { DryRunReport, type DryRunReportProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<DryRunReportProps> = {
  title: 'Components/Domain/DryRunReport',
  component: DryRunReport,
};

export default meta;

/** A publish that would release a revision and open a placement's window. */
export const Publish: Story = {
  render: () => (
    <DryRunReport
      timeZone="UTC"
      report={{
        mutations: 3,
        aggregates: [
          { kind: 'placement', count: 1 },
          { kind: 'variant', count: 1 },
        ],
        changes: [
          { aggregate: 'placement:0199a6b2-5c3e-7f10-8a4b-1c2d3e4f5a6b', from: 2, to: 3 },
          { aggregate: 'variant:0199a6b2-5c3e-7f10-8a4b-aaaaaaaaaaaa:shared', from: 5, to: 6 },
        ],
        becomesVisible: [
          {
            placement: '0199a6b2-5c3e-7f10-8a4b-1c2d3e4f5a6b',
            locale: 'da',
            at: '2026-10-03T09:30:00.000000Z',
          },
        ],
      }}
    />
  ),
  play: ({ canvasElement }) => {
    single(canvasElement, 'section[aria-label]', HTMLElement);
    check(canvasElement.querySelectorAll('h3').length === 3, 'each part has a heading');
  },
};

/** A change that creates an entry and makes nothing visible. */
export const NothingVisible: Story = {
  render: () => (
    <DryRunReport
      report={{
        mutations: 3,
        aggregates: [{ kind: 'entry', count: 1 }],
        changes: [{ aggregate: 'entry:0199a6b2-5c3e-7f10-8a4b-1c2d3e4f5a6b', from: null, to: 1 }],
        becomesVisible: [],
      }}
    />
  ),
};

export const Dark: Story = inDark(Publish);
export const ForcedColors: Story = inForcedColours(Publish);
export const Danish: Story = inDanish(Publish);
