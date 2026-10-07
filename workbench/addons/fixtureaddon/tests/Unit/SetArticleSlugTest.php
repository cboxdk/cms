<?php

declare(strict_types=1);

namespace Workbench\FixtureAddon\Tests\Unit;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\Codecs\Domain\EncodingFailed;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Workbench\FixtureAddon\FixtureArticle;
use Workbench\FixtureAddon\Slug\Actions\SetArticleSlugAction;
use Workbench\FixtureAddon\Slug\Boundary\SetArticleSlugCodec;
use Workbench\FixtureAddon\Slug\Domain\ArticleHeads;
use Workbench\FixtureAddon\Slug\Domain\ArticleSlug;
use Workbench\FixtureAddon\Slug\Domain\Commands\SetArticleSlug;
use Workbench\FixtureAddon\Slug\Domain\Dto\ArticleHead;
use Workbench\FixtureAddon\Slug\Domain\Dto\SetArticleSlugAggregates;

/**
 * The fixture addon's own command fixtureaddon.slug.set, tested without a database or the kernel,
 * as an addon tests a write action (docs/addons/commands.md): the value class ArticleSlug takes a
 * well-formed slug and refuses any other; the codec reads and writes the command's document,
 * writes the canonical JSON the content hash is taken over and refuses a document that is not
 * one or a command of another class; the action reads the head through its port, expects the
 * shared variant at the version the caller saw, is authorized on the entry's home node, and plans
 * the next revision with the head's fields and the slug in the addon's namespace, the owner's
 * fields and other extenders' untouched, and the head moved to it.
 */
final class SetArticleSlugTest extends TestCase
{
    private const string ENTRY = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a21';

    private const string HOME = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a22';

