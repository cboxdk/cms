<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\EventPayloadTextRule;
use PHPStan\Node\ClassPropertyNode;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Rule 8, PRD 6.5 invariant 10 and 7.2: an event payload's properties hold ids, hashes and values
 * that are not text, never a string.
 *
 * @extends RuleTestCase<Rule<ClassPropertyNode>>
 */
final class EventPayloadTextRuleTest extends RuleTestCase
{
    use ReportedErrors;

    public function test_it_reports_payload_properties_that_can_hold_text_or_that_an_event_cannot_carry(): void
    {
        // Not reported: a ChangesetId (line 25), a TextHash (26), int (27), ?int (28), bool (29),
        // DateTimeImmutable (30), a backed enum (31), a list of ids (32), a list of hashes or null
        // (42), the int property of DeclaredV1 (57) and a string in a class that is no payload (73).
        self::assertSame([
            '33 cboxCms.eventPayloadText',  // string $title
            '34 cboxCms.eventPayloadText',  // ?string $note
            '35 cboxCms.eventPayloadText',  // int|string $key
            '36 cboxCms.eventPayloadText',  // list<string> $tags
            '37 cboxCms.eventPayloadText',  // mixed $anything
            '38 cboxCms.eventPayloadText',  // float $ratio
            '39 cboxCms.eventPayloadText',  // Title, an object that holds a string
            '40 cboxCms.eventPayloadText',  // a pure enum
            '41 cboxCms.eventPayloadText',  // array<string, int>, whose keys can be text
            '43 cboxCms.eventPayloadText',  // DateTimeInterface, which may be mutable
            '55 cboxCms.eventPayloadText',  // a declared non-empty-string $slug
        ], $this->reported('EventPayloads'));
    }

    public function test_the_messages_name_the_property_and_the_fix(): void
    {
        $text = ', which can hold text. Events carry ids, versions, values that are not text and hashes of text, never text (PRD 6.5 invariant 10, 7.2): type it as an id value object that implements Cbox\Cms\Contracts\Ids\Identifier, or as a Cbox\Cms\Contracts\Events\TextHash.';
        $other = ', which an event cannot carry. Use int, bool, null, DateTimeImmutable, a backed enum, an id value object that implements Cbox\Cms\Contracts\Ids\Identifier, a Cbox\Cms\Contracts\Events\TextHash, or a list of these (PRD 7.2).';
        $payload = 'Property Fixture\Counters\Domain\CountedV1::$';

        $this->analyse([self::fixture('EventPayloads')], [
            [$payload.'title of an event payload is of type string'.$text, 33],
            [$payload.'note of an event payload is of type string|null'.$text, 34],
            [$payload.'key of an event payload is of type int|string'.$text, 35],
            [$payload.'tags of an event payload is of type list<string>'.$text, 36],
            [$payload.'anything of an event payload is of type mixed'.$text, 37],
            [$payload.'ratio of an event payload is of type float'.$other, 38],
            [$payload.'label of an event payload is of type Cbox\Cms\Testkit\Tests\Phpstan\EventPayloads\Title'.$other, 39],
            [$payload.'flag of an event payload is of type Cbox\Cms\Testkit\Tests\Phpstan\EventPayloads\CounterFlag'.$other, 40],
            [$payload.'counts of an event payload is of type array<string, int>'.$other, 41],
            [$payload.'when of an event payload is of type DateTimeInterface'.$other, 43],
            ['Property Fixture\Counters\Domain\DeclaredV1::$slug of an event payload is of type non-empty-string'.$text, 55],
        ]);
    }

    /**
     * @return Rule<ClassPropertyNode>
     */
    protected function getRule(): Rule
    {
        return new EventPayloadTextRule;
    }
}
