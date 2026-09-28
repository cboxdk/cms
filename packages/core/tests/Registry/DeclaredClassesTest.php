<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Infrastructure\DeclaredClasses;

it('reads the classes, interfaces, traits and enums a file declares, with their namespace', function (): void {
    $source = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Acme\Blog\Actions;

        use Cbox\Cms\Contracts\Attributes\Command;

        #[Command('post.publish', version: 1)]
        final readonly class PublishPost {}

        interface Publishes {}

        trait Publishing {}

        enum State: string { case Draft = 'draft'; }

        abstract class Base {}
        PHP;

    expect(DeclaredClasses::in($source))->toBe([
        'Acme\Blog\Actions\PublishPost',
        'Acme\Blog\Actions\Publishes',
        'Acme\Blog\Actions\Publishing',
        'Acme\Blog\Actions\State',
        'Acme\Blog\Actions\Base',
    ]);
});

it('skips anonymous classes and ::class constants', function (): void {
    $source = <<<'PHP'
        <?php

        namespace Acme;

        final class Factory
        {
            public function make(): object
            {
                $name = Factory::class;
                $static = static::class;

                return new class($name) { public function __construct(public string $name) {} };
            }

            public function other(): object
            {
                return new #[\Attribute] class extends \stdClass implements \Countable { public function count(): int { return 0; } };
            }
        }
        PHP;

    expect(DeclaredClasses::in($source))->toBe(['Acme\Factory']);
});

it('follows several namespaces in one file, and the global namespace', function (): void {
    $source = <<<'PHP'
        <?php

        namespace First {
            class One {}
        }

        namespace Second\Level {
            class Two {}
        }

        namespace {
            class Three {}
        }
        PHP;

    expect(DeclaredClasses::in($source))->toBe(['First\One', 'Second\Level\Two', 'Three'])
        ->and(DeclaredClasses::in("<?php\n\nclass Top {}\n"))->toBe(['Top']);
});

it('ignores comments between the keyword and the name', function (): void {
    expect(DeclaredClasses::in("<?php\nnamespace /* ns */ Acme;\nfinal class /* why */ Commented {}\n"))->toBe(['Acme\Commented']);
});

it('finds nothing in a file without declarations', function (): void {
    expect(DeclaredClasses::in("<?php\n\nreturn ['class' => 'Not\\\\A\\\\Declaration'];\n"))->toBe([])
        ->and(DeclaredClasses::in('plain text, class Foo'))->toBe([]);
});
