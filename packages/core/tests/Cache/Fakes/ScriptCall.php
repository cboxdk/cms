<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Cache\Fakes;

/**
 * One EVAL a scripted client received: the script, the keys followed by the arguments, and the
 * number of keys.
 */
final readonly class ScriptCall
{
    /**
     * @param  list<mixed>  $args
     */
    public function __construct(
        public string $script,
        public array $args,
        public int $keys,
    ) {}
}
