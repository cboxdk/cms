<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\StringIdsRule;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 4, PRD 5.3: public ids are value objects, not strings, outside Boundary and Adapter.
 *
 * @extends RuleTestCase<Rule<InClassMethodNode>>
 */
final class StringIdsRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_public_string_ids_outside_boundary_and_adapter(): void
    {
        // Not reported: ChangesetId's own string $value (line 11), find(ChangesetId $id)
        // (line 23), a ChangesetId return (line 39), int $revisionId, $paid and $identity
        // (line 52), a string that is no id (line 54), private and protected methods (lines 59
        // and 64), the Boundary (line 81), the Adapter (line 91) and the tests (line 101).
        self::assertSame([
            '21 cboxCms.stringId',  // find(string $changesetId) in a domain interface
            '29 cboxCms.stringId',  // a public promoted string $id
            '34 cboxCms.stringId',  // id(): string
            '44 cboxCms.stringId',  // entryId(): ?string
            '52 cboxCms.stringId',  // non-empty-string $userId
            '71 cboxCms.stringId',  // getId(): string in Infrastructure
        ], $this->reported('StringIds'));
    }

    public function test_the_message_names_the_declaration_and_the_advice(): void
    {
        $advice = ' Type ids as value objects, such as ChangesetId (PRD 5.3); string ids are only allowed in Boundary and Adapter namespaces.';

        $this->analyse([self::fixture('StringIds')], [
            ['Parameter $changesetId of method Fixture\Changesets\Domain\Changesets::find() is a string id of type string.'.$advice, 21],
            ['Parameter $id of method Fixture\Changesets\Domain\Changeset::__construct() is a string id of type string.'.$advice, 29],
            ['Return type of method Fixture\Changesets\Domain\Changeset::id() is a string id of type string.'.$advice, 34],
            ['Return type of method Fixture\Changesets\Domain\Changeset::entryId() is a string id of type string|null.'.$advice, 44],
            ['Parameter $userId of method Fixture\Changesets\Domain\Changeset::assign() is a string id of type non-empty-string.'.$advice, 52],
            ['Return type of method Fixture\Changesets\Infrastructure\ChangesetModel::getId() is a string id of type string.'.$advice, 71],
        ]);
    }

    public function test_it_exempts_only_string_ids_a_framework_interface_or_parent_class_requires(): void
    {
        // Not reported, because the framework declares a string there: getJobId() of Laravel's
        // queue Job interface (line 16), validateId(string $id) of PHP's
        // SessionUpdateTimestampHandlerInterface (line 58), and getId() and setId() of the parent
        // class Illuminate\Session\Store (lines 85 and 93). The addon's own interface in its
        // Adapter is allowed there (line 104).
        self::assertSame([
            '25 cboxCms.stringId',  // getJobId(): string without the Job interface
            '34 cboxCms.stringId',  // uniqueId(): string, which ShouldBeUnique does not declare
            '43 cboxCms.stringId',  // id(): string where Guard declares int|string|null
            '67 cboxCms.stringId',  // validateId(string $id) without the session interface
            '80 cboxCms.stringId',  // ?string $id in a constructor Store does not make abstract
            '113 cboxCms.stringId', // customerId(): string of an interface outside the framework
        ], $this->reported('FrameworkStringIds'));
    }

    public function test_the_message_of_a_signature_the_framework_does_not_require(): void
    {
        $advice = ' Type ids as value objects, such as ChangesetId (PRD 5.3); string ids are only allowed in Boundary and Adapter namespaces.';

        $this->analyse([self::fixture('FrameworkStringIds')], [
            ['Return type of method Fixture\Queue\Jobs\OwnJob::getJobId() is a string id of type string.'.$advice, 25],
            ['Return type of method Fixture\Queue\Jobs\UniqueJob::uniqueId() is a string id of type string.'.$advice, 34],
            ['Return type of method Fixture\Queue\Jobs\CmsGuard::id() is a string id of type string.'.$advice, 43],
            ['Parameter $id of method Fixture\Sessions\Domain\OwnSessionIds::validateId() is a string id of type string.'.$advice, 67],
            ['Parameter $id of method Fixture\Sessions\Domain\CmsSession::__construct() is a string id of type string|null.'.$advice, 80],
            ['Return type of method Acme\Billing\Domain\Customer::customerId() is a string id of type string.'.$advice, 113],
        ]);
    }

    /**
     * @return Rule<InClassMethodNode>
     */
    protected function getRule(): Rule
    {
        return new StringIdsRule(self::createReflectionProvider());
    }
}