    #[Test]
    public function the_value_class_takes_a_well_formed_slug_and_refuses_any_other(): void
    {
        self::assertSame('a-quiet-week', ArticleSlug::fromString('a-quiet-week')->toString());
        self::assertTrue(new ArticleSlug('x1')->equals(new ArticleSlug('x1')));

        foreach (['A quiet week', 'a--quiet', '-a', 'a-', '', str_repeat('a', 121)] as $wrong) {
            try {
                new ArticleSlug($wrong);
                self::fail(sprintf('"%s" was taken as a slug.', $wrong));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($wrong === '' || strlen($wrong) > 120 ? 'slug' : 'not well formed', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function the_codec_reads_and_writes_the_document_and_refuses_a_document_that_is_not_one(): void
    {
        $codec = new SetArticleSlugCodec;
        $json = '{"entry":"'.self::ENTRY.'","slug":"a-quiet-week","version":3}';

        $command = $codec->decode($json, ClassificationAccess::Public);

        self::assertSame(self::ENTRY, $command->entry->toString());
        self::assertSame(3, $command->version->value);
        self::assertSame('a-quiet-week', $command->slug->value);
        self::assertSame($json, $codec->encode($command, ClassificationAccess::Public));
        self::assertSame($json, $codec->encodeCommand($command));

        try {
            $codec->encodeCommand(new CreateEntry(EntryId::fromString(self::ENTRY), PlanViews::article(), NodeId::fromString(self::HOME), new FieldValues));
            self::fail('Another command was encoded.');
        } catch (EncodingFailed $failed) {
            self::assertStringContainsString('is not a SetArticleSlug', $failed->getMessage());
        }

        foreach ([
            '{"entry":"'.self::ENTRY.'","slug":"A quiet week","version":3}' => 'slug',
            '{"entry":"'.self::ENTRY.'","slug":"a-quiet-week","version":0}' => 'version',
            '{"entry":"'.self::ENTRY.'","version":3}' => 'slug',
            '{"entry":"'.self::ENTRY.'","slug":"a-quiet-week","version":3,"extra":true}' => 'extra',
        ] as $refused => $path) {
            try {
                $codec->decode($refused, ClassificationAccess::Public);
                self::fail($refused.' was read.');
            } catch (DecodingFailed $failed) {
                self::assertStringContainsString($path, $failed->getMessage(), $refused);
            }
        }
    }

    #[Test]
    public function the_action_reads_the_head_through_its_port_and_names_the_variant_and_the_home_node(): void
    {
        $head = $this->head();
        $action = new SetArticleSlugAction($this->heads($head));
        $command = $this->command('a-quiet-week', 4);

        $aggregates = $action->resolve($command);

        self::assertSame($head, $aggregates->head);
        self::assertEquals(new ReadVersion(new VariantRef($command->entry, VariantKey::shared()), new AggregateVersion(4)), $aggregates->versions()->of($command->variant()));
        self::assertEquals([new AuthorizationTarget(NodeId::fromString(self::HOME))], $aggregates->authorizationScope()->targets);
        self::assertEquals($aggregates->versions(), $command->expectedVersions());

        $absent = new SetArticleSlugAction($this->heads(null))->resolve($command);

        self::assertNull($absent->versions()->of($command->variant())?->version);
        self::assertTrue($absent->authorizationScope()->isAnywhere());
    }

    #[Test]
    public function the_action_plans_the_next_revision_with_the_slug_set_and_the_head_moved_to_it(): void
    {
        $head = $this->head();
        $command = $this->command('a-quiet-week', 4);

        $plan = new SetArticleSlugAction($this->heads($head))->plan($command, new SetArticleSlugAggregates($command->entry, $head));
        [$revision, $moved] = $plan->mutations();

        self::assertInstanceOf(RevisionCreated::class, $revision);
        self::assertInstanceOf(HeadMoved::class, $moved);
        self::assertSame(FixtureArticle::TYPE_ID, $revision->type->toString());
        self::assertSame(6, $revision->revision->value);
        self::assertTrue($revision->variant->isShared());
        self::assertEquals(new TextValue('a-quiet-week'), $revision->fields->extension(FixtureArticle::namespace())?->get(FixtureArticle::slug()));
        self::assertEquals(new TextValue('kept'), $revision->fields->extension(FixtureArticle::namespace())?->get(new FieldHandle('note')));
        self::assertEquals(new TextValue('A quiet week'), $revision->fields->own->get(FixtureArticle::title()));
        self::assertEquals(new BooleanValue(true), $revision->fields->own->get(new FieldHandle('fixture_featured')));
        self::assertEquals(new TextValue('theirs'), $revision->fields->extension(new FieldNamespace('other'))?->get(new FieldHandle('mark')));
        self::assertSame(3, $moved->from?->value);
        self::assertSame(6, $moved->to->value);
    }

    #[Test]
    public function the_action_sets_the_slug_of_a_head_without_the_addons_fields_and_refuses_to_plan_an_absent_head(): void
    {
        $fields = SetArticleSlugAction::withSlug(new FieldValues(new FieldMap(new NamedValue(FixtureArticle::title(), new TextValue('A quiet week')))), new ArticleSlug('fresh'));

        self::assertEquals(new TextValue('fresh'), $fields->extension(FixtureArticle::namespace())?->get(FixtureArticle::slug()));
        self::assertCount(1, $fields->extensions);

        $command = $this->command('fresh', 1);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('version_conflict');

        new SetArticleSlugAction($this->heads(null))->plan($command, new SetArticleSlugAggregates($command->entry, null));
    }

    private function command(string $slug, int $version): SetArticleSlug
    {
        return new SetArticleSlug(EntryId::fromString(self::ENTRY), new AggregateVersion($version), new ArticleSlug($slug));
    }

    /**
     * A head at variant version 4 whose head revision is 3 and highest revision 5, with the owner's
     * title and featured flag, the addon's old slug and another field of its own, and another
     * extender's field.
     */
    private function head(): ArticleHead
    {
        return new ArticleHead(
            PlanViews::article(),
            NodeId::fromString(self::HOME),
            new AggregateVersion(4),
            new RevisionNumber(3),
            new RevisionNumber(5),
            new FieldValues(
                new FieldMap(new NamedValue(FixtureArticle::title(), new TextValue('A quiet week')), new NamedValue(new FieldHandle('fixture_featured'), new BooleanValue(true))),
                new ExtensionFields(new FieldNamespace('other'), new FieldMap(new NamedValue(new FieldHandle('mark'), new TextValue('theirs')))),
                new ExtensionFields(FixtureArticle::namespace(), new FieldMap(new NamedValue(FixtureArticle::slug(), new TextValue('old-slug')), new NamedValue(new FieldHandle('note'), new TextValue('kept')))),
            ),
        );
    }

    /**
     * The port answering every entry with the head, or none.
     */
    private function heads(?ArticleHead $head): ArticleHeads
    {
        return new readonly class($head) implements ArticleHeads
        {
            public function __construct(private ?ArticleHead $head) {}

            public function head(EntryId $entry): ?ArticleHead
            {
                return $this->head;
            }
        };
    }
}
