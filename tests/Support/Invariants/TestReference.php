<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Invariants;

use InvalidArgumentException;

/**
 * A test that tries to break an invariant, by its id, and the issuers it tries it through.
 *
 * The id is the test file's path from the repository root, `::`, and the test's name: the
 * description a Pest file gives it()/test()/arch() as written in the source, or the method of a
 * PHPUnit class.
 */
final readonly class TestReference
{
    public const string SEPARATOR = '::';

    public string $path;

    public string $name;

    /**
     * @param  list<Issuer>  $issuers
     */
    public function __construct(
        public string $id,
        public array $issuers,
    ) {
        $at = strpos($id, self::SEPARATOR);

        if ($at === false || $at === 0 || $at + strlen(self::SEPARATOR) === strlen($id)) {
            throw new InvalidArgumentException("The test id {$id} is not <path>::<test name>.");
        }

        $this->path = substr($id, 0, $at);
        $this->name = substr($id, $at + strlen(self::SEPARATOR));
    }

    /**
     * @param  list<Issuer>  $issuers
     */
    public static function of(string $path, string $name, array $issuers): self
    {
        return new self($path.self::SEPARATOR.$name, $issuers);
    }
}
