import { ReceiptStatus, type ReceiptStatusProps, type ReceiptSummary } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<ReceiptStatusProps> = {
  title: 'Components/Domain/ReceiptStatus',
  component: ReceiptStatus,
};

export default meta;

const COMMITTED: ReceiptSummary = {
  outcome: 'committed',
  wait_level: 'origin',
  changeset_id: '0199a6b2-5c3e-7f10-8a4b-1c2d3e4f5a6b',
  projections: [{ projection: 'origin', state: 'acknowledged' }],
};

/** A committed change that reached the wait level asked for. */
export const Committed: Story = {
  render: () => <ReceiptStatus receipt={COMMITTED} />,
  play: ({ canvasElement }) => {
    single(canvasElement, '[role="status"]', HTMLDivElement);
    check(
      canvasElement.textContent.includes(COMMITTED.changeset_id ?? ''),
      'it shows the changeset',
    );
  },
};

/** Committed, but the wait level was not reached in time: one projection is still updating. */
export const WaitTimeout: Story = {
  render: () => (
    <ReceiptStatus
      receipt={{
        outcome: 'committed_wait_timeout',
        wait_level: 'edge',
        changeset_id: '0199a6b2-5c3e-7f10-8a4b-1c2d3e4f5a6b',
        projections: [
          { projection: 'fragments', state: 'pending' },
          { projection: 'origin', state: 'acknowledged' },
        ],
      }}
    />
  ),
};

/** A dry run, which committed nothing. */
export const DryRun: Story = {
  render: () => (
    <ReceiptStatus
      receipt={{ outcome: 'dry_run', wait_level: 'commit', changeset_id: null, projections: [] }}
    />
  ),
  play: ({ canvasElement }) => {
    check(canvasElement.querySelector('code') === null, 'a dry run has no changeset');
  },
};

/** A refusal, which committed nothing. */
export const Rejected: Story = {
  render: () => (
    <ReceiptStatus
      receipt={{ outcome: 'rejected', wait_level: 'commit', changeset_id: null, projections: [] }}
    />
  ),
};

export const Dark: Story = inDark(WaitTimeout);
export const ForcedColors: Story = inForcedColours(WaitTimeout);
export const Danish: Story = inDanish(WaitTimeout);
