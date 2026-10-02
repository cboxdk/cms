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

CI is one entry script, `bin/ci`. `.github/workflows/ci.yml` runs it on every pull request, every push to `main` and every run started by hand, on the PHP image and the Postgres and Valkey images of `compose.yaml`. It installs the locked dependencies and runs `composer check -- --pr`, the PR profile: the same steps as the local profile for gates 1 to 6, and gates 8, 9 and 10. Gates 7 and 11 are reported as not run, each with its reason.

Mutation testing, the `Mutation` suite and mutation testing on the classes changed since the base of the change, is deferred until after v1 (Sylvester, 2 October 2026): it made every merge take far longer, and the architecture will change before v1, so tests of tests are not worth the time now. The ordinary tests stay required. A pull request and a push to `main` run only the gates job, whose gate 5 reports both mutation steps as not run with that reason; the gates job is the run's result and the status check a pull request requires. Mutation testing runs only on demand: `composer check -- --pr --mutation`, `CMS_CI_MUTATION=1` for `bin/ci`, or a run started by hand with the input `mutation`, from Actions or with `gh workflow run ci.yml --ref main -f mutation=true`.

A run with mutation testing runs the PR profile as parallel jobs, so a run takes as long as its slowest job, and each job has the budget of 15 minutes. Each job runs `bin/ci` with `CMS_CI_PART` naming its part:

| Job | Part | What it runs |
|---|---|---|
| plan | `plan` | `composer mutation:plan`: the files below `packages/*/src` changed since the base of the change, split into shards by file, one shard per 10 files and at most 100, each file in exactly one shard |
| gates | `gates` | `composer check -- --pr --mutation --only=gates`: every gate and the `Mutation` suite, but not mutation testing on the changed files; without mutation testing, `composer check -- --pr` |
| mutation, one job per shard | `shard:<i>/<n>` | `composer check -- --pr --mutation --shard=<i>/<n>`: mutation testing on the files of shard i only, against the fast suites, then the mutations they did not catch against the `Postgres` suite |
| verdict | `verdict` | `composer mutation:verdict`: fails unless the plan, the gates and every shard passed, every shard reported its files, and every changed class reaches a score of 80 over all its mutations |

The files of `Adapter` and `Infrastructure`, whose mutations mostly need the `Postgres` suite, get shards of their own, sized as if each file were twice as large. The testkit's shared contract suites, the traits named `*Contract` in `packages/testkit/src` that each implementation's test class uses, are left out of mutation testing; their mutations remove assertions, which only a test of the tests could catch. A mutation that no test can catch because the mutated code behaves as the original, an equivalent mutation, is removed from the code where that is simple; otherwise it is an entry of `EquivalentMutations::kernel()` in `tools/src/Mutation/Domain/EquivalentMutations.php`: the source, the line and Pest's mutator, with the reason. A listed mutation is left out of its class's score and never counted as caught, so the minimum of 80 holds for the rest. An entry that names no surviving mutation, because the code moved or a test catches it now, fails the step, and a test holds every entry to the code as it is. A mutation can drop the directory from a path and write a file into the checkout; the run of each step removes, when it ends, every file git neither tracks nor ignores that appeared during it. A run started by hand, from Actions or with `gh workflow run ci.yml --ref main -f mutation=true -f base_ref=<commit>`, takes the base of the change as its input `base_ref`, so the changes of several pushes, such as a whole block's, run as one matrix. To see the plan of your change, run `CMS_CI_BASE_REF=main composer mutation:plan`; to run one shard of it locally, `CMS_CI_BASE_REF=main composer check -- --pr --mutation --shard=<i>/<n>`.

To run CI locally in a container on a clean archive of `HEAD`, commit first and run `docker compose -f compose.ci.yaml run --rm ci`. It runs the gates without mutation testing and prints the wall time against the budget. With `CMS_CI_MUTATION=1` it runs every part one after the other, the plan, the gates, each shard and the verdict, and prints each part's wall time. `CMS_CI_PART=gates`, or with mutation testing `CMS_CI_PART=shard:2/5`, runs one part. Mutation testing needs the base of the change on the host, for example `CMS_CI_MUTATION=1 CMS_CI_BASE_REF=HEAD~1 docker compose -f compose.ci.yaml run --rm ci`. `docker compose -f compose.ci.yaml down` stops its services.

## The selftest

`composer check:selftest` proves that each gate catches what it is there to catch. It makes a git worktree of `HEAD` in the system's temporary directory, installs the dependencies there, plants one known violation per gate, and passes only when each gate fails with a path inside that worktree. Then it drops the worktree's test database and removes the worktree. It checks `HEAD`, so commit first. Run it after changing a gate, the configuration of a tool, or the check itself.

## Changing a check

A check that verifies a change is never weakened to let the change pass: not the analysis configuration, not a test filter, not an architecture test and not CI. A check that looks wrong is left as it is and raised for review.
