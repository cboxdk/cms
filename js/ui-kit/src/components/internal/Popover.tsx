// The surface every overlay of the kit that hangs from a control opens in: a menu, the choices of
// a select or a combobox, and the list of a multi-select. It is internal to the kit. It is built on
// React Aria's usePopover hook rather than its Popover component, whose type declarations do not
// compile under the repository's TypeScript settings (docs/ui/_index.md, Primitives).
//
// It renders in a portal at the end of the document, positioned next to its trigger, flips when
// there is no room, and closes on Escape and on a press outside it. A modal popover hides the rest
// of the page from a screen reader while it is open and traps focus in it, returning focus to the
// trigger when it closes; a non-modal one, for a combobox, keeps focus in the combobox's input.
//
// It keeps hanging from its trigger while it is open. React Aria places it when it opens, when the
// window resizes and when the popover or its trigger changes size, but not when the trigger moves,
// and a choice can move it with the list still open: the tags a MultiSelect adds below its button
// change the height of a dialog centred on the screen, which moves the button. So the popover
// places itself with React Aria's useOverlayPosition rather than through usePopover, whose
// placement it switches off, and after every render in which the trigger has moved it is placed
// again. Left where it was, it would not only hang apart from the trigger: the next time React Aria
// places it from its ResizeObserver, the first time after it opens included, its height (the room
// left beside the trigger) would change inside the observer's callback, which the browser reports
// as an error, "ResizeObserver loop completed with undelivered notifications".

import { useLayoutEffect, useRef, useState, type ReactNode, type RefObject } from 'react';
import { DismissButton, Overlay, useOverlayPosition, usePopover, type Placement } from 'react-aria';
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
    {
      triggerRef,
      popoverRef: ref,
      placement,
      offset: 4,
      isNonModal: nonModal,
      shouldUpdatePosition: false,
    },
    state,
  );
  const position = useOverlayPosition({
    targetRef: triggerRef,
    overlayRef: ref,
    placement,
    offset: 4,
    isOpen: state.isOpen,
  });
  const placedBeside = useRef<DOMRect | null>(null);
  const close = () => {
    state.close();
  };
  const [width, setWidth] = useState<number | undefined>(undefined);

  useLayoutEffect(() => {
    const trigger = triggerRef.current;
    setWidth(matchTriggerWidth && trigger instanceof HTMLElement ? trigger.offsetWidth : undefined);
  }, [matchTriggerWidth, triggerRef]);

  // After every render: the trigger's rectangle the last time, and the popover placed again when
  // the trigger has moved or changed size since. Placing it renders again, with the trigger where
  // it was, so the effect settles.
  useLayoutEffect(() => {
    const trigger = triggerRef.current;

    if (trigger === null) {
      return;
    }

    const rectangle = trigger.getBoundingClientRect();
    const last = placedBeside.current;
    placedBeside.current = rectangle;

    if (last !== null && !sameRectangle(last, rectangle)) {
      position.updatePosition();
    }
  });

  return (
    <Overlay>
      {nonModal ? null : <div {...underlayProps} className="cms-popover-underlay" />}
      <div
        {...popoverProps}
        ref={ref}
        className="cms-popover"
        style={
          width === undefined
            ? position.overlayProps.style
            : { ...position.overlayProps.style, minWidth: width }
        }
      >
        {nonModal ? null : <DismissButton onDismiss={close} />}
        {children}
        <DismissButton onDismiss={close} />
      </div>
    </Overlay>
  );
}

/** Whether two rectangles are at the same place and of the same size. */
function sameRectangle(a: DOMRect, b: DOMRect): boolean {
  return a.top === b.top && a.left === b.left && a.width === b.width && a.height === b.height;
}
