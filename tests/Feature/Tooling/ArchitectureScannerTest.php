<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Arch\Category;
use Cbox\Cms\Tests\Support\Arch\Comment;
use Cbox\Cms\Tests\Support\Arch\DeclaredType;
use Cbox\Cms\Tests\Support\Arch\GlobalName;
use Cbox\Cms\Tests\Support\Arch\Layer;
use Cbox\Cms\Tests\Support\Arch\SourceFile;
use Illuminate\Support\Facades\Http;

/*
 * The token scanner and the layer map behind the Arch suite. The Arch suite is only as good
 * as these, so they are tested on their own (GUARDRAILS 7.3).
 */

it('maps a namespace to the innermost layer segment, at the end or with segments below it', function (?Layer $expected, string $namespace): void {
    expect(Layer::of($namespace))->toBe($expected);
})->with([
    [Layer::Domain, 'Cbox\Cms\Core\Entries\Domain'],
    [Layer::Domain, 'Cbox\Cms\Core\Entries\Domain\Dto'],
    [Layer::Actions, 'Cbox\Cms\Core\Entries\Actions'],
    [Layer::Adapter, 'Cbox\Cms\Core\Receipts\Adapter\Postgres'],
    [Layer::Infrastructure, 'Cbox\Cms\Core\Entries\Infrastructure\Models'],
    [Layer::Http, 'Cbox\Cms\Http'],
    [Layer::Http, 'Cbox\Cms\Http\Controllers'],
    [Layer::Boundary, 'Cbox\Cms\Http\Boundary'],
    [Layer::Cli, 'Cbox\Cms\Cli\Console'],
    [Layer::Jobs, 'Cbox\Cms\Core\Projections\Jobs'],
    [Layer::Domain, 'Cbox\Cms\Contracts'],
    [Layer::Domain, 'Cbox\Cms\Contracts\Attributes'],
    [null, 'Cbox\Cms\ContractsExtra'],
    [null, 'Cbox\Cms\Core'],
    [null, 'Cbox\Cms\Core\DomainEvents'],
    [null, 'Cbox\Cms\Core\HttpGateway'],
    [null, ''],
]);

it('maps a namespace to its category', function (?Category $expected, string $namespace): void {
    expect(Category::of($namespace))->toBe($expected);
})->with([
    [Category::Commands, 'Cbox\Cms\Core\Entries\Domain\Commands'],
    [Category::Dto, 'Cbox\Cms\Core\Entries\Domain\Dto\Nested'],
    [Category::Receipts, 'Cbox\Cms\Core\Domain\Receipts'],
    [Category::Queries, 'Cbox\Cms\Core\Entries\Domain\Queries'],
    [null, 'Cbox\Cms\Core\Entries\Domain'],
    [null, 'Cbox\Cms\Cli\Console'],
]);

it('finds declared types and skips ::class and anonymous classes', function (): void {
    $file = SourceFile::parse('Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Entries\Domain;

        final readonly class EntryId
        {
            public function name(): string
            {
                $anonymous = new class {};
                $readonly = new readonly class {};

                return self::class.$anonymous::class;
            }
        }

        interface Clock {}

        trait Named {}

        enum Status: string
        {
            case Active = 'active';
        }
        PHP);

    expect(array_map(static fn (DeclaredType $type): string => $type->kind.' '.$type->fqcn(), $file->types))->toBe([
        'class Cbox\Cms\Core\Entries\Domain\EntryId',
        'interface Cbox\Cms\Core\Entries\Domain\Clock',
        'trait Cbox\Cms\Core\Entries\Domain\Named',
        'enum Cbox\Cms\Core\Entries\Domain\Status',
    ])->and($file->types[0]->layer())->toBe(Layer::Domain)
        ->and($file->types[0]->line)->toBe(7);
});

it('finds a phpstan-ignore comment that is attached to no node, with its namespace and line', function (): void {
    $file = SourceFile::parse('Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Entries\Domain;

        final readonly class EntryId {}

        /* a block comment */
        // @phpstan-ignore-next-line
        PHP);

    expect(array_map(static fn (Comment $comment): string => $comment->line.' '.$comment->namespace.' '.trim($comment->text), $file->comments))->toBe([
        '9 Cbox\Cms\Core\Entries\Domain /* a block comment */',
        '10 Cbox\Cms\Core\Entries\Domain // @phpstan-ignore-next-line',
    ]);
});

it('puts a comment before the namespace statement in the global namespace', function (): void {
    $file = SourceFile::parse('Probe.php', "<?php\n\n// @phpstan-ignore-line\ndeclare(strict_types=1);\n\nnamespace Cbox\\Cms\\Core\\Adapter;\n\n/** @phpstan-ignore-next-line */\n");

    expect(array_map(static fn (Comment $comment): string => $comment->namespace, $file->comments))
        ->toBe(['', 'Cbox\Cms\Core\Adapter'])
        ->and($file->declaresStrictTypes)->toBeTrue();
});

it('knows whether a file starts with declare(strict_types=1)', function (bool $expected, string $code): void {
    expect(SourceFile::parse('Probe.php', $code)->declaresStrictTypes)->toBe($expected);
})->with([
    [true, "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n"],
    [true, "<?php\n\n/** Header. */\n\ndeclare(strict_types=1);\n"],
    [false, "<?php\n\nreturn [];\n"],
    [false, "<?php\n\ndeclare(strict_types=0);\n"],
    [false, "<?php\n\nnamespace A;\n\ndeclare(strict_types=1);\n"],
    [false, "<html><?php\n\ndeclare(strict_types=1);\n"],
]);

it('records names that resolve from the global namespace, but not trait uses or closure uses', function (): void {
    $file = SourceFile::parse('Probe.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Cbox\Cms\Core\Entries\Adapter;

        use DB;
        use Illuminate\Support\Facades\Http;
        use function strlen;

        final class Store
        {
            use SomeTrait;

            public function run(): int
            {
                $callback = function () use ($value): int {
                    return \Cache::get('x') + \Facades\App\Clock::now();
                };

                return strlen('x');
            }
        }
        PHP);

    expect(array_map(static fn (GlobalName $name): string => $name->name, $file->globalNames))
        ->toBe(['DB', Http::class, 'Cache', 'Facades\App\Clock']);
});
