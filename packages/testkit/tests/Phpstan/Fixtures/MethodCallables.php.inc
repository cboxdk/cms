<?php

declare(strict_types=1);

// A first-class callable of a method for each rule MethodCallablesRule hands one to: a read of
// the system clock, a new UUID and IO in a hook.

namespace Fixture\MethodCallables {
    use Carbon\Carbon;
    use Cbox\Cms\Contracts\Hooks\FieldChanges;
    use Cbox\Cms\Contracts\Hooks\PlanView;
    use Cbox\Cms\Contracts\Hooks\TransformHook;
    use Illuminate\Database\ConnectionInterface;
    use Ramsey\Uuid\UuidFactory;

    final readonly class TakesMethods
    {
        public function clockAndUuid(Carbon $carbon, UuidFactory $factory): void
        {
            $carbon->diffForHumans(...);
            $factory->uuid4(...);
        }
    }

    final readonly class SelectsInAHook implements TransformHook
    {
        public function __construct(private ConnectionInterface $connection) {}

        public function transform(PlanView $plan): FieldChanges
        {
            $select = $this->connection->select(...);

            return $select === null ? new FieldChanges() : new FieldChanges();
        }
    }
}
