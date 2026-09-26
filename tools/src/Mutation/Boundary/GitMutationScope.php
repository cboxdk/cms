<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Boundary;

use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use PhpToken;
use Symfony\Component\Process\Process;

/**
 * Finds what mutation on changed files mutates in a git checkout: the PHP files below
 * packages/<package>/src that were added or changed between the merge base of CMS_CI_BASE_REF and
 * HEAD, each with the class, enum, interface or trait it declares, read from its tokens. A
 * deleted file has nothing to mutate. An unset, empty or unknown ref, or one without a merge base
 * with HEAD, gives an unresolved scope with the reason, never an empty change.
 *
 * CI sets CMS_CI_BASE_REF: .github/workflows/ci.yml to the pull request's base commit, and
 * docker/ci-entry.sh to HEAD~1 of the two-commit repository it builds from the merge base and HEAD.
 */
final readonly class GitMutationScope
{
    public const string VARIABLE = 'CMS_CI_BASE_REF';

    private const string SOURCE = '#^packages/[^/]+/src/.+\.php$#';

    /**
     * @param  string  $root  the root of the checkout
     * @param  string|null  $baseRef  the value of CMS_CI_BASE_REF, null when it is not set
     */
    public static function resolve(string $root, ?string $baseRef): MutationScope
    {
        if ($baseRef === null || trim($baseRef) === '') {
            return MutationScope::unresolved(self::VARIABLE.' is not set, so there is no base to find the changed files from. CI sets it to the base of the pull request.');
        }

        $described = self::VARIABLE.'='.$baseRef;
        $commit = self::git($root, ['rev-parse', '--verify', '--end-of-options', $baseRef.'^{commit}']);

        if ($commit->exitCode !== 0) {
            return MutationScope::unresolved("{$described} names no commit in {$root}: {$commit->message}");
        }

        $mergeBase = self::git($root, ['merge-base', $commit->output, 'HEAD']);

        if ($mergeBase->exitCode !== 0) {
            return MutationScope::unresolved("{$described} has no merge base with HEAD in {$root}: {$mergeBase->message}");
        }

        $diff = self::git($root, ['diff', '--name-only', '--no-renames', '--diff-filter=d', '-z', $mergeBase->output, 'HEAD', '--', 'packages']);

        if ($diff->exitCode !== 0) {
            return MutationScope::unresolved("git diff from the merge base {$mergeBase->output} of {$described} failed: {$diff->message}");
        }

        $sources = [];

        foreach (explode("\0", $diff->raw) as $path) {
            if (preg_match(self::SOURCE, $path) !== 1) {
                continue;
            }

            $code = is_file($root.'/'.$path) ? file_get_contents($root.'/'.$path) : false;

            if ($code === false) {
                return MutationScope::unresolved("{$path} changed since the merge base of {$described}, but it cannot be read in the checkout.");
            }

            $sources[] = new ChangedSource($path, self::declaredName($code) ?? $path);
        }

        return MutationScope::changed("{$mergeBase->output}, the merge base of {$described} and HEAD", $sources);
    }

    /**
     * The fully qualified name of the first class, enum, interface or trait the code declares.
     */
    private static function declaredName(string $code): ?string
    {
        $namespace = '';
        $previous = null;
        $tokens = array_values(array_filter(PhpToken::tokenize($code), static fn (PhpToken $token): bool => ! $token->isIgnorable()));

        foreach ($tokens as $index => $token) {
            if ($token->is(T_NAMESPACE) && ($tokens[$index + 1] ?? null)?->is([T_NAME_QUALIFIED, T_STRING])) {
                $namespace = $tokens[$index + 1]->text.'\\';
            }

            $declares = $token->is([T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT])
                && ! $previous?->is([T_DOUBLE_COLON, T_NEW])
                && ($tokens[$index + 1] ?? null)?->is(T_STRING);

            if ($declares) {
                return $namespace.$tokens[$index + 1]->text;
            }

            $previous = $token;
        }

        return null;
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function git(string $root, array $arguments): GitResult
    {
        $process = new Process(['git', ...$arguments], $root, ['GIT_OPTIONAL_LOCKS' => '0'], null, 60);
        $process->run();
        $message = trim(preg_replace('/\s+/', ' ', $process->getErrorOutput()) ?? '');

        return new GitResult($process->getExitCode() ?? 1, trim($process->getOutput()), $process->getOutput(), $message === '' ? 'git exited '.($process->getExitCode() ?? 'without an exit code') : $message);
    }
}
