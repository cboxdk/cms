import { useKitTranslation, type KitTranslationKey } from '../i18n/translations';
import { Badge } from './Badge';
import type { Tone } from './tone';

import './receipt-status.css';

/**
 * How a command ended, as receipt.v1.json says.
 *
 * @experimental
 */
export type ReceiptOutcome = 'rejected' | 'committed' | 'committed_wait_timeout' | 'dry_run';

/**
 * How long the caller asked to wait, as receipt.v1.json says.
 *
 * @experimental
 */
export type ReceiptWaitLevel = 'commit' | 'origin' | 'edge' | 'verified' | 'propagated';

/**
 * The part of a receipt (receipt.v1.json, as the generated ReceiptV1 types it) the status shows; a
 * page passes the receipt it got as it is.
 *
 * @experimental
 */
export interface ReceiptSummary {
  /** How the command ended. */
  readonly outcome: ReceiptOutcome;
  /** How long the caller asked to wait. */
  readonly wait_level: ReceiptWaitLevel;
  /** The changeset the command committed, or null when it committed nothing. */
  readonly changeset_id: string | null;
  /** Each projection the changeset affected and whether it has caught up. */
  readonly projections: readonly {
    readonly projection: string;
    readonly state: 'pending' | 'acknowledged';
  }[];
}

/**
 * The props of ReceiptStatus.
 *
 * @experimental
 */
export interface ReceiptStatusProps {
  /** The receipt the command answered with. */
  readonly receipt: ReceiptSummary;
}

const OUTCOMES: Readonly<Record<ReceiptOutcome, { key: KitTranslationKey; tone: Tone }>> = {
  committed: { key: 'kit.receipt.committed', tone: 'success' },
  committed_wait_timeout: { key: 'kit.receipt.committed_wait_timeout', tone: 'warning' },
  dry_run: { key: 'kit.receipt.dry_run', tone: 'info' },
  rejected: { key: 'kit.receipt.rejected', tone: 'danger' },
};

const EXPLANATIONS: Readonly<Record<ReceiptOutcome, KitTranslationKey>> = {
  committed: 'kit.receipt.committed_explained',
  committed_wait_timeout: 'kit.receipt.committed_wait_timeout_explained',
  dry_run: 'kit.receipt.dry_run_explained',
  rejected: 'kit.receipt.rejected_explained',
};

const WAIT_LEVELS: Readonly<Record<ReceiptWaitLevel, KitTranslationKey>> = {
  commit: 'kit.receipt.wait.commit',
  origin: 'kit.receipt.wait.origin',
  edge: 'kit.receipt.wait.edge',
  verified: 'kit.receipt.wait.verified',
  propagated: 'kit.receipt.wait.propagated',
};

/**
 * What a command's receipt says (GUARDRAILS 8: the panel shows status from receipts): how the
 * command ended, what that means, the wait level asked for, the changeset, and which projections
 * have caught up, each in the kit's own text. It is a status, so a screen reader announces it when
 * it appears after a submit.
 *
 * @experimental
 */
export function ReceiptStatus({ receipt }: ReceiptStatusProps) {
  const t = useKitTranslation();
  const outcome = OUTCOMES[receipt.outcome];
  const acknowledged = receipt.projections.filter((status) => status.state === 'acknowledged');

  return (
    <div className="cms-receipt-status" role="status">
      <div className="cms-receipt-status__head">
        <Badge tone={outcome.tone}>{t(outcome.key)}</Badge>
        <p className="cms-receipt-status__explanation">{t(EXPLANATIONS[receipt.outcome])}</p>
      </div>
      <dl className="cms-receipt-status__facts">
        <div>
          <dt>{t('kit.receipt.wait_level')}</dt>
          <dd>{t(WAIT_LEVELS[receipt.wait_level])}</dd>
        </div>
        {receipt.changeset_id === null ? null : (
          <div>
            <dt>{t('kit.receipt.changeset')}</dt>
            <dd>
              <code>{receipt.changeset_id}</code>
            </dd>
          </div>
        )}
        {receipt.projections.length === 0 ? null : (
          <div>
            <dt>{t('kit.receipt.projections')}</dt>
            <dd>
              {t('kit.receipt.projections_caught_up', {
                done: acknowledged.length,
                total: receipt.projections.length,
              })}
              <ul className="cms-receipt-status__projections">
                {receipt.projections.map((status) => (
                  <li key={status.projection}>
                    <code>{status.projection}</code>{' '}
                    {status.state === 'acknowledged'
                      ? t('kit.receipt.projection.acknowledged')
                      : t('kit.receipt.projection.pending')}
                  </li>
                ))}
              </ul>
            </dd>
          </div>
        )}
      </dl>
    </div>
  );
}
