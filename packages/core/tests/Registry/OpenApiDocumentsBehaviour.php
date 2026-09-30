<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\OpenApiDocument;
use Cbox\Cms\Core\Registry\Domain\Dto\RestRoute;
use Cbox\Cms\Core\Registry\Domain\OpenApiDocuments;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheUnwritable;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Throwable;

/**
 * What every OpenApiDocuments does (GUARDRAILS 2.1, PRD 8.8), run against FileOpenApiDocuments in a
 * scratch directory and against FakeOpenApiDocuments, so the fake BuildRegistry's action tests use
 * cannot drift from the file.
 */
trait OpenApiDocumentsBehaviour
{
    /**
     * Documents whose codecs read version 1 of the command fixture.note.create and nothing else.
     */
    abstract protected function openApiDocuments(): OpenApiDocuments;

    /**
     * Documents like openApiDocuments() whose writes fail.
     */
    abstract protected function unwritableOpenApiDocuments(): OpenApiDocuments;

    /**
     * The text the last write put in place, or null when nothing was written.
     */
    abstract protected function writtenOpenApi(OpenApiDocuments $documents): ?string;

    #[Test]
    public function it_describes_each_route_on_rest_by_path_and_method_and_no_other_action(): void
    {
        $document = $this->decoded($this->openApiDocuments()->describe(new RegistryCompiler()->compile(RegistryFixtures::validDiscovery())));

        Assert::assertSame('3.1.1', $document->openapi);
        Assert::assertInstanceOf(stdClass::class, $document->paths);
        Assert::assertSame(['/v1/commands/fixture.note.create/v1'], array_keys(get_object_vars($document->paths)));
        $path = $document->paths->{'/v1/commands/fixture.note.create/v1'};
        Assert::assertInstanceOf(stdClass::class, $path);
        Assert::assertSame(['post'], array_keys(get_object_vars($path)));
        Assert::assertInstanceOf(stdClass::class, $path->post);
        Assert::assertSame('command.fixture.note.create.v1', $path->post->operationId);
    }

    #[Test]
    public function it_describes_a_registry_without_routes_with_no_paths(): void
    {
        $document = $this->decoded($this->openApiDocuments()->describe(CompiledRegistry::empty()));

        Assert::assertEquals(new stdClass, $document->paths);
    }

    #[Test]
    public function it_refuses_every_route_whose_command_or_query_has_no_codec_naming_the_action(): void
    {
        $documents = $this->openApiDocuments();
        $failed = $this->thrownBy(fn (): OpenApiDocument => $documents->describe($this->registryOf(
            new ActionEntry('App\Actions\PinNote', 'acme/notes', ActionKind::Write, new CommandName('fixture.note.pin'), 1, 'App\Commands\PinNote', [Surface::Rest]),
            new ActionEntry('App\Actions\FindNotes', 'acme/notes', ActionKind::Query, new CommandName('fixture.note.find'), 2, 'App\Queries\FindNotes', [Surface::Rest]),
            new ActionEntry('App\Actions\CreateNote', 'acme/notes', ActionKind::Write, new CommandName('fixture.note.create'), 1, 'App\Commands\CreateNote', [Surface::Rest]),
        )));

        Assert::assertInstanceOf(RegistryBuildFailed::class, $failed);
        Assert::assertSame([BuildErrorCode::SurfaceWithoutCodec, BuildErrorCode::SurfaceWithoutCodec], $failed->codes());
        Assert::assertStringContainsString('App\Actions\FindNotes is exposed on REST, but no codec reads version 2 of the query fixture.note.find', $failed->getMessage());
        Assert::assertStringContainsString('App\Actions\PinNote is exposed on REST, but no codec reads version 1 of the command fixture.note.pin', $failed->getMessage());
        Assert::assertNull($this->writtenOpenApi($documents));
    }

    #[Test]
    public function it_describes_the_same_registry_with_the_same_bytes(): void
    {
        $registry = new RegistryCompiler()->compile(RegistryFixtures::validDiscovery());

        Assert::assertSame($this->openApiDocuments()->describe($registry)->json, $this->openApiDocuments()->describe($registry)->json);
    }

    #[Test]
    public function it_puts_the_document_it_writes_in_place_whole_and_replaces_it_on_the_next_write(): void
    {
        $documents = $this->openApiDocuments();
        $first = $documents->describe(new RegistryCompiler()->compile(RegistryFixtures::validDiscovery()));
        $second = $documents->describe(CompiledRegistry::empty());

        $documents->write($first);
        Assert::assertSame($first->json, $this->writtenOpenApi($documents));

        $documents->write($second);
        Assert::assertSame($second->json, $this->writtenOpenApi($documents));
    }

    #[Test]
    public function it_refuses_a_write_that_fails_and_writes_nothing(): void
    {
        $documents = $this->unwritableOpenApiDocuments();
        $failed = $this->thrownBy(static fn () => $documents->write(new OpenApiDocument("{}\n")));

        Assert::assertInstanceOf(RegistryCacheUnwritable::class, $failed);
        Assert::assertStringStartsWith('['.RegistryCacheUnwritable::CODE.'] ', $failed->getMessage());
        Assert::assertStringContainsString('openapi.json', $failed->getMessage());
        Assert::assertNull($this->writtenOpenApi($documents));
    }

    private function registryOf(ActionEntry ...$actions): CompiledRegistry
    {
        return new CompiledRegistry([], [], array_values($actions), [], [], array_values(array_filter(array_map(RestRoute::of(...), $actions))));
    }

    private function decoded(OpenApiDocument $document): stdClass
    {
        Assert::assertStringEndsWith("\n", $document->json);
        $decoded = json_decode($document->json, false, 512, JSON_THROW_ON_ERROR);
        Assert::assertInstanceOf(stdClass::class, $decoded);

        return $decoded;
    }

    /**
     * @param  Closure(): mixed  $call
     */
    private function thrownBy(Closure $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }
}
