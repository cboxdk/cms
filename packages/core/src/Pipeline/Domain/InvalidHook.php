<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Phase;
use LogicException;

/**
 * A hook cannot be bound as the registry declares it: it does not implement the interface of its
 * phase, or its budget is outside 1 to 20 ms. cms:build refuses both, so this is a registry that
 * does not match the code; run cms:build again.
 */
#[Internal]
final class InvalidHook extends LogicException
{
    public static function phase(string $class, Phase $phase): self
    {
        return new self(sprintf(
            'The hook %s is registered for the %s phase and does not implement %s. Run cms:build again after changing a hook.',
            $class,
            $phase->value,
            $phase->hookInterface(),
        ));
    }

    public static function budget(string $class, int $budgetMs): self
    {
        return new self(sprintf('The hook %s has a budget of %d ms. It must be between 1 and %d ms.', $class, $budgetMs, Hook::MAX_BUDGET_MS));
    }
}
