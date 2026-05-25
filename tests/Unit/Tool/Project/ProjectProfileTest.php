<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Project;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Project\ProjectProfile;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class ProjectProfileTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/sylius-mate-profile-' . bin2hex(random_bytes(4));
        mkdir($this->sandbox . '/src', 0777, true);
        mkdir($this->sandbox . '/translations', 0777, true);
        file_put_contents($this->sandbox . '/src/Kernel.php', "<?php namespace Elesto; class Kernel {}\n");
        file_put_contents(
            $this->sandbox . '/composer.json',
            json_encode([
                'autoload' => ['psr-4' => ['Elesto\\' => 'src/']],
            ], \JSON_PRETTY_PRINT),
        );
        file_put_contents($this->sandbox . '/translations/messages.en.yaml', "k: v\n");
        file_put_contents($this->sandbox . '/translations/messages.pl.yaml', "k: v\n");
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->sandbox);
    }

    public function testDetectsAppNamespaceAndLocales(): void
    {
        $tool = new ProjectProfile($this->host(['kernel.project_dir' => $this->sandbox, 'kernel.default_locale' => 'en']));

        $result = ($tool)();

        self::assertSame('Elesto', $result['items'][0]['app_namespace']);
        self::assertSame('Elesto\\', $result['items'][0]['app_namespace_with_separator']);
        self::assertSame(['en', 'pl'], $result['items'][0]['enabled_locales']);
        self::assertFalse($result['items'][0]['mailer_dsn_observable']);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function host(array $params): FakeHostContainerProvider
    {
        return new FakeHostContainerProvider(new Container(new ParameterBag($params)));
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $child = $path . '/' . $item;
            is_dir($child) ? $this->deleteTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
