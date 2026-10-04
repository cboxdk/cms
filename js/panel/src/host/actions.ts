// How the host runs an action (section 3.3 of the panel extension architecture): a kit button that
// runs one command as the viewer, prefilled from the point's props, through the contribution's own
// host, so the addon's issues limit holds and the call carries the provenance
// `addon:<namespace>:<contribution>`. Before it runs, the action asks as its manifest says: none
// runs at once; confirm asks the viewer in the kit's confirmation dialog; dry_run runs the command
// as a dry run first and shows what it would change, the DryRunSummary of the receipt, before the
// viewer confirms the command for real; form opens the command's form, which the page does. The
// receipt of a run, and the problem details of a rejection, are shown where the action is.

import type { CommandAnswer, PanelHost } from '@cboxdk/cms-panel/extend';
import type { DryRunSummary } from '@cboxdk/cms-ui-kit';

import type { DryRunSummaryV1 } from '../generated/protocol/DryRunSummaryV1';
import type { AnyCommands } from './panel-host';

/** How an action asks before it runs its command, as its manifest says. */
export type ActionConfirm = 'none' | 'confirm' | 'dry_run' | 'form';

/** What a run of an action came to. */
export type ActionOutcome =
  /** The command ran, as a dry run or for real; the answer says how it ended. */
  | { readonly status: 'answered'; readonly answer: CommandAnswer; readonly dryRun: boolean }
  /** The viewer did not confirm, so nothing ran. */
  | { readonly status: 'cancelled' }
  /** The call could not be made or was not answered with a receipt. */
  | { readonly status: 'failed'; readonly failure: unknown };

/** What a run of an action needs from the host besides the contribution's own host. */
export interface ActionRunServices {
  /** Asks the viewer to confirm the command, as the kit's confirmation dialog does. */
  readonly confirm: () => Promise<boolean>;
  /** Shows the viewer what the dry run found, and answers whether they confirmed the command. */
  readonly review: (summary: DryRunSummaryV1, answer: CommandAnswer) => Promise<boolean>;
}

/**
 * Runs the command of an action with the document the point's props prefilled, asking first as
 * the action's confirm says. A form action is not run here: the page opens the form.
 */
export async function runAction(
  host: PanelHost<AnyCommands>,
  command: string,
  document: object,
  confirm: ActionConfirm,
  services: ActionRunServices,
): Promise<ActionOutcome> {
  try {
    if (confirm === 'confirm' && !(await services.confirm())) {
      return { status: 'cancelled' };
    }

    if (confirm === 'dry_run') {
      const trial = await host.runCommand(command, document, { dryRun: true });

      if (trial.dryRun === null) {
        // The dry run was rejected, so the command would be too: its problem is the answer.
        return { status: 'answered', answer: trial, dryRun: true };
      }

      if (!(await services.review(trial.dryRun, trial))) {
        return { status: 'cancelled' };
      }
    }

    return { status: 'answered', answer: await host.runCommand(command, document), dryRun: false };
  } catch (failure: unknown) {
    return { status: 'failed', failure };
  }
}

/** The summary of a dry run as the kit's DryRunReport shows it. */
export function dryRunReport(summary: DryRunSummaryV1): DryRunSummary {
  return {
    mutations: summary.blast_radius.mutations,
    aggregates: summary.blast_radius.aggregates.map((count) => ({
      kind: count.kind,
      count: count.count,
    })),
    changes: summary.changes.map((change) => ({
      aggregate: change.aggregate,
      from: change.before,
      to: change.after,
    })),
    becomesVisible: summary.becomes_visible.map((visible) => ({
      placement: visible.placement,
      locale: visible.locale,
      at: visible.from,
    })),
  };
}
