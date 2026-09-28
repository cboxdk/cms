<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * A fixed state of the world that a screenshot's command runs in, so capturing it again gives the
 * same image: `tools/bin/docs-scene.php <scene> <artisan command>` runs the command in the
 * workbench application with the Clock and the doctor's probes bound to the scene's fixtures
 * (Adapter\SceneFixtures) instead of the real clock, files and services, whose times, versions and
 * paths change from one machine and one day to the next.
 */
enum Scene: string
{
    /** Every probe answers as a healthy development environment does. */
    case Healthy = 'healthy';

    /** As Healthy, but PHP has allow_url_fopen on. */
    case AllowUrlFopen = 'allow-url-fopen';
}
