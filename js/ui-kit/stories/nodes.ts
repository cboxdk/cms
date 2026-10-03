// The content tree the stories of Tree and NodePicker show: a site with its sections.

import type { TreeNode } from '@cboxdk/cms-ui-kit';

export const NODES: readonly TreeNode[] = [
  {
    id: 'site',
    label: 'example.com',
    children: [
      {
        id: 'news',
        label: 'News',
        children: [
          { id: 'sport', label: 'Sport' },
          { id: 'culture', label: 'Culture' },
        ],
      },
      { id: 'about', label: 'About' },
      { id: 'archive', label: 'Archive', disabled: true },
    ],
  },
];
