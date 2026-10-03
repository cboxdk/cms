// The surface every overlay of the kit that hangs from a control opens in: a menu, the choices of
// a select or a combobox, and the list of a multi-select. It is internal to the kit. It is built on
// React Aria's usePopover hook rather than its Popover component, whose type declarations do not
// compile under the repository's TypeScript settings (docs/ui/_index.md, Primitives).
//
// It renders in a portal at the end of the document, positioned next to its trigger, flips when
// there is no room, and closes on Escape and on a press outside it. A modal popover hides the rest
// of the page from a screen reader while it is open and traps focus in it, returning focus to the
// trigger when it closes; a non-modal one, for a combobox, keeps focus in the combobox's input.

import { useLayoutEffect, useRef, useState, type ReactNode, type RefObject } from 'react';
import { DismissButton, Overlay, usePopover, type Placement } from 'react-aria';
import type { OverlayTriggerState } from 'react-stately';

import '../shared.css';

export interface PopoverProps {
  readonly state: OverlayTriggerState;
  /** The control the popover hangs from. */
  readonly triggerRef: RefObject<Element | null>;
  /** A ref to the popover's element, when the caller needs it, such as a combobox does. */
  readonly popoverRef?: RefObject<HTMLDivElement | null>;
  readonly placement?: Placement;
  /** Whether the rest of the page stays reachable while it is open; only for a combobox. */
  readonly nonModal?: boolean;
  /** Whether the popover is at least as wide as its trigger. */
  readonly matchTriggerWidth?: boolean;
  readonly children: ReactNode;
}

export function Popover({
  state,
  triggerRef,
  popoverRef,
  placement = 'bottom start',
  nonModal = false,
  matchTriggerWidth = false,
  children,
}: PopoverProps) {
  const ownRef = useRef<HTMLDivElement>(null);
  const ref = popoverRef ?? ownRef;
  const { popoverProps, underlayProps } = usePopover(
    { triggerRef, popoverRef: ref, placement, offset: 4, isNonModal: nonModal },
    state,
  );
  const close = () => {
    state.close();
  };
  const [width, setWidth] = useState<number | undefined>(undefined);

  useLayoutEffect(() => {
    const trigger = triggerRef.current;
    setWidth(matchTriggerWidth && trigger instanceof HTMLElement ? trigger.offsetWidth : undefined);
  }, [matchTriggerWidth, triggerRef]);

  return (
    <Overlay>
      {nonModal ? null : <div {...underlayProps} className="cms-popover-underlay" />}
      <div
        {...popoverProps}
        ref={ref}
        className="cms-popover"
        style={
          width === undefined ? popoverProps.style : { ...popoverProps.style, minWidth: width }
        }
      >
        {nonModal ? null : <DismissButton onDismiss={close} />}
        {children}
        <DismissButton onDismiss={close} />
      </div>
    </Overlay>
  );
}
