// What the panel's own pages reach the host's services through (PRD 13.4): the commands they issue
// as the viewer and the notices they show. A page of the core is no contribution, so it has no
// contribution host; it runs a command through the same transport an action of a contribution
// does, the Inertia profile with an envelope of its own and a new idempotency key per call, in
// the namespace cms and so without a provenance, and shows a notice in the kit's toast region.
// Nothing else of the host reaches a page this way: a page gets its data from its props.

import type { CommandAnswer, CommandOptions } from '@cboxdk/cms-panel/extend';
import { useMemo } from 'react';

import { CORE_NAMESPACE } from './addons';
import { useHostRuntime } from './runtime';

/** What a page of the core does through the host. */
export interface CoreServices {
  /**
   * Runs the command, `<name>@<version>`, with the document as the viewer, and answers with the
   * receipt, the problem details of a rejection and the summary of a dry run.
   */
  readonly runCommand: (
    command: string,
    document: object,
    options?: CommandOptions,
  ) => Promise<CommandAnswer>;
  /** Shows a notice in the panel's toast region, its message already in the panel's locale. */
  readonly notify: (tone: 'info' | 'success' | 'warning' | 'danger', message: string) => void;
}

/** The host's services as a page of the core uses them. */
export function useCoreServices(): CoreServices {
  const { services } = useHostRuntime();

  return useMemo<CoreServices>(
    () => ({
      runCommand: (command, document, options = {}) =>
        services.runCommand({ command, document, options }),
      notify: (tone, message) => {
        services.notify(CORE_NAMESPACE, { tone, message });
      },
    }),
    [services],
  );
}
