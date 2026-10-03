import { Toolbar } from 'react-aria-components/Toolbar';

import { useKitTranslation } from '../i18n/translations';
import { Button, type ButtonVariant } from './Button';
import type { IconName } from './Icon';
import { Menu } from './Menu';

import './action-bar.css';

/**
 * An action of an ActionBar.
 *
 * @experimental
 */
export interface ActionBarAction {
  /** An id unique among its siblings, which the component gives back. */
  readonly id: string;
  /** What the action does, from the caller's translations. */
  readonly label: string;
  /** How much the action's button draws the eye; secondary by default. */
  readonly variant?: ButtonVariant;
  /** An icon before the action's text. */
  readonly icon?: IconName | undefined;
  /** Whether the action cannot be taken now; its button is dimmed and skipped. */
  readonly disabled?: boolean | undefined;
}

/**
 * The props of ActionBar.
 *
 * @experimental
 */
export interface ActionBarProps {
  /** What the actions act on, such as "Actions for the role", from the caller's translations. */
  readonly label: string;
  /** The actions, the most used first. */
  readonly actions: readonly ActionBarAction[];
  /** Called with the id of the action taken. */
  readonly onAction: (id: string) => void;
  /**
   * How many actions are shown as buttons; the rest are in a menu at the end, named in the kit's
   * own text. 3 by default.
   */
  readonly visible?: number;
}

/**
 * The actions of a page or a selection in a row, such as the page header's actions. It is a
 * toolbar: one stop of the Tab order, in which Left and Right move between the actions, Home and
 * End go to the first and the last, and Enter or Space takes the action. The actions past the
 * visible ones go in an overflow menu, so the row never wraps into an unreadable pile.
 *
 * @experimental
 */
export function ActionBar({ label, actions, onAction, visible = 3 }: ActionBarProps) {
  const t = useKitTranslation();
  const shown = actions.slice(0, Math.max(0, visible));
  const overflow = actions.slice(Math.max(0, visible));

  return (
    <Toolbar aria-label={label} className="cms-action-bar">
      {shown.map((action) => (
        <Button
          key={action.id}
          variant={action.variant ?? 'secondary'}
          icon={action.icon}
          disabled={action.disabled === true}
          onClick={() => {
            onAction(action.id);
          }}
        >
          {action.label}
        </Button>
      ))}
      {overflow.length === 0 ? null : (
        <Menu
          trigger={{ label: t('kit.menu.more'), icon: 'more' }}
          items={overflow.map((action) => ({
            id: action.id,
            label: action.label,
            tone: action.variant === 'danger' ? 'danger' : 'neutral',
            disabled: action.disabled,
          }))}
          onAction={onAction}
        />
      )}
    </Toolbar>
  );
}
