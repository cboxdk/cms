<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Tests\Unit;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Plans\Mutations\GrantAssigned;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Workbench\FixtureAddon\DenySelfGrant;

/**
 * The fixture addon's authorize hook on grant.assign, the four-eyes rule: a person may not grant
 * a role to themselves, whatever the effect and the case of the id's hex digits, while a grant to
 * another person and a plan without an actor principal pass. The hook is held to the verdicts of
 * resources/panel/parity/self-grant.json, which the panel's check fixtureaddon.self-grant mirrors
 * (the mirror rule, PRD 13.4), case by case: a denial is recorded at the grantee's path, actor.
 */
final class DenySelfGrantTest extends TestCase
{
    private const string PARITY = __DIR__.'/../../resources/panel/parity/self-grant.json';

    private const string VIEWER = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01';

    private const string OTHER = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02';

    #[Test]
    public function it_denies_a_grant_to_the_actor_the_command_runs_as_and_names_the_rule(): void
    {
        $decision = new DenySelfGrant()->authorize($this->view(self::VIEWER, self::VIEWER));

        self::assertTrue($decision->denies());
        self::assertStringContainsString('four-eyes rule', (string) $decision->reason);
        self::assertStringContainsString(self::VIEWER, (string) $decision->reason);
    }

    #[Test]
    public function it_has_no_objection_to_a_grant_to_another_person_or_without_an_actor_principal(): void
    {
        $hook = new DenySelfGrant;

        self::assertFalse($hook->authorize($this->view(self::VIEWER, self::OTHER))->denies());
        self::assertFalse($hook->authorize(new PlanView(new CommandName('grant.assign'), 1, new AnonymousPrincipal, ClassificationAccess::Public, $this->grant(self::VIEWER)))->denies());
    }

    #[Test]
    public function it_gives_the_verdicts_the_panels_mirrored_check_is_held_to(): void
    {
        $cases = $this->cases();
        $hook = new DenySelfGrant;

        self::assertNotEmpty($cases);

        foreach ($cases as $case) {
            $document = $case['document'];
            $actor = $document['actor'] ?? null;
            $effect = $document['effect'] ?? null;
            $locales = $document['locales'] ?? null;
            self::assertIsString($actor);

            $mutation = new GrantAssigned(
                GrantId::fromString(is_string($document['grant'] ?? null) ? $document['grant'] : self::OTHER),
                ActorId::fromString($actor),
                RoleId::fromString(is_string($document['role'] ?? null) ? $document['role'] : self::OTHER),
                NodeId::fromString(is_string($document['node'] ?? null) ? $document['node'] : self::OTHER),
                $effect === 'deny' ? GrantEffect::Deny : GrantEffect::Allow,
                is_array($locales) ? array_values(array_map(static fn (mixed $locale): Locale => new Locale(is_string($locale) ? $locale : ''), $locales)) : null,
            );
            $principal = $case['viewer'] === null
                ? new AnonymousPrincipal
                : new ActorPrincipal(ActorId::fromString($case['viewer']), [], IssuerKind::Human, ClassificationAccess::Internal);
            $decision = $hook->authorize(new PlanView(new CommandName('grant.assign'), 1, $principal, ClassificationAccess::Public, $mutation));

            self::assertSame($case['refused'], $decision->denies() ? ['actor'] : [], $case['name']);
        }
    }

    /**
     * The view of grant.assign giving the grantee a role, as the viewer runs it.
     */
    private function view(string $viewer, string $grantee): PlanView
    {
        return new PlanView(
            new CommandName('grant.assign'),
            1,
            new ActorPrincipal(ActorId::fromString($viewer), [], IssuerKind::Human, ClassificationAccess::Internal),
            ClassificationAccess::Public,
            $this->grant($grantee),
        );
    }

    private function grant(string $grantee): GrantAssigned
    {
        return new GrantAssigned(
            GrantId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a10'),
            ActorId::fromString($grantee),
            RoleId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a03'),
            NodeId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a04'),
            GrantEffect::Allow,
        );
    }

    /**
     * @return list<array{name: string, viewer: string|null, document: array<string, mixed>, refused: list<string>}>
     */
    private function cases(): array
    {
        $decoded = json_decode((string) file_get_contents(self::PARITY), true, 16, JSON_THROW_ON_ERROR);
        $cases = [];

        foreach (is_array($decoded) && is_array($decoded['cases'] ?? null) ? $decoded['cases'] : [] as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['name']);
            self::assertIsArray($case['document']);
            self::assertIsArray($case['refused']);
            self::assertTrue($case['viewer'] === null || is_string($case['viewer']));

            $refused = [];

            foreach ($case['refused'] as $path) {
                self::assertIsString($path);
                $refused[] = $path;
            }

            $document = [];

            foreach ($case['document'] as $key => $value) {
                $document[(string) $key] = $value;
            }

            $cases[] = ['name' => $case['name'], 'viewer' => $case['viewer'], 'document' => $document, 'refused' => $refused];
        }

        return $cases;
    }
}
