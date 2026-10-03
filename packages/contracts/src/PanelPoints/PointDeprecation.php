<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * That a panel point is deprecated (PRD 13.4): the panel API release it was deprecated in, the
 * release it is removed in, and the point that replaces it, if any. A point declares it with
 * `deprecated: new PointDeprecation(since: '1.2', removeIn: '2.0', replacement: 'notes.form.submit@2')`
 * on its #[PanelPoint], and cms:build warns about every contribution to it with
 * registry_panel_point_deprecated, naming the release and the replacement.
 */
#[Experimental]
final readonly class PointDeprecation
{
    /**
     * @param  string  $since  the panel API release it was deprecated in, `<major>.<minor>`
     * @param  string  $removeIn  the panel API release it is removed in, `<major>.<minor>`, after since
     * @param  string|null  $replacement  the id of the point that replaces it, `<name>@<version>`
     *
     * @throws InvalidPanelPoint
     */
    public function __construct(
        public string $since,
        public string $removeIn,
        public ?string $replacement = null,
    ) {
        foreach (['since' => $since, 'removeIn' => $removeIn] as $what => $release) {
            if (preg_match(PanelPoint::SINCE_PATTERN, $release) !== 1) {
                throw InvalidPanelPoint::because(sprintf('The deprecation\'s %s, "%s", is not a release of the panel API as <major>.<minor>, such as "1.2".', $what, $release));
            }
        }

        if ($this->release($removeIn) <= $this->release($since)) {
            throw InvalidPanelPoint::because(sprintf('The deprecation is removed in %s, which is not after %s, the release it was deprecated in.', $removeIn, $since));
        }

        if ($replacement !== null) {
            PointId::fromString($replacement);
        }
    }

    /**
     * The replacement's id, or null when the point has none.
     */
    public function replacementId(): ?PointId
    {
        return $this->replacement === null ? null : PointId::fromString($this->replacement);
    }

    /**
     * The release as one number that orders releases: the major version, then the minor.
     */
    private function release(string $release): int
    {
        [$major, $minor] = explode('.', $release);

        return (int) $major * 10000 + (int) $minor;
    }
}
