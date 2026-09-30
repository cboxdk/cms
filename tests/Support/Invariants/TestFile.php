<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Invariants;

/**
 * The tests a test file declares, read from its tokens by TestFileReader, and which of them the
 * source skips.
 */
final readonly class TestFile
{
    /**
     * @param  list<string>  $tests  the names of the tests, in the order of the source
     * @param  list<string>  $skipped  the names of the tests the source skips one by one
     * @param  bool  $skipsAll  whether the source skips or may skip every test of the file
     */
    public function __construct(
        public array $tests,
        public array $skipped,
        public bool $skipsAll,
    ) {}

    public function declares(string $name): bool
    {
        return in_array($name, $this->tests, true);
    }

    public function skips(string $name): bool
    {
        return $this->skipsAll || in_array($name, $this->skipped, true);
    }
}
