<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\Layer;
use Cbox\Cms\Tests\Support\Arch\Rules;

/*
 * The phpstan-ignore annotations are forbidden outside Boundary and Adapter (GUARDRAILS 2.2,
 * gate 3). This scan reads every comment token with token_get_all over packages/src and workbench/app, so
 * the gate does not depend on PHPStan honouring or reporting its own ignores: a comment that
 * is attached to no node, such as one at the end of a file, is found as well.
 */

arch('phpstan-ignore: a token scan finds no @phpstan-ignore comment outside Boundary and Adapter', function (): void {
    $files = Codebase::code();
    $violations = [];

    foreach ($files as $file) {
        foreach ($file->comments as $comment) {
            if (! str_contains($comment->text, '@phpstan-ignore')) {
                continue;
            }

            if (in_array(Layer::of($comment->namespace), [Layer::Boundary, Layer::Adapter], true)) {
                continue;
            }

            $violations[] = sprintf(
                '%s in namespace "%s".',
                Codebase::relative($comment->location()),
                $comment->namespace,
            );
        }
    }

    expect($files)->not->toBeEmpty();
    Rules::none($violations, '@phpstan-ignore is only allowed in Boundary and Adapter namespaces:');
});
