<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Unit\Tool\Service;

use PHPUnit\Framework\TestCase;
use Sylius\MateExtension\Tests\Unit\Fake\FakeHostContainerProvider;
use Sylius\MateExtension\Tool\Service\ServicesYamlProfile;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class ServicesYamlProfileTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/sylius-mate-services-' . bin2hex(random_bytes(4));
        mkdir($this->sandbox . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->sandbox . '/config/services.yaml');
        @rmdir($this->sandbox . '/config');
        @rmdir($this->sandbox);
    }

    public function testReadsDefaultsAndGlobs(): void
    {
        file_put_contents(
            $this->sandbox . '/config/services.yaml',
            <<<'YAML'
            services:
                _defaults:
                    autowire: true
                    autoconfigure: true
                    public: false

                _instanceof:
                    Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType:
                        autowire: false

                App\:
                    resource: '../src/'
                    exclude:
                        - '../src/Entity/'
                        - '../src/Repository/'

                App\Controller\:
                    resource: '../src/Controller/'
                    tags: ['controller.service_arguments']
            YAML,
        );

        $tool = new ServicesYamlProfile($this->host());

        $result = ($tool)();

        $profile = $result['profile'];
        self::assertSame(['autowire' => true, 'autoconfigure' => true, 'public' => false], $profile['defaults']);
        self::assertArrayHasKey('Sylius\\Bundle\\ResourceBundle\\Form\\Type\\AbstractResourceType', $profile['instanceof_overrides']);
        self::assertSame('App\\', $profile['app_glob']['service_pattern']);
        self::assertSame('../src/', $profile['app_glob']['resource']);
        self::assertSame('App\\Controller\\', $profile['controller_glob']['service_pattern']);
    }

    public function testReturnsErrorWhenMissing(): void
    {
        $tool = new ServicesYamlProfile($this->host());

        $result = ($tool)();

        self::assertSame('services_yaml_missing', $result['error']['code']);
    }

    private function host(): FakeHostContainerProvider
    {
        $container = new Container(new ParameterBag(['kernel.project_dir' => $this->sandbox]));

        return new FakeHostContainerProvider($container);
    }
}
