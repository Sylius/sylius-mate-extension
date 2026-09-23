<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Tool\Project;

use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\MateExtension\Tool\Project\ProjectProfile;

final class ProjectProfileTest extends IntegrationTestCase
{
    public function testDetectsNamespaceAndLocaleOfTheHostProject(): void
    {
        $result = (new ProjectProfile(self::host()))();

        $profile = $result['items'][0];
        self::assertSame('Sylius\\TestApplication', $profile['app_namespace']);
        self::assertSame('Sylius\\TestApplication\\', $profile['app_namespace_with_separator']);
        self::assertSame('en_US', $profile['default_locale']);
        self::assertContains('en_US', $profile['enabled_locales']);
        self::assertSame(self::testApplicationDir(), $profile['project_dir']);
        self::assertFalse($profile['mailer_dsn_observable']);
    }
}
