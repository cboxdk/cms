---
title: Gates and CI
weight: 22
description: The eleven gates every change passes, composer check as the local profile, the PR profile that bin/ci runs in CI, and the selftest that proves each gate catches a violation.
---

# Gates and CI

Every change passes the same gates, in the same order. Each gate runs a Composer or npm script that a developer can also run by hand, so a gate's command is defined once.

| Gate | What it checks | The command | Local profile | PR profile |
|---|---|---|---|---|
| 1 | Formatting of PHP and JS | `composer lint:check`, `npm run format:check` | yes | yes |
| 2 | Rector | `composer rector:check` | yes | yes |
| 3 | PHPStan at level 10 with the testkit's rules | `composer analyse` | yes | yes |
| 4 | tsc and ESLint | `npm run typecheck`, `npm run lint` | yes | yes |
| 5 | The installation, then the Pest suites `Unit`, `Codecs`, `Contract`, `Postgres`, `Arch` and `Actions` | `composer install:check`, `vendor/bin/pest --testsuite=<suite> --parallel` | yes | yes, with the `Mutation` suite and mutation testing on the changed files |
| 6 | Generated code is the committed code | `composer check:generated` | yes | yes |
| 7 | Storybook, visual regression and axe | | no | not run until the panel has a UI |
| 8 | The `Browser` suite | `vendor/bin/pest --testsuite=Browser`, locally `composer image:run -- vendor/bin/pest --testsuite=Browser` | no | yes |
| 9 | Known vulnerabilities in the dependencies | `composer audit --locked --abandoned=report`, `npm audit` | no | yes |
| 10 | Documentation | `composer docs:check` | no | yes |
| 11 | Review of changed checks by someone other than the author | | no | not run until branch protection on main requires review by someone other than the author, a repository setting of github.com/cboxdk/cms |

## composer check

`composer check` runs the local profile, gates 1 to 6. It runs every gate also after one has failed, marks each gate and each step pass, fail or not run, prints a summary, and exits 1 when a gate failed. It needs the services, `composer services:up`: with Postgres or Valkey down, gate 5 fails, it does not skip.

Options go after `--`: `composer check -- --report=<file>` also writes a JSON report, and `--brief` leaves the output of failed steps out of the console.

Gate 5 starts with `composer install:check`, which fails when `vendor/` is not the installation `composer.lock` describes, so a checkout that moved without `composer install` fails with the fix instead of a missing class. Each suite then runs on its own with `--fail-on-skipped --fail-on-incomplete --parallel`: the same tests, spread over one worker process per CPU. Each worker of the `Postgres` suite gets a test database of its own, so the workers never share rows. A suite added to `phpunit.xml` must also be added to the profile; a test fails until it is.

Gate 10 is not in the local profile, but the `Unit` suite runs the same documentation audit on the repository, so `composer check` fails on everything `composer docs:check` would find.

## The dev image

The gates run in the php-baseimages dev image, `ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1`, on your machine as in CI: PHP 8.5 with PCOV and Xdebug, Node 22, and Playwright's Chromium in `/ms-playwright`. `composer check` started on the host checks its options and then runs itself again in a container of the image, for the checkout you run it in, a git worktree included:

