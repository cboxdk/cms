<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Cache\Fakes;

/**
 * A client of a phpredis connection that is not a Redis object: it answers eval() alone, so a
 * store that asks it for a prefix or a last error fails with an Error.
 */
final class ScriptedClient
{
    /** @var list<ScriptCall> */
    public array $calls = [];

    /**
     * @param  list<mixed>  $answers
     */
    public function __construct(private array $answers = []) {}

    /**
     * @param  list<mixed>  $args
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $this->calls[] = new ScriptCall($script, $args, $num_keys);

        return array_shift($this->answers);
    }
}
