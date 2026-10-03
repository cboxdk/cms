import { useKitTranslation, type KitTranslationKey } from '../i18n/translations';
import { Badge } from './Badge';

import './actor-chip.css';

/**
 * The class of an actor (PRD 5.16).
 *
 * @experimental
 */
export type ActorClass = 'staff' | 'end_user' | 'service';

/**
 * The state of an actor (PRD 5.16).
 *
 * @experimental
 */
export type ActorState = 'pending' | 'active' | 'deactivated' | 'deprovisioned';

/**
 * The props of ActorChip.
 *
 * @experimental
 */
export interface ActorChipProps {
  /** The actor's name, from its profile. */
  readonly name: string;
  /** The actor's email, shown below the name, or undefined. */
  readonly email?: string | undefined;
  /** The actor's class, shown after the email in the kit's own text. */
  readonly actorClass?: ActorClass | undefined;
  /** The actor's state; anything but active is shown next to the name. */
  readonly state?: ActorState | undefined;
}

const CLASSES: Readonly<Record<ActorClass, KitTranslationKey>> = {
  staff: 'kit.actor.class.staff',
  end_user: 'kit.actor.class.end_user',
  service: 'kit.actor.class.service',
};

const STATES: Readonly<Record<Exclude<ActorState, 'active'>, KitTranslationKey>> = {
  pending: 'kit.actor.state.pending',
  deactivated: 'kit.actor.state.deactivated',
  deprovisioned: 'kit.actor.state.deprovisioned',
};

/** The initials of a name: the first letters of its first and last words. */
function initials(name: string): string {
  const words = name
    .trim()
    .split(/\s+/)
    .filter((word) => word !== '');
  const first = words[0]?.[0] ?? '';
  const last = words.length > 1 ? (words[words.length - 1]?.[0] ?? '') : '';

  return (first + last).toUpperCase();
}

/**
 * An actor where it is named, such as in a list of grants: its initials in a circle, which are
 * decoration, its name, its email and class, and its state when it is not active.
 *
 * @experimental
 */
export function ActorChip({ name, email, actorClass, state }: ActorChipProps) {
  const t = useKitTranslation();
  const facts = [email, actorClass === undefined ? undefined : t(CLASSES[actorClass])].filter(
    (fact) => fact !== undefined,
  );

  return (
    <span className="cms-actor-chip">
      <span className="cms-actor-chip__avatar" aria-hidden="true">
        {initials(name)}
      </span>
      <span className="cms-actor-chip__text">
        <span className="cms-actor-chip__name">
          {name}
          {state === undefined || state === 'active' ? null : (
            <Badge tone={state === 'pending' ? 'warning' : 'neutral'}>{t(STATES[state])}</Badge>
          )}
        </span>
        {facts.length === 0 ? null : (
          <span className="cms-actor-chip__facts">{facts.join(' · ')}</span>
        )}
      </span>
    </span>
  );
}
