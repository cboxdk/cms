<?php

declare(strict_types=1);

// @phpstan-ignore-line
namespace Fixture\Entries\Domain {
    final class Suppressed
    {
        // @phpstan-ignore-next-line
        public function nextLine(mixed $value): void {}

        public function sameLine(mixed $value): void {} // @phpstan-ignore-line

        public function identifier(mixed $value): void {} // @phpstan-ignore cboxCms.mixed

        public function ownIdentifier(): void {} // @phpstan-ignore cboxCms.phpstanIgnore

        /**
         * A doc comment with the tag inside.
         *
         * @phpstan-ignore-next-line
         */
        public function documented(): void {}
    }
}

namespace Fixture\Receipts\Adapter {
    final class Allowed
    {
        // @phpstan-ignore-next-line
        public function nextLine(mixed $value): void {}

        public function sameLine(): void {} // @phpstan-ignore-line
    }
}

namespace Fixture\Entries\Domain\Tail {
    final class Tail {}
    // A comment that is attached to no node: @phpstan-ignore-line
}
