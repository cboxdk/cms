<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Arch;

use Cbox\Cms\Testkit\Phpstan\LayerScope;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\Rules;
use PhpToken;

/*
 * The testkit's PHPStan rule for mixed and untyped arrays skips test code: a namespace with a
 * Tests segment, or the global namespace of Pest files (GUARDRAILS 2.2). Code in packages/src
 * and workbench/app must therefore never look like test code, or it would escape the rule.
 */

arch('namespaces: every file in packages/src and workbench/app declares a namespace that is not test code', function (): void {
    $files = Codebase::code();
    $violations = [];

    foreach ($files as $file) {
        $tokens = array_values(PhpToken::tokenize((string) file_get_contents($file->path)));
        $declared = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_NAMESPACE)) {
                continue;
            }

            foreach (array_slice($tokens, $index + 1) as $next) {
                if ($next->isIgnorable()) {
                    continue;
                }

                // A braced global namespace is ''; namespace\foo() is a relative name, not a declaration.
                if ($next->text === '{' || $next->is([T_STRING, T_NAME_QUALIFIED])) {
                    $declared[] = $next->text === '{' ? '' : $next->text;
                }

                break;
            }
        }

        if ($declared === []) {
            $violations[] = Codebase::relative($file->path).' declares no namespace.';
        }

        foreach ($declared as $namespace) {
            if (LayerScope::isTestCode($namespace)) {
                $violations[] = sprintf('%s declares the namespace "%s", which the PHPStan rules treat as test code.', Codebase::relative($file->path), $namespace);
            }
        }
    }

    expect($files)->not->toBeEmpty();
    Rules::none($violations, 'Code in packages/src and workbench/app needs a namespace without a Tests segment:');
});