- The checkout is mounted at its own absolute path and is the working directory, so the reports name the paths you see, and the checkout gets its own test database, which the testkit derives from that path. A worktree also gets the main checkout's `.git` at its path, so git works in it.
- `node_modules` is a Docker volume of the checkout's own, `laravel-cms-node-modules-<hash of the checkout's path>`, because the `node_modules` on a Mac holds macOS binaries. The first run, and every run after `package-lock.json` changed, installs it with `npm ci` in the image.
- Your `~/.pest` is mounted, so the graph of `composer test:affected` is shared.
- It runs as your uid and gid, never as root, because root ignores file permissions and the tests of unwritable files would skip, and with your machine's name.
- `.cache`, where PHPStan, Rector and Pint keep their caches, and the bootstrap cache of the Testbench application are Docker volumes of the checkout's own too, because on Docker Desktop a file that one process replaces with a rename can be missing for a moment to another process reading it through the mount, and the parallel workers rewrite files there. Each run starts by copying the host's bootstrap cache into its volume, so it starts from the manifests and the registry cache the host has.
- It joins the network of the main checkout's services and reaches them as `postgres` and `valkey`. It never starts them: when Postgres or Valkey is not running and healthy, it stops with exit 1 and says to run `composer services:up` in the main checkout.

A process that already runs in the image, where the image sets `CBOX_IMAGE_TIER=dev`, runs the gates in place: CI, the php service and the container itself. `composer image:run -- <command>` runs any command the same way, such as `composer image:run -- vendor/bin/pest --testsuite=Browser` for gate 8 or `--testsuite=Mutation`, which needs PCOV. `composer image:prune` removes the volumes of checkouts that are gone, and `composer image:prune -- --dry-run` only lists them.

## composer test:affected

`composer test:affected` gives fast feedback while you work; it is not a gate. It runs `vendor/bin/pest --parallel --tia` in the dev image: Pest's test impact analysis records, per test, the files and tables it touches, through PCOV, in a graph below `~/.pest/tia`. The first run records the graph and runs everything; after that, a run reruns only the tests that depend on what changed and replays the results of the others. Arguments go after `--`; one that selects tests, such as `--filter` or `--testsuite`, makes Pest run the selection without the analysis.

The analysis takes Pest files only and stops at a PHPUnit test class, so the command runs twice: first the Pest files with the analysis, then every PHPUnit test class, such as the classes of the contract suites, in full. Both configurations are `phpunit.xml` with the other kind of file left out.

## The documentation gate

`composer docs:check` fails when:

- a public extension point, meaning an interface, attribute class or trait of `packages/*/src` that is not `#[Internal]`, a class with `#[Command]` or `#[Hook]`, or a JSON Schema in `packages/*/resources/schemas`, has no page, or is on two pages;
- a page that documents an extension point has no running example;
- a fenced block on a page is not a file of the repository byte for byte, embedded with an `example` or `example-file` marker, or an example test is in no suite of gate 5 or asserts nothing;
- `docs/` breaks the layout: files other than `index.md`, `quickstart.md` and `requirements.md` at its root, a folder without `_index.md`, a page without `title`, `weight` and `description` in its frontmatter, or an `_index.md` whose weight is not the lowest in its folder;
- the root of the repository lacks `README.md`, `LICENSE`, `SECURITY.md` or `CONTRIBUTING.md`;
- a relative link or image in `docs/`, `README.md`, `CONTRIBUTING.md` or `SECURITY.md` points to a file, folder or heading that does not exist;
- the screenshots in `docs/screenshots` and the manifest `Cbox\Cms\Tooling\Docs\Domain\Screenshots` do not match, or a page embeds a screenshot with another caption than the manifest's;
- a Markdown file is below `packages/`.

`composer docs:screenshots` captures the screenshots again; see [Screenshots](../screenshots/_index.md).

`composer docs:requirements` writes [Requirements](../requirements.md) again from `composer.json`, `package.json` and `compose.yaml`. It is not part of gate 10: a test in the Unit suite of gate 5 fails when the committed page differs from what it writes.

## CI

CI is one entry script, `bin/ci`. `.github/workflows/ci.yml` runs it on every pull request and every push to `main`, on the PHP image and the Postgres and Valkey images of `compose.yaml`. It installs the locked dependencies and runs `composer check -- --pr`, the PR profile: the same steps as the local profile for gates 1 to 6, gate 5 with the `Mutation` suite and mutation testing on the classes changed since the base of the change, and gates 8, 9 and 10. Gates 7 and 11 are reported as not run, each with its reason.

To run CI locally in a container on a clean archive of `HEAD`, commit first and run `docker compose -f compose.ci.yaml run --rm ci`. Mutation testing needs the base of the change on the host, for example `CMS_CI_BASE_REF=HEAD~1 docker compose -f compose.ci.yaml run --rm ci`. `docker compose -f compose.ci.yaml down` stops its services. The budget for a CI run is 15 minutes.

## The selftest

`composer check:selftest` proves that each gate catches what it is there to catch. It makes a git worktree of `HEAD` in the system's temporary directory, installs the dependencies there, plants one known violation per gate, and passes only when each gate fails with a path inside that worktree. Then it drops the worktree's test database and removes the worktree. It checks `HEAD`, so commit first. Run it after changing a gate, the configuration of a tool, or the check itself.

## Changing a check

A check that verifies a change is never weakened to let the change pass: not the analysis configuration, not a test filter, not an architecture test and not CI. A check that looks wrong is left as it is and raised for review.
