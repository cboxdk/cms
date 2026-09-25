<?php

declare(strict_types=1);

// Public string ids in the domain, in Boundary, in an Adapter and in tests.

namespace Fixture\Changesets\Domain {
    final readonly class ChangesetId
    {
        // The value object takes its string as $value.
        public function __construct(public string $value) {}

        public function toString(): string
        {
            return $this->value;
        }
    }

    interface Changesets
    {
        public function find(string $changesetId): ?ChangesetId;

        public function get(ChangesetId $id): ChangesetId;
    }

    final readonly class Changeset
    {
        public function __construct(
            public string $id,
            public ChangesetId $parentId,
            public string $title,
        ) {}

        public function id(): string
        {
            return $this->id;
        }

        public function changesetId(): ChangesetId
        {
            return new ChangesetId($this->id);
        }

        public function entryId(): ?string
        {
            return null;
        }

        /**
         * @param  non-empty-string  $userId
         */
        public function assign(string $userId, int $revisionId, string $paid, string $identity): void {}

        public function rename(string $title): string
        {
            return $title;
        }

        private function lookup(string $id): string
        {
            return $id;
        }

        protected function other(string $entryId): void {}
    }
}

namespace Fixture\Changesets\Infrastructure {
    final class ChangesetModel
    {
        public function getId(): string
        {
            return '';
        }
    }
}

namespace Fixture\Http\Boundary {
    final class ChangesetParser
    {
        public function find(string $changesetId): string
        {
            return $changesetId;
        }
    }
}

namespace Fixture\Changesets\Adapter {
    final class ChangesetRows
    {
        public function id(string $id): string
        {
            return $id;
        }
    }
}

namespace Fixture\Changesets\Tests {
    final class ChangesetFactory
    {
        public function find(string $changesetId): string
        {
            return $changesetId;
        }
    }
}
