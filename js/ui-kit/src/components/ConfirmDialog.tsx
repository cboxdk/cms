import { Dialog as AriaDialog } from 'react-aria-components/Dialog';
import { Heading } from 'react-aria-components/Heading';

import { Button } from './Button';
import { ModalFrame } from './internal/ModalFrame';

import './dialog.css';

/**
 * The props of ConfirmDialog.
 *
 * @experimental
 */
export interface ConfirmDialogProps {
  /** The question, such as "Revoke the grant?", from the caller's translations. */
  readonly title: string;
  /** What the action does and whether it can be undone, from the caller's translations. */
  readonly message: string;
  /** The confirming button's text, which says the action, such as "Revoke". */
  readonly confirmLabel: string;
  /** The cancelling button's text, such as "Cancel". */
  readonly cancelLabel: string;
  /** danger, the default, for an action that removes or revokes; neutral for any other. */
  readonly tone?: 'danger' | 'neutral';
  /** Whether the dialog is open; the dialog is controlled. */
  readonly open: boolean;
  /** Called with false when the dialog closes by Escape, a button or a confirmation. */
  readonly onOpenChange: (open: boolean) => void;
  /** Called when the action is confirmed; the dialog then closes. */
  readonly onConfirm: () => void;
}

/**
 * A question before an action that cannot simply be undone, such as revoking a grant: an alert
 * dialog, which a screen reader announces with its message. Focus starts on the cancelling button,
 * so Enter right away does not take the action; Tab moves to the confirming one. Escape cancels,
 * and a press outside it does not, so it is never closed by accident.
 *
 * @experimental
 */
export function ConfirmDialog({
  title,
  message,
  confirmLabel,
  cancelLabel,
  tone = 'danger',
  open,
  onOpenChange,
  onConfirm,
}: ConfirmDialogProps) {
  return (
    <ModalFrame open={open} onOpenChange={onOpenChange} kind="dialog" dismissable={false}>
      <AriaDialog role="alertdialog" className="cms-dialog">
        <div className="cms-dialog__header">
          <Heading slot="title" className="cms-dialog__title">
            {title}
          </Heading>
        </div>
        <p className="cms-dialog__body cms-dialog__message">{message}</p>
        <div className="cms-dialog__footer">
          <Button
            autoFocus
            onClick={() => {
              onOpenChange(false);
            }}
          >
            {cancelLabel}
          </Button>
          <Button
            variant={tone === 'danger' ? 'danger' : 'primary'}
            onClick={() => {
              onConfirm();
              onOpenChange(false);
            }}
          >
            {confirmLabel}
          </Button>
        </div>
      </AriaDialog>
    </ModalFrame>
  );
}
