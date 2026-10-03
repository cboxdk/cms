// The experimental kinds of contribution (sections 3.3 and 3.7 of the panel extension
// architecture): an action with a handler of its own instead of the host's, and a form check that
// answers asynchronously.

import type { CheckContext, IssuedCommands, Issue, NoCommands } from '../contributions';
import type { CommandAnswer, CommandOptions } from '../host';

/**
 * What an action's handler receives: the point's props, and issue() for the commands the addon may
 * issue (I).
 *
 * @experimental
 */
export interface ActionContext<P, I extends IssuedCommands<I> = NoCommands> {
  readonly props: Readonly<P>;
  readonly issue: <K extends keyof I & string>(
    command: K,
    document: I[K],
    options?: CommandOptions,
  ) => Promise<CommandAnswer>;
}

/**
 * The handler of an action that needs more than the command the host runs for it.
 *
 * @experimental
 */
export type ActionHandler<P, I extends IssuedCommands<I> = NoCommands> = (
  context: ActionContext<P, I>,
) => Promise<void>;

/**
 * A form check that answers asynchronously, within 300 ms, and is cancelled through the signal on
 * the next edit.
 *
 * @experimental
 */
export type AsyncFormCheck<D> = (
  document: Readonly<D>,
  context: CheckContext & { readonly signal: AbortSignal },
) => Promise<readonly Issue[]>;
