<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Playwright;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Output\Envelope;

#[McpTool(
    name: 'sylius_playwright_recipe',
    description: 'Emit a tests/Playwright/<slug>.spec.ts script for a Sylius flow. Pass custom steps OR template="back_in_stock_flow" preset. NEVER emit raw "doctrine:query:sql UPDATE" against entities observed by listeners — the listener will not fire. Mutations must go through bin/console commands (e.g. app:variant:restock) or the admin UI. The preset emits an execSync("bin/console app:variant:restock ...") helper so the inventory listener runs.',
)]
final class PlaywrightRecipe
{
    public function __construct(
        private readonly string $scaffoldDir,
    ) {
    }

    /**
     * @param array<string, list<string>> $steps
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $feature_alias,
        string $title,
        array $steps = [],
        ?string $template = null,
        ?string $variant_code = null,
        ?string $product_slug = null,
    ): array {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $feature_alias)) {
            return Envelope::error('invalid_feature_alias', 'Argument "feature_alias" must be snake_case (e.g. "back_in_stock_notify").');
        }

        $warnings = $this->detectRawSqlMutations($steps);

        if (null !== $template) {
            if ('back_in_stock_flow' !== $template) {
                return Envelope::error('unknown_template', sprintf('Preset template "%s" is not known.', $template), 'Supported: back_in_stock_flow.');
            }

            if (null === $variant_code || null === $product_slug) {
                return Envelope::error(
                    'missing_template_args',
                    'Preset "back_in_stock_flow" requires variant_code and product_slug.',
                    'Pass both arguments; pull values from sylius_domain_list_resources + fixture inventory.',
                );
            }

            $steps = $this->backInStockFlow($variant_code, $product_slug);
        }

        $scriptTemplate = @file_get_contents($this->scaffoldDir . '/playwright_recipe.ts.tpl');
        if (false === $scriptTemplate) {
            return Envelope::error('template_missing', 'Scaffold template playwright_recipe.ts.tpl not found.');
        }

        $imports = [
            "import { test, expect } from '@playwright/test';",
            "import { execSync } from 'node:child_process';",
        ];

        $helpers = $this->renderHelpers();

        $body = sprintf(
            "%s\n\n%s\n\ntest('%s', async ({ page, request }) => {\n%s\n});\n",
            implode("\n", $imports),
            $helpers,
            $title,
            $this->renderSteps($steps),
        );

        $envelope = Envelope::items(
            [[
                'kind' => 'playwright_spec',
                'suggested_path' => sprintf('tests/Playwright/%s.spec.ts', $feature_alias),
                'body' => $body,
            ]],
            null,
            'Write the spec, then run via the Playwright MCP. Mutations on entities observed by Doctrine listeners MUST go through bin/console commands (use restockVariant helper). Pull mailer assertions from the Mate Symfony profiler MCP.',
        );

        if ([] !== $warnings) {
            $envelope['warnings'] = $warnings;
        }

        return $envelope;
    }

    /**
     * @param array<string, list<string>> $steps
     *
     * @return list<string>
     */
    private function detectRawSqlMutations(array $steps): array
    {
        $warnings = [];
        foreach ($steps as $phase => $phaseSteps) {
            if (!\is_array($phaseSteps)) {
                continue;
            }

            foreach ($phaseSteps as $step) {
                if (!\is_string($step)) {
                    continue;
                }

                if (preg_match('/doctrine:query:sql.*UPDATE/i', $step) || preg_match('/UPDATE\\s+sylius_/i', $step)) {
                    $warnings[] = sprintf(
                        'Step in phase "%s" looks like a raw SQL UPDATE. Doctrine listeners will NOT fire — replace with bin/console command or admin UI flow.',
                        (string) $phase,
                    );
                }
            }
        }

        return $warnings;
    }

    private function renderHelpers(): string
    {
        return <<<'TS'
            function restockVariant(code: string, qty: number): void {
                execSync(`bin/console app:variant:restock ${code} ${qty}`, { stdio: 'inherit' });
            }
            TS;
    }

    /**
     * @return array<string, list<string>>
     */
    private function backInStockFlow(string $variantCode, string $productSlug): array
    {
        return [
            'setup' => [
                sprintf("restockVariant('%s', 0)", $variantCode),
            ],
            'scenario' => [
                sprintf("await page.goto('/en_US/products/%s')", $productSlug),
                "await expect(page.getByTestId('back-in-stock-form')).toBeVisible()",
                "await page.getByLabel('Email').fill('shopper@example.com')",
                "await page.getByRole('button', { name: /subscribe/i }).click()",
                "await expect(page.getByText(/we will notify you/i)).toBeVisible()",
                sprintf("restockVariant('%s', 10)", $variantCode),
                "// hit GET /_profiler/<X-Debug-Token>.json?panel=mailer (or Mate profiler MCP) to assert the queued email",
                sprintf("await page.goto('/en_US/products/%s')", $productSlug),
                "await expect(page.getByTestId('back-in-stock-form')).toBeHidden()",
            ],
            'teardown' => [],
        ];
    }

    /**
     * @param array<string, list<string>> $steps
     */
    private function renderSteps(array $steps): string
    {
        $lines = [];
        foreach (['setup', 'scenario', 'teardown'] as $phase) {
            $phaseSteps = $steps[$phase] ?? null;
            if (!\is_array($phaseSteps) || [] === $phaseSteps) {
                continue;
            }

            $lines[] = sprintf('    // %s', strtoupper($phase));
            foreach ($phaseSteps as $step) {
                if (!\is_string($step) || '' === trim($step)) {
                    continue;
                }

                $lines[] = '    ' . rtrim($step, ';') . ';';
            }

            $lines[] = '';
        }

        if ([] === $lines) {
            $lines = ['    // TODO: add steps'];
        }

        return trim(implode("\n", $lines));
    }
}
