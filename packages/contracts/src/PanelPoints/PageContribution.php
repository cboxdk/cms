<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A page of the addon in the panel (PRD 13.4), under `<prefix>/x/<namespace>/<path>`, so an
 * addon's pages never collide with the core's or another addon's. Its component is registered
 * in the addon's bundle under the contribution's id.
 *
 * - path: one or more segments of lowercase letters, digits and single hyphens, such as
 *   "queue".
 * - data: a #[Query] of the addon, or null; its result is the page's only props, so the same
 *   data can be read from REST. A page has no point props, so the query takes no required
 *   input.
 *
 * The id, point, priority and scope are those of every contribution (PanelContribution).
 */
#[Experimental]
final readonly class PageContribution implements PanelContribution
{
    public const string PATH_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*\z/';

    public const int PATH_MAX_LENGTH = 128;

    public int $priority;

    public ?string $data;

    /**
     * @param  string  $path  the page's path below the addon's, such as "queue"
     * @param  string|null  $data  the class of a #[Query] of the addon
     *
     * @throws InvalidAddonManifest when a value breaks its rule
     */
    public function __construct(
        public ContributionId $id,
        public string $point,
        public string $path,
        ?string $data = null,
        int $priority = self::DEFAULT_PRIORITY,
        public Scope $scope = new Scope,
    ) {
        $this->priority = ContributionRules::priority($id->value, $priority);
        if (strlen($path) > self::PATH_MAX_LENGTH || preg_match(self::PATH_PATTERN, $path) !== 1) {
            throw InvalidAddonManifest::because(sprintf('The page %s has the path "%s", which is not segments of lowercase letters, digits and single hyphens separated by slashes, of at most %d characters, such as "queue".', $id->value, $path, self::PATH_MAX_LENGTH));
        }

        $this->data = $data === null ? null : ContributionRules::className($id->value, 'data query', $data);
    }

    public function id(): ContributionId
    {
        return $this->id;
    }

    public function point(): string
    {
        return $this->point;
    }

    public function kind(): PointKind
    {
        return PointKind::Page;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function runsCode(): bool
    {
        return true;
    }
}
