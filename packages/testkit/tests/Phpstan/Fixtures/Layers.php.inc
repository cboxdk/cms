<?php

declare(strict_types=1);

// The same mixed parameter in five namespaces. Only Boundary and Adapter may use it.

namespace Fixture\Entries\Domain {
    final class Parser
    {
        public function parse(mixed $value): int
        {
            return 1;
        }
    }
}

namespace Fixture\Http\Boundary\Parsers {
    final class Parser
    {
        public function parse(mixed $value): int
        {
            return 1;
        }
    }
}

namespace Fixture\Http\Boundary {
    final class Parser
    {
        public function parse(mixed $value): int
        {
            return 1;
        }
    }
}

namespace Fixture\Receipts\Adapter {
    final class Parser
    {
        public function parse(mixed $value): int
        {
            return 1;
        }
    }
}

namespace Fixture\Boundary\Domain {
    // The innermost layer segment decides: this is Domain.
    final class Parser
    {
        public function parse(mixed $value): int
        {
            return 1;
        }
    }
}

namespace Fixture\Entries\Tests {
    // Test code is not checked.
    final class Parser
    {
        public function parse(mixed $value): int
        {
            return 1;
        }
    }
}
