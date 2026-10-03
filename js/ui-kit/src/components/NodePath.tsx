import { Icon } from './Icon';

import './node-path.css';
import './shared.css';

/**
 * The props of NodePath.
 *
 * @experimental
 */
export interface NodePathProps {
  /** The names of the nodes from the root down to the node, from the data. */
  readonly segments: readonly string[];
}

/**
 * Where a node sits in the content tree, such as the node a grant is on: the names of its
 * ancestors and its own, separated by arrows a screen reader skips, and read as a slash between
 * names. A long path wraps at its separators.
 *
 * @experimental
 */
export function NodePath({ segments }: NodePathProps) {
  return (
    <span className="cms-node-path">
      {segments.map((segment, index) => (
        <span key={`${String(index)}-${segment}`} className="cms-node-path__segment">
          {index === 0 ? null : (
            <>
              <span className="cms-node-path__separator" aria-hidden="true">
                <Icon name="chevron-right" size="sm" />
              </span>
              <span className="cms-visually-hidden">/</span>
            </>
          )}
          <span className="cms-node-path__name" data-last={index === segments.length - 1}>
            {segment}
          </span>
        </span>
      ))}
    </span>
  );
}
