// Failure isolation (section 5.4 of the panel extension architecture): every contribution renders
// inside an error boundary of its own, so one that throws while it renders, or whose module fails
// to load, leaves every other contribution and the page rendered. The failure is reported with the
// addon, the point and the contribution, never its message, and the viewer sees a compact notice
// that names the addon; a viewer with internal access sees what was thrown beside it.

import { Callout } from '@cboxdk/cms-ui-kit';
import { Component, type ReactNode } from 'react';

import { useTranslation } from '../i18n/translations';
import { CORE_NAMESPACE } from './addons';
import { failureDetail, type HostReport } from './reports';
import { useHostRuntime } from './runtime';

/** The props of ContributionBoundary. */
export interface ContributionBoundaryProps {
  /** The report of a failure, without its detail. */
  readonly report: HostReport;
  /** Called once with the report when what it wraps fails. */
  readonly onFailure: (report: HostReport) => void;
  /** What renders in place of what failed, given what was thrown. */
  readonly fallback: (failure: unknown) => ReactNode;
  readonly children: ReactNode;
}

interface BoundaryState {
  readonly failed: boolean;
  readonly failure: unknown;
}

/** An error boundary around one contribution. */
export class ContributionBoundary extends Component<ContributionBoundaryProps, BoundaryState> {
  public override state: BoundaryState = { failed: false, failure: undefined };

  public static getDerivedStateFromError(failure: unknown): BoundaryState {
    return { failed: true, failure };
  }

  public override componentDidCatch(): void {
    this.props.onFailure(this.props.report);
  }

  public override render(): ReactNode {
    return this.state.failed ? this.props.fallback(this.state.failure) : this.props.children;
  }
}

/** The name the viewer knows an addon by: its namespace, or the panel for the core's own. */
export function useAddonName(addon: string): string {
  const { t } = useTranslation();

  return addon === CORE_NAMESPACE ? t('panel.host.core') : addon;
}

/** The props of FailedContribution. */
export interface FailedContributionProps {
  readonly addon: string;
  readonly failure: unknown;
}

/** The notice in place of a contribution that failed. */
export function FailedContribution({ addon, failure }: FailedContributionProps) {
  const { t } = useTranslation();
  const runtime = useHostRuntime();
  const name = useAddonName(addon);

  return (
    <Callout tone="warning" title={t('panel.host.failed_title', { addon: name })}>
      {runtime.contributions.details ? failureDetail(failure) : t('panel.host.failed_body')}
    </Callout>
  );
}

/** The props of UnavailableAddon. */
export interface UnavailableAddonProps {
  readonly addon: string;
}

/** The notice in place of the contributions of an addon whose code could not be used. */
export function UnavailableAddon({ addon }: UnavailableAddonProps) {
  const { t } = useTranslation();
  const name = useAddonName(addon);

  return (
    <Callout tone="warning" title={t('panel.host.unavailable_title', { addon: name })}>
      {t('panel.host.unavailable_body', { addon: name })}
    </Callout>
  );
}
