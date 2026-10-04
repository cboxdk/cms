// What the host reports when a contribution does not do what its contract says (section 5.4 of
// the panel extension architecture): a code, the addon, and the point and contribution it is
// about, never a message text a contribution wrote (GUARDRAILS 5, invariant 10), so the report
// can be counted in telemetry. The viewer sees a compact notice that names the addon instead of
// what failed; a viewer with internal access sees the detail beside it.

/** The codes the host reports, each a word of the panel's own, `panel_<what>`. */
export const HOST_REPORT_CODES = [
  'panel_addon_mismatch',
  'panel_addon_unavailable',
  'panel_contribution_failed',
  'panel_replacement_failed',
  'panel_decorator_failed',
  'panel_decorator_tightening_refused',
  'panel_check_failed',
  'panel_check_over_budget',
  'panel_check_issue_refused',
  'panel_step_failed',
  'panel_step_timed_out',
  'panel_step_patch_refused',
  'panel_observer_failed',
  'panel_point_kind_mismatch',
  'panel_command_refused',
  'panel_navigation_refused',
  'panel_action_failed',
  'panel_action_unhandled',
] as const;

/** A code the host reports. */
export type HostReportCode = (typeof HOST_REPORT_CODES)[number];

/** One report: what went wrong, and whose contribution it was. */
export interface HostReport {
  readonly code: HostReportCode;
  /** The namespace of the addon, or cms for the core's own. */
  readonly addon: string;
  /** The point, `<name>@<version>`, or undefined when the report is about the addon as a whole. */
  readonly point?: string | undefined;
  /** The contribution's id, or undefined when the report is about the addon as a whole. */
  readonly contribution?: string | undefined;
}

/** Where the host sends its reports. */
export type HostReporter = (report: HostReport) => void;

/**
 * The detail of what was thrown, for a viewer whose access lets them see it: the name of the
 * error and its message. It is shown, never reported.
 */
export function failureDetail(failure: unknown): string {
  if (failure instanceof Error) {
    return failure.message === '' ? failure.name : `${failure.name}: ${failure.message}`;
  }

  return typeof failure === 'string' ? failure : NOT_AN_ERROR;
}

/** The detail of a throw of something that is not an Error, which tells nothing more. */
const NOT_AN_ERROR = 'A value that is not an Error was thrown.';

/** What was thrown, as an Error a boundary can show. */
export function asError(failure: unknown): Error {
  return failure instanceof Error ? failure : new Error(failureDetail(failure));
}
