<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Core\Pipeline\Actions\RunExposedCommand;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCodec;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Tests\TestCase;
use PHPUnit\Framework\Assert;

/**
 * One surface contract test (GUARDRAILS 2.1, 9: one per action and surface): a write action of the
 * registry, one surface its #[Action] lists, and that surface's profile, or null when
 * SurfaceProfiles has none, which fails the test. verify() sends every Scenario through the
 * surface, with the smallest document the command's JSON Schema accepts (SampleDocument), over
 * the ContractKernel, and checks the receipt or problem, the field paths, what reached the
 * committer and the envelope it carried, and the transport's signal the profile gives.
 */
final readonly class SurfaceContractCase
{
    public function __construct(
        public CompiledRegistry $registry,
        public ActionEntry $action,
        public Surface $surface,
        public ?SurfaceProfile $profile,
    ) {}

    /**
     * The name of the case in the dataset, such as "entry.create v1 on rest".
     */
    public function name(): string
    {
        return sprintf('%s v%d on %s', $this->action->command->value, $this->action->commandVersion, $this->surface->value);
    }

    public function verify(TestCase $test): void
    {
        $profile = $this->profile ?? Assert::fail(sprintf(
            'The action %s exposes %s version %d on the surface %s, which has no profile in %s. Write a %s for the surface and add it to SurfaceProfiles::all().',
            $this->action->class,
            $this->action->command->value,
            $this->action->commandVersion,
            $this->surface->value,
            SurfaceProfiles::class,
            SurfaceProfile::class,
        ));

        $codec = app(CommandCodecs::class)->find($this->action->command, $this->action->commandVersion) ?? Assert::fail(sprintf(
            'The installation has no command codec of %s version %d, which %s exposes on %s.',
            $this->action->command->value,
            $this->action->commandVersion,
            $this->action->class,
            $this->surface->value,
        ));

        $kernel = new ContractKernel($this->registry);
        app()->instance(RunExposedCommand::class, $kernel->action());
        $profile->prepare($test, $this->registry, $kernel);

        foreach (Scenario::cases() as $scenario) {
            $this->scenario($test, $profile, $kernel, $codec, $scenario);
        }
    }

    private function scenario(TestCase $test, SurfaceProfile $profile, ContractKernel $kernel, CommandCodec $codec, Scenario $scenario): void
    {
        $kernel->script($scenario);
        $document = SampleDocument::of($codec->schema);
        $path = 'fields.label';

        if ($scenario === Scenario::DocumentFieldError) {
            $path = SampleDocument::required($codec->schema)[0] ?? Assert::fail(sprintf('The schema of %s requires no property, so no document of it is missing one.', $this->action->command->value));
            unset($document->{$path});
        }

        $answer = $profile->send($test, $this->registry, new SurfaceCall(
            $this->action->command,
            $this->action->commandVersion,
            SampleDocument::json($document),
            sprintf('contract-%s-v%d-%s-%s', $this->action->command->value, $this->action->commandVersion, $this->surface->value, $scenario->value),
            $scenario === Scenario::DryRun,
            $this->waitLevel($scenario),
            $profile->credential($kernel),
        ));

        $at = sprintf('%s, %s', $this->name(), $scenario->value);
        $expected = match ($scenario) {
            Scenario::DocumentFieldError => ['rejected', 'json_invalid', ['json_invalid '.$profile->commandPath($path)], null],
            Scenario::FieldError => ['rejected', 'validation_failed', ['validation_failed -', 'validation_required '.$profile->commandPath($path)], null],
            Scenario::VersionConflict => ['rejected', 'version_conflict', ['version_conflict -'], null],
            Scenario::DryRun => ['dry_run', null, [], null],
            Scenario::WaitTimeout => ['committed_wait_timeout', null, [], FakeChangesetCommitter::CHANGESET],
        };

        Assert::assertSame($profile->transport($scenario, $path), $answer->transport, $at.': the transport\'s answer');
        Assert::assertSame($expected, [$answer->outcome, $answer->code, $answer->errors, $answer->changeset], $at.': outcome, code, errors and changeset');

        if ($answer->outcome !== 'rejected') {
            Assert::assertSame($this->waitLevel($scenario)->value, $answer->waitLevel, $at.': the receipt\'s wait level');
        }

        $pending = $kernel->pending();
        Assert::assertCount($scenario->commits() ? 1 : 0, $pending, $at.': the changesets handed to the committer');

        foreach ($pending as $changeset) {
            Assert::assertSame($this->action->commandClass, $changeset->input::class, $at.': the command its codec read');
            Assert::assertSame(IssuingSurface::of($this->surface), $changeset->envelope->surface, $at.': the envelope\'s surface');
            Assert::assertSame($this->waitLevel($scenario), $changeset->envelope->waitLevel, $at.': the envelope\'s wait level');
            Assert::assertFalse($changeset->envelope->dryRun, $at.': the envelope\'s dry run');
        }
    }

    /**
     * The wait level the call asks for: origin in the scenario that does not reach it, else commit.
     */
    private function waitLevel(Scenario $scenario): WaitLevel
    {
        return $scenario === Scenario::WaitTimeout ? WaitLevel::Origin : WaitLevel::Commit;
    }
}
