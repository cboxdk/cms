import { NodePath, type NodePathProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<NodePathProps> = {
  title: 'Components/Domain/NodePath',
  component: NodePath,
};

export default meta;

/** Where a node sits: a screen reader reads the names with slashes between them. */
export const Default: Story = {
  render: () => <NodePath segments={['example.com', 'News', 'Sport']} />,
  play: ({ canvasElement }) => {
    const path = single(canvasElement, '.cms-node-path', HTMLSpanElement);

    check(path.textContent === 'example.com/News/Sport', 'the path reads with slashes');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
