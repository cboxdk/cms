<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Boundary;

use Cbox\Cms\Tooling\Mutation\Domain\ChangedSource;
use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use PhpToken;
use Symfony\Component\Process\Process;

/**
 * Finds what mutation on changed files mutates in a git checkout: the PHP files below
 * packages/<package>/src that were added or changed since the base of the change, each with the
 * class, enum, interface or trait it declares, read from its tokens. A deleted file has nothing to
 * mutate.
 *
 * The base is the merge base of CMS_CI_BASE_REF and HEAD. .github/workflows/ci.yml sets the
 * variable to the pull request's base commit or to the commit before a push, and
 * docker/ci-entry.sh to HEAD~1 of the two-commit repository it builds from the base and HEAD.
 * When the variable is unset, empty or 40 zeros (the commit before a push that creates a branch),
 * the base is derived from the checkout: HEAD~1 when HEAD is the branch main, the merge base of
 * HEAD and origin/main on any other branch or a detached HEAD (main when the repository has no
 * origin/main, as a repository without a remote), and the empty tree when HEAD is the only commit
 * of the repository or the first commit of main, so every file counts as changed. A ref that names
 * no commit, a base without a merge base with HEAD, or a checkout the base cannot be derived in
 * gives an unresolved scope with the reason, never an empty change.
 */
final readonly class GitMutationScope
{
    public const string VARIABLE = 'CMS_CI_BASE_REF';

    /**
     * The branch whose previous commit is the base when HEAD is on it.
     */
    public const string MAIN = 'main';

    /**
     * The refs a branch other than main is compared with, in order: the first that exists.
     *
     * @var list<string>
     */
    public const array MAINLINES = ['origin/main', 'main'];

    private const string SOURCE = '#^packages/[^/]+/src/.+\.php$#';

    /**
     * @param  string  $root  the root of the checkout
     * @param  string|null  $baseRef  the value of CMS_CI_BASE_REF, null when it is not set
     */
    public static function resolve(string $root, ?string $baseRef): MutationScope
    {
        if ($baseRef === null) {
            return self::derive($root, self::VARIABLE.' is not set');
        }

        $missing = self::missingBase($baseRef);

        if ($missing !== null) {
            return self::derive($root, $missing);
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

        return self::changedSince($root, $mergeBase->output, "{$mergeBase->output}, the merge base of {$described} and HEAD");
    }

    /**
     * Why a set CMS_CI_BASE_REF names no base: it is empty or 40 zeros. Null when it is a ref to
     * resolve.
     */
    private static function missingBase(string $baseRef): ?string
    {
        return match (true) {
            trim($baseRef) === '' => self::VARIABLE.' is empty',
            preg_match('/^0{40}$/', trim($baseRef)) === 1 => self::VARIABLE.' is 40 zeros, the commit before a push that created the branch',
            default => null,
        };
    }

    /**
     * The scope since the base derived from the checkout, when CMS_CI_BASE_REF names none.
     *
     * @param  string  $missing  why CMS_CI_BASE_REF names no base
     */
    private static function derive(string $root, string $missing): MutationScope
    {
        $head = self::git($root, ['rev-parse', '--verify', 'HEAD^{commit}']);

        if ($head->exitCode !== 0) {
            return MutationScope::unresolved("{$missing}, and HEAD names no commit in {$root} to derive the base from: {$head->message}");
        }

        $onMain = self::git($root, ['symbolic-ref', '--quiet', 'HEAD'])->output === 'refs/heads/'.self::MAIN;
        $parent = self::git($root, ['rev-parse', '--verify', '--quiet', 'HEAD~1^{commit}']);
        $onlyCommit = self::git($root, ['rev-list', '--count', '--all'])->output === '1';

        if ($parent->exitCode !== 0 && ($onMain || $onlyCommit)) {
            $emptyTree = self::git($root, ['hash-object', '-t', 'tree', '/dev/null']);

            if ($emptyTree->exitCode !== 0) {
                return MutationScope::unresolved("{$missing}, and git cannot name the empty tree in {$root}: {$emptyTree->message}");
            }

            $first = $onlyCommit ? 'the only commit of the repository' : 'the first commit of '.self::MAIN;

            return self::changedSince($root, $emptyTree->output, "the empty tree, as {$missing} and HEAD {$head->output} is {$first}, so every file counts as changed");
        }

        if ($onMain) {
            return self::changedSince($root, $parent->output, "{$parent->output}, HEAD~1 of ".self::MAIN.", as {$missing} and HEAD is ".self::MAIN);
        }

        foreach (self::MAINLINES as $mainline) {
            $mainlineCommit = self::git($root, ['rev-parse', '--verify', '--quiet', '--end-of-options', $mainline.'^{commit}']);

            if ($mainlineCommit->exitCode !== 0) {
                continue;
            }

            $mergeBase = self::git($root, ['merge-base', $mainlineCommit->output, 'HEAD']);

            if ($mergeBase->exitCode !== 0) {
                return MutationScope::unresolved("{$missing}, and {$mainline} has no merge base with HEAD in {$root}: {$mergeBase->message}");
            }

            return self::changedSince($root, $mergeBase->output, "{$mergeBase->output}, the merge base of {$mainline} and HEAD, as {$missing} and HEAD is not ".self::MAIN);
        }

        return MutationScope::unresolved("{$missing}, HEAD is not ".self::MAIN.', and neither '.implode(' nor ', self::MAINLINES)." names a commit in {$root} to take the merge base with. CI sets it to the base of the pull request.");
    }

    /**
     * The PHP files below packages/<package>/src added or changed between the commit or tree and
     * HEAD, with what each declares.
     *
     * @param  string  $from  the commit or tree the change starts from
     * @param  string  $base  how the base was found, one line
     */
    private static function changedSince(string $root, string $from, string $base): MutationScope
    {
        $diff = self::git($root, ['diff', '--name-only', '--no-renames', '--diff-filter=d', '-z', $from, 'HEAD', '--', 'packages']);

        if ($diff->exitCode !== 0) {
            return MutationScope::unresolved("git diff from {$base} failed: {$diff->message}");
        }

        $sources = [];

        foreach (explode("\0", $diff->raw) as $path) {
            if (preg_match(self::SOURCE, $path) !== 1) {
                continue;
            }

            $code = is_file($root.'/'.$path) ? file_get_contents($root.'/'.$path) : false;

            if ($code === false) {
                return MutationScope::unresolved("{$path} changed since {$base}, but it cannot be read in the checkout.");
            }

            $sources[] = new ChangedSource($path, self::declaredName($code) ?? $path);
        }

        return MutationScope::changed($base, $sources);
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
