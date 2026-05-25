<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Translation;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Translation\TranslationCreate;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Yaml\Yaml;

final class TranslationCreateTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/sylius-mate-i18n-' . bin2hex(random_bytes(4));
        mkdir($this->sandbox, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->sandbox);
    }

    public function testWritesNewTranslationFile(): void
    {
        $tool = new TranslationCreate($this->host('en'));

        $result = ($tool)(['app' => ['email' => ['back_in_stock' => ['subject' => 'In stock again']]]]);

        self::assertSame(['en'], $result['locales']);
        self::assertTrue($result['written']);
        self::assertTrue($result['cache_clear_required']);

        $path = $this->sandbox . '/translations/messages.en.yaml';
        self::assertFileExists($path);
        $parsed = Yaml::parseFile($path);
        self::assertSame('In stock again', $parsed['app']['email']['back_in_stock']['subject']);
    }

    public function testMergesIntoExistingFile(): void
    {
        $dir = $this->sandbox . '/translations';
        mkdir($dir);
        file_put_contents($dir . '/messages.en.yaml', "app:\n    existing: keep me\n");

        $tool = new TranslationCreate($this->host('en'));

        ($tool)(['app' => ['new' => 'added']]);

        $parsed = Yaml::parseFile($dir . '/messages.en.yaml');
        self::assertSame('keep me', $parsed['app']['existing']);
        self::assertSame('added', $parsed['app']['new']);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $tool = new TranslationCreate($this->host('en_US'));

        $result = ($tool)(['app' => ['x' => 'y']], dry_run: true);

        self::assertFalse($result['written']);
        self::assertFileDoesNotExist($this->sandbox . '/translations/messages.en_US.yaml');
    }

    public function testRejectsInvalidLocale(): void
    {
        $tool = new TranslationCreate($this->host('en'));

        $result = ($tool)(['x' => 'y'], 'NOT-A-LOCALE');

        self::assertSame('invalid_locale', $result['error']['code']);
    }

    private function host(string $locale): FakeHostContainerProvider
    {
        $container = new Container(new ParameterBag([
            'kernel.project_dir' => $this->sandbox,
            'kernel.default_locale' => $locale,
        ]));

        return new FakeHostContainerProvider($container);
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
