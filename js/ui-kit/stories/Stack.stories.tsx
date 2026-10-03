import { Badge, Button, Inline, Stack, type StackProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  textsOf,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<StackProps> = {
  title: 'Components/Layout/Stack',
  component: Stack,
};

export default meta;

const TEXTS: Localized<{ first: string; second: string; third: string }> = {
  da: { first: 'Første', second: 'Anden', third: 'Tredje' },
  en: { first: 'First', second: 'Second', third: 'Third' },
};

/** Children one below the other, at each of the kit's gaps. */
export const Gaps: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Inline gap="xl" align="start">
        {(['xs', 'sm', 'md', 'lg'] as const).map((gap) => (
          <Stack key={gap} gap={gap}>
            <Badge>{texts.first}</Badge>
            <Badge>{texts.second}</Badge>
            <Button>{texts.third}</Button>
          </Stack>
        ))}
      </Inline>
    );
  },
  play: ({ canvasElement }) => {
    const stack = single(canvasElement, '[data-gap="md"]', HTMLDivElement);

    check(getComputedStyle(stack).flexDirection === 'column', 'a stack is a column');
  },
};

export const Dark: Story = inDark(Gaps);
export const ForcedColors: Story = inForcedColours(Gaps);
export const Danish: Story = inDanish(Gaps);
