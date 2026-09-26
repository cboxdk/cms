<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * What a checkout's test database says about itself in its COMMENT ON DATABASE: the real path of
 * the checkout and the host it lives on, as JSON, such as
 * `{"checkout":"/srv/laravel-cms","host":"build-7"}`.
 *
 * A server can be shared by several hosts, and a checkout path means something only on its own
 * host, so whoever cleans up the test databases of removed checkouts looks only at those of its
 * own host.
 */
#[Experimental]
final readonly class TestDatabaseComment
{
    public function __construct(
        public string $checkout,
        public string $host,
    ) {}

    /**
     * The comment of the checkout at $root on this host.
     */
    public static function of(string $root): self
    {
        $host = gethostname();

        if ($host === false || $host === '') {
            throw new RuntimeException('PHP could not read the host name.');
        }

        return new self(TestDatabaseName::realpath($root), $host);
    }

    public function encode(): string
    {
        return json_encode(['checkout' => $this->checkout, 'host' => $this->host], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @throws InvalidArgumentException when $json is not what encode() writes
     */
    public static function decode(string $json): self
    {
        try {
            $values = json_decode($json, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The test database comment is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }

        $checkout = is_array($values) ? ($values['checkout'] ?? null) : null;
        $host = is_array($values) ? ($values['host'] ?? null) : null;

        if (! is_string($checkout) || $checkout === '' || ! is_string($host) || $host === '') {
            throw new InvalidArgumentException('The test database comment has no checkout and host.');
        }

        return new self($checkout, $host);
    }
}
