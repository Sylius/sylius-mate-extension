<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\Integration\Tool;

use Sylius\MateExtension\Hook\HookablesReader;
use Sylius\MateExtension\Resource\ResourceLinker;
use Sylius\MateExtension\Tests\Integration\IntegrationTestCase;
use Sylius\MateExtension\Tests\TestApplication\Entity\Book;
use Sylius\MateExtension\Tool\Grid\GridDefinition;
use Sylius\MateExtension\Tool\Grid\ListGrids;
use Sylius\MateExtension\Tool\Hook\ListHookables;
use Sylius\MateExtension\Tool\Hook\ListHooks;
use Sylius\MateExtension\Tool\Resource\InspectResource;
use Sylius\MateExtension\Tool\Resource\ListResources;
use Sylius\MateExtension\Tool\Route\InspectRoute;
use Sylius\MateExtension\Tool\Route\ShowRoute;
use Sylius\MateExtension\Tool\Twig\ListFunctions;
use Sylius\MateExtension\Tool\Twig\VerifyFunction;
use Symfony\AI\Mate\Attribute\MateResourceTemplate;
use Symfony\AI\Mate\Discovery\Model\ResourceTemplateDefinition;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use Webmozart\Assert\Assert;

final class ResourceTemplatesTest extends IntegrationTestCase
{
    public function testHookUriFromTheListReadsTheHookables(): void
    {
        $reader = new HookablesReader(self::host());
        $uri = (new ListHooks($reader))('mate_test.')['items'][0]['uri'];

        self::assertSame('sylius://hook/mate_test.page.content', $uri);
        self::assertSame((new ListHookables($reader))('mate_test.page.content'), $this->read(new ListHookables($reader), $uri));
    }

    public function testResourceUriFromTheListReadsTheDiagnostic(): void
    {
        $uri = (new ListResources(self::host()))('mate_test.')['items'][0]['uri'];

        self::assertSame('sylius://resource/mate_test.book', $uri);
        self::assertSame((new InspectResource(self::host(), new ResourceLinker(self::host())))('mate_test.book'), $this->read(new InspectResource(self::host(), new ResourceLinker(self::host())), $uri));
    }

    public function testRouteUriFromTheListReadsTheDiagnostic(): void
    {
        $items = (new ShowRoute(self::host()))(null, 'mate_test_ping')['items'];
        $uri = $items[0]['uri'];

        self::assertSame('sylius://route/mate_test_ping', $uri);
        self::assertSame((new InspectRoute(self::host(), new ResourceLinker(self::host())))('mate_test_ping'), $this->read(new InspectRoute(self::host(), new ResourceLinker(self::host())), $uri));
    }

    public function testTwigUriFromTheListReadsTheSignatureOfThatKind(): void
    {
        $items = (new ListFunctions(self::host()))('mate_test_')['items'];
        $uris = array_column($items, 'uri');

        self::assertSame(['sylius://twig/filter/mate_test_shout', 'sylius://twig/function/mate_test_greet'], $uris);
        self::assertSame((new VerifyFunction(self::host()))('mate_test_greet', 'function'), $this->read(new VerifyFunction(self::host()), $uris[1]));
    }

    public function testTwigNameWithSpacesIsPercentEncodedInTheUriAndReadBack(): void
    {
        $items = (new ListFunctions(self::host()))('divisible', 'test')['items'];

        self::assertSame(['sylius://twig/test/divisible%20by'], array_column($items, 'uri'));
        self::assertSame((new VerifyFunction(self::host()))('divisible by', 'test'), $this->read(new VerifyFunction(self::host()), $items[0]['uri']));
    }

    public function testGridUriFromTheListReadsTheFullDefinition(): void
    {
        $uri = (new ListGrids(self::host()))('mate_test_')['items'][0]['uri'];

        self::assertSame('sylius://grid/mate_test_book', $uri);

        $grid = $this->read(new GridDefinition(self::host(), new ResourceLinker(self::host())), $uri)['items'][0];
        self::assertSame('mate_test_book', $grid['name']);
        self::assertSame(Book::class, $grid['driver']['options']['class']);
        self::assertSame(['id', 'title'], array_keys($grid['fields']));
    }

    public function testUnknownGridIsReportedAsEmpty(): void
    {
        $result = (new GridDefinition(self::host(), new ResourceLinker(self::host())))->describe('mate_test_missing');

        self::assertSame([], $result['items']);
        self::assertStringContainsString('sylius_domain_list_grids', $result['note']);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(object $handler, string $uri): array
    {
        foreach ((new \ReflectionObject($handler))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attribute = $method->getAttributes(MateResourceTemplate::class)[0] ?? null;
            if (null === $attribute) {
                continue;
            }

            $template = $attribute->newInstance();
            $definition = new ResourceTemplateDefinition($template->uriTemplate, $template->name, $template->title, $template->description, $template->mimeType, $handler::class, $method->getName());
            $variables = $definition->extractVariables($uri);
            self::assertNotNull($variables, sprintf('URI "%s" does not match template "%s".', $uri, $template->uriTemplate));

            $content = $method->invokeArgs($handler, $variables);
            Assert::isArray($content);
            Assert::string($content['text']);
            self::assertSame($uri, $content['uri']);

            $decoded = ResponseEncoder::decode($content['text']);
            Assert::isArray($decoded);

            return $decoded;
        }

        self::fail(sprintf('"%s" has no #[MateResourceTemplate] method.', $handler::class));
    }
}
