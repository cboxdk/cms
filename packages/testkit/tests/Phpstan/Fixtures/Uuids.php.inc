<?php

declare(strict_types=1);

// Every form of making a new UUID, reported outside an IdGenerator implementation and tests,
// each next to the same class or function used without making one, which is not.

namespace Fixture\Uuids\Domain {
    use Illuminate\Database\Eloquent\Concerns\HasUlids;
    use Illuminate\Database\Eloquent\Concerns\HasUuids;
    use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\SoftDeletes;
    use Illuminate\Support\Str;
    use Ramsey\Uuid\Uuid as RamseyUuid;
    use Ramsey\Uuid\UuidFactory as RamseyFactory;
    use Symfony\Component\Uid\Factory\UuidFactory;
    use Symfony\Component\Uid\Ulid;
    use Symfony\Component\Uid\Uuid;
    use Symfony\Component\Uid\UuidV1;
    use Symfony\Component\Uid\UuidV4;
    use Symfony\Component\Uid\UuidV6;
    use Symfony\Component\Uid\UuidV7;

    use function Ramsey\Uuid\v1;
    use function Ramsey\Uuid\v2;
    use function Ramsey\Uuid\v4;
    use function Ramsey\Uuid\v5;
    use function Ramsey\Uuid\v6;
    use function Ramsey\Uuid\v7;

    final class Article extends Model
    {
        use HasUuids;
    }

    final class Comment extends Model
    {
        use HasVersion4Uuids;
    }

    final class Tag extends Model
    {
        use HasUlids, SoftDeletes;
    }

    final class Page extends Model
    {
        use SoftDeletes;
    }

    final readonly class Ids
    {
        public function laravel(string $value): void
        {
            Str::uuid();
            Str::uuid7();
            Str::orderedUuid();
            Str::ulid();
            Str::uuid(...);
            Str::isUuid($value);
            Str::isUlid($value);
        }

        public function ramsey(RamseyFactory $factory, string $value): void
        {
            RamseyUuid::uuid1();
            RamseyUuid::uuid2(RamseyUuid::DCE_DOMAIN_PERSON);
            RamseyUuid::uuid4();
            RamseyUuid::uuid6();
            RamseyUuid::uuid7();
            $factory->uuid4();
            $factory->uuid7();
            v1();
            v2(RamseyUuid::DCE_DOMAIN_PERSON);
            v4();
            v6();
            v7();
            RamseyUuid::uuid5(RamseyUuid::NAMESPACE_URL, 'https://example.com');
            RamseyUuid::uuid3(RamseyUuid::NAMESPACE_URL, 'https://example.com');
            RamseyUuid::fromString($value);
            $factory->fromString($value);
            v5(RamseyUuid::NAMESPACE_URL, 'https://example.com');
        }

        public function symfony(UuidFactory $factory, string $value): void
        {
            Uuid::v1();
            Uuid::v4();
            Uuid::v6();
            Uuid::v7();
            UuidV1::generate();
            UuidV6::generate();
            UuidV7::generate();
            Ulid::generate();
            new UuidV1();
            new UuidV4();
            new UuidV6();
            new UuidV7(null);
            new Ulid();
            $factory->create();
            $factory->randomBased()->create();
            $factory->timeBased()->create();
            new UuidV4($value);
            new Ulid(ulid: $value);
            Uuid::fromString($value);
            Uuid::v5(Uuid::fromString($value), 'name');
            $factory->nameBased(Uuid::fromString($value))->create('name');
        }

        public function extension(string $value): void
        {
            uuid_create();
            uuid_is_valid($value);
        }
    }
}

namespace Fixture\Uuids\Adapter {
    use Cbox\Cms\Contracts\Clock;
    use Cbox\Cms\Contracts\IdGenerator;
    use Cbox\Cms\Contracts\Ids\Uuid7;
    use DateTimeImmutable;
    use Illuminate\Support\Str;
    use Ramsey\Uuid\Uuid;

    // An IdGenerator implementation makes UUIDs.
    final readonly class RamseyIds implements IdGenerator
    {
        public function next(): Uuid7
        {
            return new Uuid7(Uuid::uuid7()->toString());
        }

        public function nextString(): string
        {
            return (static fn (): string => Str::uuid7()->toString())();
        }
    }

    // Any other adapter may not, whatever its layer, and a Clock is no id generator.
    final readonly class Keys
    {
        public function key(): string
        {
            return Str::uuid()->toString();
        }
    }

    final readonly class UuidClock implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('@'.Uuid::uuid1()->getDateTime()->getTimestamp());
        }
    }
}

namespace Fixture\Uuids\Tests {
    use Illuminate\Support\Str;

    final class IdsTest
    {
        public function id(): string
        {
            return Str::uuid()->toString();
        }
    }
}

namespace {
    // A Pest file: the global namespace below a tests directory, as this fixture is.
    $id = Illuminate\Support\Str::uuid();
}
