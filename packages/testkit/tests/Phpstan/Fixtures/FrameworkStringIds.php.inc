<?php

declare(strict_types=1);

// String ids that a framework interface or parent class requires, and the same names without
// that requirement.

namespace Fixture\Queue\Jobs {
    use Illuminate\Contracts\Auth\Guard;
    use Illuminate\Contracts\Queue\Job;
    use Illuminate\Contracts\Queue\ShouldBeUnique;

    // Job declares getJobId() with @return string.
    abstract class QueuedJob implements Job
    {
        public function getJobId(): string
        {
            return '';
        }
    }

    // The same name without the interface.
    final class OwnJob
    {
        public function getJobId(): string
        {
            return '';
        }
    }

    // ShouldBeUnique declares no method; Laravel calls uniqueId() by name.
    final class UniqueJob implements ShouldBeUnique
    {
        public function uniqueId(): string
        {
            return '';
        }
    }

    // Guard declares id() with @return int|string|null, so the string is this class's choice.
    abstract class CmsGuard implements Guard
    {
        public function id(): string
        {
            return '';
        }
    }
}

namespace Fixture\Sessions\Domain {
    use Illuminate\Session\Store;
    use SessionHandlerInterface;
    use SessionUpdateTimestampHandlerInterface;

    // PHP's own interface declares validateId(string $id).
    abstract class SessionIds implements SessionUpdateTimestampHandlerInterface
    {
        public function validateId(string $id): bool
        {
            return $id !== '';
        }
    }

    // The same name without the interface.
    final class OwnSessionIds
    {
        public function validateId(string $id): bool
        {
            return $id !== '';
        }
    }

    // Store declares getId() with @return string and setId() with @param string|null $id.
    // Its constructor is not abstract, so a subclass chooses its own parameters.
    final class CmsSession extends Store
    {
        public function __construct(
            string $name,
            SessionHandlerInterface $handler,
            ?string $id = null,
        ) {
            parent::__construct($name, $handler, $id);
        }

        public function getId(): string
        {
            return parent::getId();
        }

        /**
         * @param  string|null  $id
         */
        public function setId($id)
        {
            parent::setId($id);
        }
    }
}

namespace Acme\Billing\Adapter {
    // An addon's own interface is not the framework, even where string ids are allowed.
    interface Customers
    {
        public function customerId(): string;
    }
}

namespace Acme\Billing\Domain {
    use Acme\Billing\Adapter\Customers;

    abstract class Customer implements Customers
    {
        public function customerId(): string
        {
            return '';
        }
    }
}
