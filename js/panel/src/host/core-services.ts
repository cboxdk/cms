// What the panel's own pages reach the host's services through (PRD 13.4): the commands they issue
// as the viewer and the notices they show. A page of the core is no contribution, so it has no
// contribution host; it runs a command through the same transport an action of a contribution
// does, the Inertia profile with an envelope of its own and a new idempotency key per call, unless
// the page keeps one, as the generic command form does per form instance, in the namespace cms
// and so without a provenance, and shows a notice in the kit's toast region. Once a command
// answered, the observers of panel.observe.command@1 are told what completed (section 3.9 of the
// panel extension architecture): the command's name and version, its outcome as the receipt says
// it, and the changeset it committed, or null; an observer cannot affect the answer. Nothing else
// of the host reaches a page this way: a page gets its data from its props.

import type { CommandAnswer, CommandOptions } from '@cboxdk/cms-panel/extend';
import type { CommandCompletedV1 } from '@cboxdk/cms-panel/experimental';
import { useEffect, useMemo, useRef } from 'react';

import { CORE_NAMESPACE } from './addons';
import { usePointHost } from './PointHost';
import { useHostRuntime } from './runtime';

/** What a page of the core does through the host. */
export interface CoreServices {
  /**
   * Runs the command, `<name>@<version>`, with the document as the viewer, and answers with the
   * receipt, the problem details of a rejection and the summary of a dry run; `key` keeps one
   * idempotency key across calls, so the same form submitted twice gives one changeset.
   */
  readonly runCommand: (
    command: string,
    document: object,
    options?: CommandOptions,
    key?: string,
  ) => Promise<CommandAnswer>;
  /** Shows a notice in the panel's toast region, its message already in the panel's locale. */
  readonly notify: (tone: 'info' | 'success' | 'warning' | 'danger', message: string) => void;
}

/**
 * The event of panel.observe.command@1 for a command that answered: its name and version, read
 * from `<name>@<version>`, the receipt's outcome and the changeset it committed.
 */
export function commandCompleted(command: string, answer: CommandAnswer): CommandCompletedV1 {
  const at = command.lastIndexOf('@');
  const version = at === -1 ? Number.NaN : Number(command.slice(at + 1));

  return {
    changeset: answer.receipt.changeset_id,
    command: at === -1 ? command : command.slice(0, at),
    outcome: answer.receipt.outcome,
    version: Number.isInteger(version) && version >= 1 ? version : 1,
  };
}

/** The host's services as a page of the core uses them. */
export function useCoreServices(): CoreServices {
  const { services } = useHostRuntime();
  // Asking the point loads the addons' code; the handle is rebuilt on every render, and the one
  // of the latest render knows which observers' code has arrived, so a command that answers
  // after an addon loaded tells its observers too.
  const observers = usePointHost('panel.observe.command@1');
  const latest = useRef(observers);

  useEffect(() => {
    latest.current = observers;
  });

  return useMemo<CoreServices>(
    () => ({
      runCommand: (command, document, options = {}, key) =>
        services.runCommand({ command, document, options, key }).then((answer) => {
          latest.current.observe(commandCompleted(command, answer));

          return answer;
        }),
      notify: (tone, message) => {
        services.notify(CORE_NAMESPACE, { tone, message });
      },
    }),
    [services],
  );
}
