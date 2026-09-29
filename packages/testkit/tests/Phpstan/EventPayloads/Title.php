<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan\EventPayloads;

/**
 * An object that holds text, for the fixture EventPayloads.php.inc, autoloaded so the rule can
 * read it by name.
 */
final readonly class Title
{
    public function __construct(public string $value) {}
}
