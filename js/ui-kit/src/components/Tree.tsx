import type { ReactNode } from 'react';
import { Button } from 'react-aria-components/Button';
import { Collection } from 'react-aria-components/Collection';
import { Tree as AriaTree, TreeItem, TreeItemContent } from 'react-aria-components/Tree';
import type { Key, Selection } from 'react-stately';

import { Icon } from './Icon';
import { defined } from './internal/defined';
import { ProgressLabel } from './ProgressLabel';

import './tree.css';

/**
 * A node of a Tree, with the nodes below it.
 *
 * @experimental
 */
export interface TreeNode {
  /** An id unique in the tree, which the tree gives back. */
  readonly id: string;
  /** The node's name, from the data. */
  readonly label: string;
  /** The nodes below it, or undefined for a leaf. */
  readonly children?: readonly TreeNode[] | undefined;
  /** Whether the node cannot be chosen. */
  readonly disabled?: boolean | undefined;
}

/**
 * The props of Tree.
 *
 * @experimental
 */
export interface TreeProps {
  /** What the tree shows, such as "Nodes", from the caller's translations. */
  readonly label: string;
  /** The nodes at the top of the tree. */
  readonly nodes: readonly TreeNode[];
  /** single lets the reader choose one node; none, the default, only shows the tree. */
  readonly selectionMode?: 'none' | 'single';
  /** The id of the chosen node, or null for none. */
  readonly selected?: string | null | undefined;
  /** Called with the id of the node chosen, or null when the choice is cleared. */
  readonly onSelectionChange?: ((id: string | null) => void) | undefined;
  /** The ids of the open nodes; with onExpandedChange, the tree is controlled. */
  readonly expanded?: readonly string[] | undefined;
  /** The ids of the nodes open at first, when the tree is not controlled. */
  readonly defaultExpanded?: readonly string[] | undefined;
  /** Called with the ids of the open nodes when the reader opens or closes one. */
  readonly onExpandedChange?: ((ids: string[]) => void) | undefined;
  /** What the tree waits for while its nodes load, from the caller's translations. */
  readonly loading?: string | undefined;
  /** Why the nodes could not be loaded and what to do, such as an ErrorState, in place of them. */
  readonly error?: ReactNode;
  /** What the tree shows when it has no nodes, such as an EmptyState. */
  readonly empty: ReactNode;
}

function renderNode(node: TreeNode): ReactNode {
  return (
    <TreeItem
      key={node.id}
      id={node.id}
      textValue={node.label}
      isDisabled={node.disabled === true}
      className="cms-tree__item"
    >
      <TreeItemContent>
        {({ hasChildItems, isExpanded, isSelected }) => (
          <div className="cms-tree__row">
            {hasChildItems ? (
              <Button slot="chevron" className="cms-tree__chevron">
                <Icon name={isExpanded ? 'chevron-down' : 'chevron-right'} size="sm" />
              </Button>
            ) : (
              <span className="cms-tree__spacer" />
            )}
            <span className="cms-tree__label">{node.label}</span>
            {isSelected ? (
              <span className="cms-tree__check">
                <Icon name="check" size="sm" />
              </span>
            ) : null}
          </div>
        )}
      </TreeItemContent>
      {node.children === undefined ? null : (
        <Collection items={node.children}>{renderNode}</Collection>
      )}
    </TreeItem>
  );
}

function firstKey(selection: Selection): string | null {
  if (selection === 'all') {
    return null;
  }

  const [key] = [...selection];

  return key === undefined ? null : String(key);
}

/**
 * A tree of nodes, such as the content tree to pick a node in. It is a tree grid: Tab moves into it
 * in one stop, Up and Down move between the visible nodes, Right opens a node and then moves into
 * it, Left closes it and then moves to its parent, typing jumps to the node that starts with what is
 * typed, and Enter or Space chooses a node when choosing is on. The chosen node is marked with a
 * check as well as its colour.
 *
 * @experimental
 */
export function Tree({
  label,
  nodes,
  selectionMode = 'none',
  selected,
  onSelectionChange,
  expanded,
  defaultExpanded,
  onExpandedChange,
  loading,
  error,
  empty,
}: TreeProps) {
  if (error !== undefined) {
    return <>{error}</>;
  }

  if (loading !== undefined) {
    return <ProgressLabel>{loading}</ProgressLabel>;
  }

  return (
    <AriaTree
      aria-label={label}
      items={nodes}
      selectionMode={selectionMode}
      disallowEmptySelection={false}
      {...defined({
        selectedKeys: selected === undefined ? undefined : selected === null ? [] : [selected],
        expandedKeys: expanded,
        defaultExpandedKeys: defaultExpanded,
      })}
      onSelectionChange={(selection: Selection) => {
        onSelectionChange?.(firstKey(selection));
      }}
      onExpandedChange={(keys: Set<Key>) => {
        onExpandedChange?.([...keys].map((key) => String(key)));
      }}
      renderEmptyState={() => <div className="cms-tree__empty">{empty}</div>}
      className="cms-tree"
    >
      {renderNode}
    </AriaTree>
  );
}
