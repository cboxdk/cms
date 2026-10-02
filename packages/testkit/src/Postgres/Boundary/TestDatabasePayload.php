<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Postgres\Boundary;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;
use JsonException;

/**
 * What a child process that provisions a checkout's test database reads on standard input
 * (bin/test-database.php): the owner role's, the app role's and the identity role's connections, the checkout root and
 * the parallel worker whose database it is, or null for the checkout's own.
 * The passwords travel on standard input, never on the command line, where `ps` would show them.
 */
#[Experimental]
final readonly class TestDatabasePayload
{
    public function __construct(
        public ConnectionSettings $owner,
        public ConnectionSettings $app,
        public ConnectionSettings $identity,
        public string $root,
        public ?int $worker = null,
    ) {}

    public function encode(): string
    {
        return json_encode([
            'owner' => $this->owner->toPayload(),
            'app' => $this->app->toPayload(),
            'identity' => $this->identity->toPayload(),
            'root' => $this->root,
            'worker' => $this->worker,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @throws InvalidArgumentException when the payload is not what encode() writes
     */
    public static function decode(string $json): self
    {
        try {
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The test database payload is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($payload)) {
            throw new InvalidArgumentException('The test database payload is not an object.');
        }

        $root = $payload['root'] ?? null;

        if (! is_string($root) || $root === '') {
            throw new InvalidArgumentException('The test database payload has no root.');
        }

        $worker = $payload['worker'] ?? null;

        if ($worker !== null && (! is_int($worker) || $worker < 1)) {
            throw new InvalidArgumentException('The test database payload names a worker that is not a positive integer.');
        }

        return new self(
            ConnectionSettings::fromPayload($payload['owner'] ?? null),
            ConnectionSettings::fromPayload($payload['app'] ?? null),
            ConnectionSettings::fromPayload($payload['identity'] ?? null),
            $root,
            $worker,
        );
    }
}
