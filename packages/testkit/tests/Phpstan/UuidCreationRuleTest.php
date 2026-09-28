<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\SystemClockRule;
use PhpParser\Node;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 7, GUARDRAILS 2.3 and PRD 5.3: only an IdGenerator implementation makes a UUID.
 *
 * @extends RuleTestCase<Rule<Node>>
 */
final class UuidCreationRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_every_new_uuid_outside_an_id_generator_and_tests(): void
    {
        // Not reported: a model without an id trait (line 49), Str's checks (lines 61 and 62),
        // name-based UUIDs and parsing with ramsey/uuid (lines 79 to 83), symfony/uid with a value,
        // parsing and a name-based UUID (lines 104 to 108), uuid_is_valid() (line 114), the
        // IdGenerator implementation and a closure in it (lines 132 and 137), the tests (line 166)
        // and a Pest file, the global namespace below tests/ (line 173).
        self::assertSame([
            '34 cboxCms.uuid',              // use HasUuids;
            '39 cboxCms.uuid',              // use HasVersion4Uuids;
            '44 cboxCms.uuid',              // use HasUlids, SoftDeletes;
            '56 cboxCms.uuid',              // Str::uuid();
            '57 cboxCms.uuid',              // Str::uuid7();
            '58 cboxCms.uuid',              // Str::orderedUuid();
            '59 cboxCms.uuid',              // Str::ulid();
            '60 cboxCms.uuid',              // Str::uuid(...);
            '67 cboxCms.uuid',              // RamseyUuid::uuid1();
            '68 cboxCms.uuid',              // RamseyUuid::uuid2(RamseyUuid::DCE_DOMAIN_PERSON);
            '69 cboxCms.uuid',              // RamseyUuid::uuid4();
            '70 cboxCms.uuid',              // RamseyUuid::uuid6();
            '71 cboxCms.uuid',              // RamseyUuid::uuid7();
            '72 cboxCms.uuid',              // $factory->uuid4();
            '73 cboxCms.uuid',              // $factory->uuid7();
            '74 cboxCms.uuid',              // v1();
            '75 cboxCms.uuid',              // v2(RamseyUuid::DCE_DOMAIN_PERSON);
            '76 cboxCms.uuid',              // v4();
            '77 cboxCms.uuid',              // v6();
            '78 cboxCms.uuid',              // v7();
            '88 cboxCms.uuid',              // Uuid::v1();
            '89 cboxCms.uuid',              // Uuid::v4();
            '90 cboxCms.uuid',              // Uuid::v6();
            '91 cboxCms.uuid',              // Uuid::v7();
            '92 cboxCms.uuid',              // UuidV1::generate();
            '93 cboxCms.uuid',              // UuidV6::generate();
            '94 cboxCms.uuid',              // UuidV7::generate();
            '95 cboxCms.uuid',              // Ulid::generate();
            '96 cboxCms.uuid',              // new UuidV1();
            '97 cboxCms.uuid',              // new UuidV4();
            '98 cboxCms.uuid',              // new UuidV6();
            '99 cboxCms.uuid',              // new UuidV7(null);
            '100 cboxCms.uuid',             // new Ulid();
            '101 cboxCms.uuid',             // $factory->create();
            '102 cboxCms.uuid',             // $factory->randomBased()->create();
            '103 cboxCms.uuid',             // $factory->timeBased()->create();
            '113 cboxCms.uuid',             // uuid_create();
            '146 cboxCms.uuid',             // return Str::uuid()->toString();
            '154 cboxCms.uuid',             // return new DateTimeImmutable('@'.Uuid::uuid1()->getDateTime()->getTimestamp());
        ], $this->reported('Uuids'));
    }

    public function test_it_reports_new_uuids_in_global_namespace_code_outside_a_tests_directory(): void
    {
        // A migration, a route file and a config file are production code in the global
        // namespace. A Pest file is exempt below tests/ only: the same file in examples/ is not.
        $project = GlobalNamespaceProject::create();

        try {
            self::assertSame([
                'config/things.php:10 cboxCms.uuid',                                          // Str::orderedUuid()
                'database/migrations/2026_01_01_000000_create_things_table.php:15 cboxCms.uuid', // Str::uuid()
                'examples/ThingTest.php:10 cboxCms.uuid',                                     // Str::uuid()
                'routes/web.php:11 cboxCms.uuid',                                             // Str::ulid()
            ], $this->reportedIn($project));
        } finally {
            $project->remove();
        }
    }

    public function test_the_message_names_the_call_and_the_contract(): void
    {
        $this->analyse([self::fixture('Uuids')], [
            [$this->message('the trait Illuminate\\Database\\Eloquent\\Concerns\\HasUuids'), 34],
            [$this->message('the trait Illuminate\\Database\\Eloquent\\Concerns\\HasVersion4Uuids'), 39],
            [$this->message('the trait Illuminate\\Database\\Eloquent\\Concerns\\HasUlids'), 44],
            [$this->message('Illuminate\\Support\\Str::uuid()'), 56],
            [$this->message('Illuminate\\Support\\Str::uuid7()'), 57],
            [$this->message('Illuminate\\Support\\Str::orderedUuid()'), 58],
            [$this->message('Illuminate\\Support\\Str::ulid()'), 59],
            [$this->message('Illuminate\\Support\\Str::uuid()'), 60],
            [$this->message('Ramsey\\Uuid\\Uuid::uuid1()'), 67],
            [$this->message('Ramsey\\Uuid\\Uuid::uuid2()'), 68],
            [$this->message('Ramsey\\Uuid\\Uuid::uuid4()'), 69],
            [$this->message('Ramsey\\Uuid\\Uuid::uuid6()'), 70],
            [$this->message('Ramsey\\Uuid\\Uuid::uuid7()'), 71],
            [$this->message('Ramsey\\Uuid\\UuidFactory::uuid4()'), 72],
            [$this->message('Ramsey\\Uuid\\UuidFactory::uuid7()'), 73],
            [$this->message('Ramsey\\Uuid\\v1()'), 74],
            [$this->message('Ramsey\\Uuid\\v2()'), 75],
            [$this->message('Ramsey\\Uuid\\v4()'), 76],
            [$this->message('Ramsey\\Uuid\\v6()'), 77],
            [$this->message('Ramsey\\Uuid\\v7()'), 78],
            [$this->message('Symfony\\Component\\Uid\\Uuid::v1()'), 88],
            [$this->message('Symfony\\Component\\Uid\\Uuid::v4()'), 89],
            [$this->message('Symfony\\Component\\Uid\\Uuid::v6()'), 90],
            [$this->message('Symfony\\Component\\Uid\\Uuid::v7()'), 91],
            [$this->message('Symfony\\Component\\Uid\\UuidV1::generate()'), 92],
            [$this->message('Symfony\\Component\\Uid\\UuidV6::generate()'), 93],
            [$this->message('Symfony\\Component\\Uid\\UuidV7::generate()'), 94],
            [$this->message('Symfony\\Component\\Uid\\Ulid::generate()'), 95],
            [$this->message('new Symfony\\Component\\Uid\\UuidV1()'), 96],
            [$this->message('new Symfony\\Component\\Uid\\UuidV4()'), 97],
            [$this->message('new Symfony\\Component\\Uid\\UuidV6()'), 98],
            [$this->message('new Symfony\\Component\\Uid\\UuidV7()'), 99],
            [$this->message('new Symfony\\Component\\Uid\\Ulid()'), 100],
            [$this->message('Symfony\\Component\\Uid\\Factory\\UuidFactory::create()'), 101],
            [$this->message('Symfony\\Component\\Uid\\Factory\\RandomBasedUuidFactory::create()'), 102],
            [$this->message('Symfony\\Component\\Uid\\Factory\\TimeBasedUuidFactory::create()'), 103],
            [$this->message('uuid_create()'), 113],
            [$this->message('Illuminate\\Support\\Str::uuid()'), 146],
            [$this->message('Ramsey\\Uuid\\Uuid::uuid1()'), 154],
        ]);
    }

    private function message(string $creation): string
    {
        return sprintf(
            'Makes a UUID through %s. Ask the IdGenerator contract for a new id: only an IdGenerator implementation makes ids, so tests get the same ids on every run (GUARDRAILS 2.3, PRD 5.3).',
            $creation,
        );
    }

    /**
     * The rule and the three rules that hand it first-class callables, from the test container.
     *
     * @return Rule<Node>
     */
    protected function getRule(): Rule
    {
        $rules = [];

        foreach (self::getContainer()->getServicesByTag('phpstan.rules.rule') as $rule) {
            if ($rule instanceof Rule && ! $rule instanceof SystemClockRule && str_starts_with($rule::class, 'Cbox\\Cms\\Testkit\\Phpstan\\')) {
                $rules[] = $rule;
            }
        }

        return new RegisteredRules($rules);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__.'/clock-and-uuids.neon'];
    }
}
