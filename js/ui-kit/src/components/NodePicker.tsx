import { useId, useState, type ReactNode } from 'react';

import { useKitTranslation } from '../i18n/translations';
import { Button } from './Button';
import { Dialog } from './Dialog';
import { describedBy, FieldDescription, FieldErrorText } from './internal/FieldParts';
import { NodePath } from './NodePath';
import { Tree, type TreeNode } from './Tree';

import './field.css';
import './node-picker.css';

/**
 * The props of NodePicker.
 *
 * @experimental
 */
export interface NodePickerProps {
  /** The field's label, such as "Node", from the caller's translations. */
  readonly label: string;
  /** What the value must be, shown below the label, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong with the value, from the caller's translations, shown below the control. */
  readonly error?: string | undefined;
  /** The content tree, or the part of it the reader may choose in. */
  readonly nodes: readonly TreeNode[];
  /** The chosen node's id, or null for none; the picker is controlled. */
  readonly value: string | null;
  /** Called with the id of the node chosen in the dialog. */
  readonly onChange: (id: string | null) => void;
  /** What the tree waits for while the nodes load, from the caller's translations. */
  readonly loading?: string | undefined;
  /** Why the nodes could not be loaded and what to do, such as an ErrorState. */
  readonly loadError?: ReactNode;
  /** What the tree shows when there is no node to choose, such as an EmptyState. */
  readonly empty: ReactNode;
  /** Whether a node must be chosen; the label is marked in the kit's own text. */
  readonly required?: boolean | undefined;
}

/** The names from the root down to the node with the id, or null when the tree has no such node. */
function pathTo(nodes: readonly TreeNode[], id: string): string[] | null {
  for (const node of nodes) {
    if (node.id === id) {
      return [node.label];
    }

    const below = node.children === undefined ? null : pathTo(node.children, id);

    if (below !== null) {
      return [node.label, ...below];
    }
  }

  return null;
}

/**
 * A field to choose a node of the content tree, such as the node of a grant: the chosen node's path,
 * and a button, named in the kit's own text, that opens a dialog with the tree. In the dialog the
 * tree has the Tree's keyboard; Enter or Space marks a node, the dialog's Choose button takes it,
 * and Escape or Cancel leaves the choice as it was. Focus returns to the button.
 *
 * @experimental
 */
export function NodePicker({
  label,
  description,
  error,
  nodes,
  value,
  onChange,
  loading,
  loadError,
  empty,
  required = false,
}: NodePickerProps) {
  const t = useKitTranslation();
  const id = useId();
  const [open, setOpen] = useState(false);
  const [marked, setMarked] = useState<string | null>(value);
  const path = value === null ? null : pathTo(nodes, value);

  return (
    <div className="cms-field">
      <p id={`${id}-label`} className="cms-field__label">
        {label}
        {required ? (
          <span className="cms-field__required" aria-hidden="true">
            {t('kit.field.required')}
          </span>
        ) : null}
      </p>
      {description === undefined ? null : (
        <FieldDescription id={`${id}-description`}>{description}</FieldDescription>
      )}
      <div
        className="cms-node-picker"
        role="group"
        aria-labelledby={`${id}-label`}
        aria-describedby={describedBy(
          description !== undefined && `${id}-description`,
          error !== undefined && `${id}-error`,
        )}
      >
        <span className="cms-node-picker__value" data-empty={path === null}>
          {path === null ? t('kit.picker.none') : <NodePath segments={path} />}
        </span>
        <Button
          onClick={() => {
            setMarked(value);
            setOpen(true);
          }}
        >
          {path === null ? t('kit.picker.choose') : t('kit.picker.change')}
        </Button>
      </div>
      {error === undefined ? null : <FieldErrorText id={`${id}-error`}>{error}</FieldErrorText>}
      <Dialog
        title={label}
        open={open}
        onOpenChange={setOpen}
        footer={
          <>
            <Button
              onClick={() => {
                setOpen(false);
              }}
            >
              {t('kit.picker.cancel')}
            </Button>
            <Button
              variant="primary"
              disabled={marked === null}
              onClick={() => {
                onChange(marked);
                setOpen(false);
              }}
            >
              {t('kit.picker.choose')}
            </Button>
          </>
        }
      >
        <Tree
          label={label}
          nodes={nodes}
          selectionMode="single"
          selected={marked}
          onSelectionChange={setMarked}
          {...(value === null
            ? {}
            : { defaultExpanded: (pathIds(nodes, value) ?? []).slice(0, -1) })}
          loading={loading}
          error={loadError}
          empty={empty}
        />
      </Dialog>
    </div>
  );
}

/** The ids from the root down to the node with the id. */
function pathIds(nodes: readonly TreeNode[], id: string): string[] | null {
  for (const node of nodes) {
    if (node.id === id) {
      return [node.id];
    }

    const below = node.children === undefined ? null : pathIds(node.children, id);

    if (below !== null) {
      return [node.id, ...below];
    }
  }

  return null;
}
