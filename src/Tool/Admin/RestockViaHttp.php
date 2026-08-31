<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Admin;

use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Output\Envelope;
use Symfony\AI\Mate\Attribute\MateTool;
use Symfony\Component\Routing\RouterInterface;

final class RestockViaHttp
{
    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[MateTool(
        name: 'sylius_admin_restock_via_http',
        description: 'Compose a Playwright-friendly recipe for restocking a product variant via the admin UI (so the change goes through ORM + listeners and yields an X-Debug-Token for profiler-based mailer assertion). Returns the admin route, HTTP method, form field hints, and the next step (read the profiler via the Mate Symfony profiler tools with the resulting token). Does NOT execute the request — Playwright does.',
    )]
    public function __invoke(string $variant_code, int $on_hand): array
    {
        if ('' === trim($variant_code)) {
            return Envelope::error('invalid_variant_code', 'Argument "variant_code" must not be empty.');
        }

        if ($on_hand < 0) {
            return Envelope::error('invalid_on_hand', 'Argument "on_hand" must be >= 0.');
        }

        return Envelope::guard(fn (): array => $this->compose($variant_code, $on_hand));
    }

    /**
     * @return array<string, mixed>
     */
    private function compose(string $variant_code, int $on_hand): array
    {
        $router = $this->host->getContainer()->get('router');
        if (!$router instanceof RouterInterface) {
            return Envelope::error('router_unavailable', 'Service "router" is not a Symfony\\Component\\Routing\\RouterInterface.');
        }

        $route = $this->locateRoute($router);
        if (null === $route) {
            return Envelope::error(
                'admin_route_missing',
                'Could not locate an admin product-variant update route.',
                'Run sylius_routes_show name_prefix="sylius_admin_product_variant" to confirm the route exists for this Sylius version.',
            );
        }

        $playwrightSnippet = $this->renderSnippet($route['path'], $variant_code, $on_hand);

        return Envelope::items(
            [[
                'route_name' => $route['name'],
                'request_path' => $route['path'],
                'method' => 'PATCH',
                'fields' => [
                    'sylius_product_variant[onHand]' => (string) $on_hand,
                    'sylius_product_variant[tracked]' => '1',
                ],
                'requires_authentication' => true,
                'playwright_snippet' => $playwrightSnippet,
                'next_step' => 'After Playwright runs the PATCH, capture response header "x-debug-token" and call the Mate Symfony profiler tools to read the mailer collector.',
            ]],
            null,
            sprintf('Use route "%s" with PATCH (PUT also supported by Sylius admin form handlers).', $route['name']),
        );
    }

    /**
     * @return ?array{name: string, path: string}
     */
    private function locateRoute(RouterInterface $router): ?array
    {
        $collection = $router->getRouteCollection();
        $candidateNames = [
            'sylius_admin_product_variant_update',
            'sylius_admin_product_variant_edit',
        ];

        foreach ($candidateNames as $candidate) {
            $route = $collection->get($candidate);
            if (null === $route) {
                continue;
            }

            return ['name' => $candidate, 'path' => $route->getPath()];
        }

        foreach ($collection->all() as $name => $route) {
            if (str_contains($name, 'admin') && str_contains($name, 'variant') && str_contains($name, 'update')) {
                return ['name' => $name, 'path' => $route->getPath()];
            }
        }

        return null;
    }

    private function renderSnippet(string $path, string $variantCode, int $onHand): string
    {
        return implode("\n", [
            sprintf("// Path: %s — substitute {productId}/{id} from the admin variant listing for code '%s'", $path, $variantCode),
            "const response = await request.patch(adminVariantUrl, {",
            "    form: {",
            sprintf("        'sylius_product_variant[onHand]': '%d',", $onHand),
            "        'sylius_product_variant[tracked]': '1',",
            "    },",
            "});",
            "const token = response.headers()['x-debug-token'];",
            "expect(token).toBeTruthy();",
        ]);
    }
}
