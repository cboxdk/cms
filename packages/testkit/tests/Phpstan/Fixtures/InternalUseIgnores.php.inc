<?php

declare(strict_types=1);

// An addon hides uses of core internals with ignore comments. Only a comment that names
// cboxCms.internalUse hides a use, one use for each time it names the identifier.

namespace Cbox\Cms\Core\Fixture\Waivers {
    use Cbox\Cms\Contracts\Attributes\Internal;

    #[Internal]
    final class Engine
    {
        public const int LIMIT = 1;

        public static function boot(): int
        {
            return 1;
        }
    }
}

namespace Acme\Blog\Adapter {
    use Cbox\Cms\Core\Fixture\Waivers\Engine;

    final class Bridge
    {
        public function endOfLine(): int
        {
            return Engine::boot(); // @phpstan-ignore cboxCms.internalUse (no stable API yet)
        }

        public function lineAbove(): int
        {
            // @phpstan-ignore cboxCms.internalUse
            return Engine::boot();
        }

        public function docComment(): int
        {
            /** @phpstan-ignore cboxCms.internalUse */
            return Engine::LIMIT;
        }

        public function twoUsesOneNaming(): int
        {
            return Engine::boot() + Engine::LIMIT; // @phpstan-ignore cboxCms.internalUse
        }

        public function twoUsesTwoNamings(): int
        {
            return Engine::boot() + Engine::LIMIT; // @phpstan-ignore cboxCms.internalUse, cboxCms.internalUse
        }

        public function ignoreLine(): int
        {
            return Engine::boot(); // @phpstan-ignore-line
        }

        public function ignoreNextLine(): int
        {
            // @phpstan-ignore-next-line
            return Engine::boot();
        }

        public function bareIgnore(): int
        {
            return Engine::boot(); // @phpstan-ignore
        }

        public function otherIdentifier(): int
        {
            return Engine::boot(); // @phpstan-ignore staticMethod.notFound
        }

        public function nothingToHide(): int
        {
            return 1; // @phpstan-ignore cboxCms.internalUse
        }
    }

    trait UsesEngine
    {
        public function fromTrait(): int
        {
            return Engine::boot(); // @phpstan-ignore cboxCms.internalUse
        }

        public function fromTraitUnwaived(): int
        {
            return Engine::LIMIT;
        }
    }

    final class TraitUser
    {
        use UsesEngine;
    }
}
