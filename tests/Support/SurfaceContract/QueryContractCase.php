<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCodec;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Tests\TestCase;
use PHPUnit\Framework\Assert;

/**
 * One surface contract test of a query (GUARDRAILS 2.1, 9: one per action and surface): a query
 * action of the registry, one surface its #[Action] lists, and that surface's query profile, or
 * null when QuerySurfaceProfiles has none, which fails the test, as does a query without a
 * QueryCodec. verify() sends every QueryScenario through the surface, with the smallest document
 * the query's JSON Schema accepts (SampleDocument), over the QueryContractKernel, and checks the
 * answer: the problem's code and paths, or a result's document that the result's codec reads back
 * into the result the kernel answered with, the queries the action handled, and the transport's signal the profile gives.
 */
final readonly class QueryContractCase
{
    /** The key a query that requires no property is sent with, which no query has. */
    public const string UNKNOWN_KEY = 'contract_unknown';

    /**
     * The classification access of the reader on every surface: what the QueryWorld grants its
     * service actor, which its agent's credential, capped at confidential, keeps.
     */
    private const ClassificationAccess ACCESS = ClassificationAccess::Confidential;

    public function __construct(
        public CompiledRegistry $registry,
        public ActionEntry $action,
        public Surface $surface,
        public ?QuerySurfaceProfile $profile,
    ) {}

    /**
     * The name of the case in the dataset, such as "actor.me v1 on rest".
     */
    public function name(): string
    {
        return sprintf('%s v%d on %s', $this->action->command->value, $this->action->commandVersion, $this->surface->value);
    }

    public function verify(TestCase $test): void
    {
        $profile = $this->profile ?? Assert::fail(sprintf(
            'The action %s exposes the query %s version %d on the surface %s, which has no query profile in %s. Write a %s for the surface and add it to QuerySurfaceProfiles::all().',
            $this->action->class,
            $this->action->command->value,
            $this->action->commandVersion,
            $this->surface->value,
            QuerySurfaceProfiles::class,
            QuerySurfaceProfile::class,
        ));

        $codec = app(QueryCodecs::class)->find($this->action->command, $this->action->commandVersion) ?? Assert::fail(sprintf(
            'The installation has no query codec of %s version %d, which %s exposes on %s.',
            $this->action->command->value,
            $this->action->commandVersion,
            $this->action->class,
            $this->surface->value,
        ));

        $kernel = new QueryContractKernel($this->registry);
        $profile->prepare($test, $this->registry);

        foreach (QueryScenario::cases() as $scenario) {
            $this->scenario($test, $profile, $kernel, $codec, $scenario);
        }
    }

    private function scenario(TestCase $test, QuerySurfaceProfile $profile, QueryContractKernel $kernel, QueryCodec $codec, QueryScenario $scenario): void
    {
        $result = $codec->result->decode(SampleDocument::json(SampleDocument::of($codec->resultSchema)), self::ACCESS);
        $kernel->script($scenario, $result);
        $document = SampleDocument::of($codec->querySchema);
        $path = '';

        if ($scenario === QueryScenario::DocumentRefused) {
            $path = SampleDocument::required($codec->querySchema)[0] ?? '';

            if ($path === '') {
                $document->{self::UNKNOWN_KEY} = true;
            } else {
                unset($document->{$path});
            }
        }

        $read = $profile->send($test, $this->registry, new QuerySurfaceCall(
            $this->action->command,
            $this->action->commandVersion,
            SampleDocument::json($document),
            $profile->credential($kernel),
        ));

        $at = sprintf('%s, %s', $this->name(), $scenario->value);
        $expected = match ($scenario) {
            QueryScenario::DocumentRefused => ['rejected', 'json_invalid', ['json_invalid '.$profile->queryPath($path)]],
            QueryScenario::Unauthorized => ['rejected', 'unauthorized', ['unauthorized -']],
            QueryScenario::Answered => ['answered', null, []],
        };

        Assert::assertSame($profile->transport($scenario), $read->transport, $at.': the transport\'s answer');
        Assert::assertSame($expected, [$read->outcome, $read->code, $read->errors], $at.': outcome, code and errors');

        if ($scenario === QueryScenario::Answered) {
            Assert::assertEquals($result, $codec->result->decode($read->result ?? '', self::ACCESS), $at.': the result its codec reads back from the answer');
        }

        $handled = $kernel->handled();
        Assert::assertCount($scenario->handled() ? 1 : 0, $handled, $at.': the queries handed to the action');

        foreach ($handled as $query) {
            Assert::assertSame($this->action->commandClass, $query::class, $at.': the query its codec read');
        }
    }
}
