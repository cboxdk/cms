// How the host runs a command a contribution issues (section 3.12 of the panel extension
// architecture): through the panel's Inertia profile, `POST <commands>/<name>/v<version>`, as the
// viewer, with an envelope of its own: a new idempotency key per call, the wait level, whether it
// is a dry run, and the provenance `addon:<namespace>:<contribution>` of the contribution that
// issued it, as a source of the envelope's provenance, which the changeset records: attribution,
// not a control (section 5.5). The profile answers every call with a redirect back to the page, the
// receipt flashed, the summary of a dry run flashed beside it and, for a rejection, the problem
// details as the page prop `problem`; the host answers the contribution with all three, read by
// their generated validators.

import type { CommandAnswer, CommandOptions } from '@cboxdk/cms-panel/extend';

import { validateDryRunSummaryV1 } from '../generated/protocol/DryRunSummaryV1';
import { validateProblemV1 } from '../generated/protocol/ProblemV1';
import { validateReceiptV1 } from '../generated/protocol/ReceiptV1';

/** One command a contribution issues, by its name and version, with its document. */
export interface CommandCall {
  /** The command and version, `<name>@<version>`, such as approvals.request@1. */
  readonly command: string;
  readonly document: object;
  readonly options: CommandOptions;
  /**
   * The provenance of the call, `addon:<namespace>:<contribution>` for a contribution of an addon,
   * sent as a source of the envelope's provenance; undefined for the core's own.
   */
  readonly provenance?: string | undefined;
}

/** The provenance of a command a contribution of an addon issues. */
export function provenanceOf(addon: string, contribution: string): string {
  return `addon:${addon}:${contribution}`;
}

/** Runs a command as the viewer and answers with what the kernel answered. */
export type CommandTransport = (call: CommandCall) => Promise<CommandAnswer>;

/** Thrown when a call cannot be made, or the answer holds no receipt. */
export class CommandUnanswered extends Error {
  public constructor(message: string) {
    super(message);
    this.name = 'CommandUnanswered';
  }
}

/** The part of Inertia's router the transport uses. */
export interface CommandRouter {
  post(
    url: string,
    data: Readonly<Record<string, unknown>>,
    options: {
      readonly preserveState: boolean;
      readonly preserveScroll: boolean;
      readonly onFlash: (flash: Readonly<Record<string, unknown>>) => void;
      readonly onFinish: () => void;
    },
  ): void;
  on(
    event: 'navigate',
    callback: (event: { readonly detail: { readonly page: { readonly props: object } } }) => void,
  ): () => void;
}

/** The form of a command's name and version. */
const COMMAND = /^(?<name>[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+)@(?<version>[1-9][0-9]*)$/;

/** The address of a command's call below the profile's address. */
export function commandUrl(commands: string, command: string): string {
  const match = COMMAND.exec(command);

  if (match?.groups === undefined) {
    throw new CommandUnanswered(
      `"${command}" is not a command and version, such as approvals.request@1.`,
    );
  }

  return `${commands.replace(/\/+$/, '')}/${match.groups.name ?? ''}/v${match.groups.version ?? ''}`;
}

/**
 * The transport over the panel's Inertia profile at the address `commands`; `key` gives each
 * call a new idempotency key.
 */
export function inertiaCommands(
  router: CommandRouter,
  commands: string,
  key: () => string = () => crypto.randomUUID(),
): CommandTransport {
  return (call) =>
    new Promise<CommandAnswer>((resolve, reject) => {
      const url = commandUrl(commands, call.command);
      let receipt: unknown;
      let dryRun: unknown;
      let problem: unknown = null;
      const stop = router.on('navigate', (event) => {
        problem = 'problem' in event.detail.page.props ? event.detail.page.props.problem : null;
      });

      router.post(
        url,
        {
          envelope: {
            dry_run: call.options.dryRun ?? false,
            idempotency_key: key(),
            wait_level: call.options.waitLevel ?? 'commit',
            ...(call.provenance === undefined
              ? {}
              : { provenance: { sources: [call.provenance] } }),
          },
          command: call.document,
        },
        {
          preserveState: true,
          preserveScroll: true,
          onFlash: (flash) => {
            receipt = flash.receipt;
            dryRun = flash.dry_run;
          },
          onFinish: () => {
            stop();
            const checkedReceipt = validateReceiptV1(receipt);
            const checkedProblem = problem === null ? null : validateProblemV1(problem);
            const checkedDryRun = dryRun === undefined ? null : validateDryRunSummaryV1(dryRun);

            if (!checkedReceipt.valid) {
              reject(
                new CommandUnanswered(`The panel answered ${call.command} without a receipt.`),
              );

              return;
            }

            resolve({
              receipt: checkedReceipt.value,
              problem:
                checkedProblem !== null && checkedProblem.valid ? checkedProblem.value : null,
              dryRun: checkedDryRun !== null && checkedDryRun.valid ? checkedDryRun.value : null,
            });
          },
        },
      );
    });
}
