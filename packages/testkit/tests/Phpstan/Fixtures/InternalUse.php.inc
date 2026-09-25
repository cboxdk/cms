<?php

declare(strict_types=1);

// Internals of the core, used by the core, by an addon and by the global namespace.

namespace Cbox\Cms\Core\Fixture\Engine {
    use Cbox\Cms\Contracts\Attributes\Internal;
    use Cbox\Cms\Contracts\Attributes\Stable;

    #[Internal]
    final class Engine
    {
        public const int LIMIT = 1;

        public static function boot(): self
        {
            return new self;
        }

        public function run(): int
        {
            return 1;
        }
    }

    #[Stable]
    final class Registry
    {
        #[Internal]
        public const int SECRET = 2;

        public const int SHARED = 3;

        #[Internal]
        public function flush(): void {}

        public function count(): int
        {
            return 0;
        }

        #[Internal]
        public static function reset(): void {}
    }

    // Code in the Cbox\Cms namespace may use internals.
    #[Stable]
    final class CoreUser
    {
        public function use(Registry $registry, Engine $engine): int
        {
            $registry->flush();
            Registry::reset();

            return new Engine()->run() + Engine::boot()->run() + Registry::SECRET + Engine::LIMIT + strlen(Engine::class);
        }
    }
}

namespace Acme\Blog {
    use Cbox\Cms\Core\Fixture\Engine\Engine;
    use Cbox\Cms\Core\Fixture\Engine\Registry;

    final class Plugin
    {
        public function stable(Registry $registry): int
        {
            return $registry->count() + Registry::SHARED;
        }

        public function method(Registry $registry): void
        {
            $registry->flush();
        }

        public function staticMethod(): void
        {
            Registry::reset();
        }

        public function constant(): int
        {
            return Registry::SECRET;
        }

        public function engine(Engine $engine): int
        {
            return $engine->run();
        }

        public function create(): Engine
        {
            return new Engine;
        }

        public function boot(): void
        {
            Engine::boot();
        }

        public function limit(): int
        {
            return Engine::LIMIT;
        }

        public function name(): string
        {
            return Engine::class;
        }

        public function check(object $value): bool
        {
            return $value instanceof Engine;
        }
    }
}

namespace {
    use Cbox\Cms\Core\Fixture\Engine\Registry;

    function flushRegistry(Registry $registry): void
    {
        $registry->flush();
    }
}
