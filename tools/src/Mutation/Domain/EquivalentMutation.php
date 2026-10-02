<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use InvalidArgumentException;

/**
 * One entry of the list of equivalent mutations (EquivalentMutations): a mutation of a source
 * below packages/<package>/src that no test can catch, because the mutated code behaves as the
 * original does, named by the source, the line the mutated code starts on and Pest's mutator, as
 * Pest reports it, with the reason it is equivalent. Pest's id of a mutation is not used, because
 * it hashes the file's real path and so differs between checkouts.
 */
final readonly class EquivalentMutation
{
    /** A PHP file below packages/<package>/src, relative to the root of the checkout. */
    public const string SOURCE_PATTERN = '#^packages/[a-z][a-z0-9-]*/src/(?:[A-Za-z0-9_]+/)*[A-Za-z0-9_]+\.php$#';

    /** A mutator of pest-plugin-mutate, by class name. */
    public const string MUTATOR_PATTERN = '#^Pest\\\\Mutate\\\\Mutators\\\\(?:[A-Za-z0-9]+\\\\)+[A-Za-z0-9]+$#';

    /**
     * @param  string  $path  the source, relative to the root of the checkout
     * @param  int  $line  the line the mutated code starts on, as Pest reports it
     * @param  string  $mutator  Pest's mutator, by class name
     * @param  string  $reason  why the mutated code behaves as the original does
     */
    public function __construct(
        public string $path,
        public int $line,
        public string $mutator,
        public string $reason,
    ) {
        if (preg_match(self::SOURCE_PATTERN, $path) !== 1) {
            throw new InvalidArgumentException("An equivalent mutation names a PHP file below packages/<package>/src, not [{$path}].");
        }

        if ($line < 1) {
            throw new InvalidArgumentException("An equivalent mutation of {$path} names a line from 1, not {$line}.");
        }

        if (preg_match(self::MUTATOR_PATTERN, $mutator) !== 1) {
            throw new InvalidArgumentException("An equivalent mutation of {$path} names a mutator of pest-plugin-mutate by class, not [{$mutator}].");
        }

        if (trim($reason) === '' || str_contains($reason, "\n")) {
            throw new InvalidArgumentException("The equivalent mutation {$this->describe()} needs a one-line reason.");
        }
    }

    /**
     * Whether a mutation Pest made of the file at $path is this one.
     */
    public function matches(string $path, MutationOutcome $outcome): bool
    {
        return $path === $this->path && $outcome->line === $this->line && $outcome->mutator === $this->mutator;
    }

    /**
     * The entry as Pest names a mutation in its output: the source, the line and the mutator's
     * short name.
     */
    public function describe(): string
    {
        $mutator = strrchr($this->mutator, '\\');

        return sprintf('%s line %d %s', $this->path, $this->line, $mutator === false ? $this->mutator : substr($mutator, 1));
    }
}
