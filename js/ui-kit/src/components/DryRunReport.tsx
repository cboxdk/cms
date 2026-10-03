import { useKitTranslation } from '../i18n/translations';
import { Icon } from './Icon';
import { Timestamp } from './Timestamp';

import './receipt-status.css';
import './dry-run-report.css';

/**
 * What a dry run of a command found (PRD 6.1): its blast radius, the version change of each
 * aggregate it would commit, and the placements it would make visible.
 *
 * @experimental
 */
export interface DryRunSummary {
  /** How many mutations the plan holds. */
  readonly mutations: number;
  /** How many aggregates of each kind the plan changes, such as entry or placement. */
  readonly aggregates: readonly { readonly kind: string; readonly count: number }[];
  /** Each aggregate the commit would change: its key, the version read, or null for a new one. */
  readonly changes: readonly {
    readonly aggregate: string;
    readonly from: number | null;
    readonly to: number;
  }[];
  /** Each placement and locale that would be visible, and from when. */
  readonly becomesVisible: readonly {
    readonly placement: string;
    readonly locale: string;
    readonly at: string;
  }[];
}

/**
 * The props of DryRunReport.
 *
 * @experimental
 */
export interface DryRunReportProps {
  /** What the dry run found. */
  readonly report: DryRunSummary;
  /** The time zone to show the times in; the reader's own by default. */
  readonly timeZone?: string | undefined;
}

/**
 * The report of a dry run, which committed nothing: how far the change would reach (its blast
 * radius), what each aggregate's version would become, and what would become visible to the public
 * and when, each part with its heading in the kit's own text, so a reader can check a change before
 * making it.
 *
 * @experimental
 */
export function DryRunReport({ report, timeZone }: DryRunReportProps) {
  const t = useKitTranslation();

  return (
    <section className="cms-dry-run-report" aria-label={t('kit.dry_run.title')}>
      <p className="cms-dry-run-report__title">
        <Icon name="info" />
        {t('kit.dry_run.title')}
      </p>
      <div className="cms-dry-run-report__part">
        <h3 className="cms-dry-run-report__heading">{t('kit.dry_run.blast_radius')}</h3>
        <p className="cms-dry-run-report__text">
          {t('kit.dry_run.mutations', { count: report.mutations })}
        </p>
        <ul className="cms-dry-run-report__list">
          {report.aggregates.map((aggregate) => (
            <li key={aggregate.kind}>
              <code>{aggregate.kind}</code>: {aggregate.count}
            </li>
          ))}
        </ul>
      </div>
      <div className="cms-dry-run-report__part">
        <h3 className="cms-dry-run-report__heading">{t('kit.dry_run.changes')}</h3>
        <ul className="cms-dry-run-report__list">
          {report.changes.map((change) => (
            <li key={change.aggregate}>
              <code>{change.aggregate}</code>{' '}
              {change.from === null
                ? t('kit.dry_run.created', { to: change.to })
                : t('kit.dry_run.version', { from: change.from, to: change.to })}
            </li>
          ))}
        </ul>
      </div>
      <div className="cms-dry-run-report__part">
        <h3 className="cms-dry-run-report__heading">{t('kit.dry_run.becomes_visible')}</h3>
        {report.becomesVisible.length === 0 ? (
          <p className="cms-dry-run-report__text">{t('kit.dry_run.nothing_visible')}</p>
        ) : (
          <ul className="cms-dry-run-report__list">
            {report.becomesVisible.map((visible) => (
              <li key={`${visible.placement} ${visible.locale}`}>
                <code>{visible.placement}</code> ({visible.locale}){' '}
                <Timestamp value={visible.at} timeZone={timeZone} />
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}
