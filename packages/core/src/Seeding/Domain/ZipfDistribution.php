<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * A Zipf distribution over the positions 0 to size - 1: position k has the weight 1 / (k + 1)^s, so
 * the first position is the most frequent and the tail is long. An exponent of 0 is an even
 * distribution. It skews a seeded data set (PRD 23): the type mix, the entries per node and the
 * choices of a field.
 */
#[Internal]
final readonly class ZipfDistribution
{
    /** @var non-empty-list<float> the cumulative share of each position, the last 1.0 */
    private array $cumulative;

    /**
     * @throws InvalidArgumentException for no positions or a negative exponent
     */
    public function __construct(public int $size, public float $exponent)
    {
        if ($size < 1 || $exponent < 0) {
            throw new InvalidArgumentException(sprintf('A Zipf distribution has at least one position and an exponent of 0 or more, got %d and %F.', $size, $exponent));
        }

        $weights = [];
        $total = 0.0;

        for ($position = 0; $position < $size; $position++) {
            $weight = 1.0 / (($position + 1) ** $exponent);
            $total += $weight;
            $weights[] = $total;
        }

        $cumulative = [];

        foreach ($weights as $position => $sum) {
            $cumulative[] = $position === $size - 1 ? 1.0 : $sum / $total;
        }

        $this->cumulative = $cumulative;
    }

    /**
     * A position drawn from the distribution.
     */
    public function pick(Randomizer $random): int
    {
        $draw = $random->nextFloat();
        $low = 0;
        $high = $this->size - 1;

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($draw < $this->cumulative[$middle]) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $low;
    }

    /**
     * The share of draws that fall on the position, 0 to 1.
     */
    public function share(int $position): float
    {
        if ($position < 0 || $position >= $this->size) {
            return 0.0;
        }

        return $this->cumulative[$position] - ($position === 0 ? 0.0 : $this->cumulative[$position - 1]);
    }
}
