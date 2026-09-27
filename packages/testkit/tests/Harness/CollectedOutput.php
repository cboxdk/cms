<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Harness;

use Closure;

final class CollectedOutput
{
    public string $text = '';

    /**
     * @return Closure(string): void
     */
    public function writer(): Closure
    {
        return function (string $text): void {
            $this->text .= $text;
        };
    }
}
