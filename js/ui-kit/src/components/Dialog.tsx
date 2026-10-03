import type { ReactNode } from 'react';
import { Dialog as AriaDialog } from 'react-aria-components/Dialog';
import { Heading } from 'react-aria-components/Heading';

import { useKitTranslation } from '../i18n/translations';
import { IconButton } from './IconButton';
import { ModalFrame } from './internal/ModalFrame';

import './dialog.css';

/**
 * The props of Dialog.
 *
 * @experimental
 */
export interface DialogProps {
  /** The dialog's heading, which names it, from the caller's translations. */
  readonly title: string;
  /** Whether the dialog is open; the dialog is controlled. */
  readonly open: boolean;
  /** Called with false when the dialog closes by Escape, a press outside it or its close button. */
  readonly onOpenChange: (open: boolean) => void;
  /** The dialog's content, such as a form. */
  readonly children: ReactNode;
  /** The dialog's buttons, below its content. */
  readonly footer?: ReactNode;
}

/**
 * A modal dialog, such as a form to assign a grant: it opens over the page, which a screen reader
 * then leaves out and the keyboard cannot reach. Focus moves into the dialog, to its first field or
 * button, and Tab and Shift+Tab stay inside it. Escape, a press outside it and its close button,
 * named in the kit's own text, close it, and focus returns to what opened it.
 *
 * @experimental
 */
export function Dialog({ title, open, onOpenChange, children, footer }: DialogProps) {
  const t = useKitTranslation();

  return (
    <ModalFrame open={open} onOpenChange={onOpenChange} kind="dialog" dismissable>
      <AriaDialog className="cms-dialog">
        <div className="cms-dialog__header">
          <Heading slot="title" className="cms-dialog__title">
            {title}
          </Heading>
          <IconButton
            label={t('kit.close')}
            icon="close"
            onClick={() => {
              onOpenChange(false);
            }}
          />
        </div>
        <div className="cms-dialog__body">{children}</div>
        {footer === undefined ? null : <div className="cms-dialog__footer">{footer}</div>}
      </AriaDialog>
    </ModalFrame>
  );
}
