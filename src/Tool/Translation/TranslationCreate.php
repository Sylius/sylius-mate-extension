<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tool\Translation;

use Mcp\Capability\Attribute\McpTool;
use Sylius\MateExtension\Kernel\HostContainerProvider;
use Sylius\MateExtension\Kernel\HostProjectDir;
use Sylius\MateExtension\Output\Envelope;
use Symfony\Component\Yaml\Yaml;

#[McpTool(
    name: 'sylius_translation_create',
    description: 'Write or merge a translation key tree into translations/<domain>.<locale>.yaml for the host project. Accepts locales[] (multi-locale) — one file per locale. Defaults to kernel.default_locale or the project profile enabled_locales. Returns merged content per locale + cache_clear_required flag.',
)]
final class TranslationCreate
{
    public function __construct(
        private readonly HostContainerProvider $host,
    ) {
    }

    /**
     * @param array<string, mixed> $keys
     * @param list<string>|null $locales
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        array $keys,
        ?string $locale = null,
        ?array $locales = null,
        string $domain = 'messages',
        bool $dry_run = false,
    ): array {
        if ([] === $keys) {
            return Envelope::error('empty_keys', 'Argument "keys" must be a non-empty nested array of translation keys.');
        }

        if (!class_exists(Yaml::class)) {
            return Envelope::error('yaml_unavailable', 'symfony/yaml component is required.');
        }

        $targetLocales = $this->resolveLocales($locale, $locales);

        foreach ($targetLocales as $candidate) {
            if (!preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $candidate)) {
                return Envelope::error('invalid_locale', sprintf('Locale "%s" is not a valid ICU code (e.g. "en" or "en_US").', $candidate));
            }
        }

        $projectRoot = HostProjectDir::resolve($this->host);
        $items = [];

        foreach ($targetLocales as $loc) {
            $relative = sprintf('translations/%s.%s.yaml', $domain, $loc);
            $absolute = $projectRoot . '/' . $relative;

            $existing = [];
            if (is_file($absolute)) {
                $parsed = Yaml::parseFile($absolute);
                if (\is_array($parsed)) {
                    $existing = $parsed;
                }
            }

            $merged = $this->mergeRecursive($existing, $keys);
            $yamlBody = Yaml::dump($merged, 6, 4);

            if (!$dry_run) {
                $dir = \dirname($absolute);
                if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                    return Envelope::error('write_failed', sprintf('Could not create directory "%s".', $dir));
                }

                if (false === @file_put_contents($absolute, $yamlBody)) {
                    return Envelope::error('write_failed', sprintf('Could not write "%s".', $absolute));
                }
            }

            $items[] = [
                'kind' => 'translation_yaml',
                'locale' => $loc,
                'suggested_path' => $relative,
                'body' => $yamlBody,
            ];
        }

        $envelope = Envelope::items(
            $items,
            null,
            $dry_run
                ? sprintf('Dry-run only — %d locale file(s) NOT written.', \count($items))
                : sprintf('Wrote %d locale file(s). Run sylius_cache_clear to refresh the translator catalogue.', \count($items)),
        );

        $envelope['locales'] = $targetLocales;
        $envelope['domain'] = $domain;
        $envelope['written'] = !$dry_run;
        $envelope['cache_clear_required'] = !$dry_run;
        $envelope['next_step'] = $dry_run ? null : 'Call sylius_cache_clear so the translator catalogue picks up the new keys.';

        return $envelope;
    }

    /**
     * @param list<string>|null $locales
     *
     * @return list<string>
     */
    private function resolveLocales(?string $locale, ?array $locales): array
    {
        if (null !== $locales && [] !== $locales) {
            return array_values(array_unique(array_map('strval', $locales)));
        }

        if (null !== $locale && '' !== $locale) {
            return [$locale];
        }

        return [$this->detectLocale()];
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overlay
     *
     * @return array<array-key, mixed>
     */
    private function mergeRecursive(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (\is_array($value) && isset($base[$key]) && \is_array($base[$key])) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function detectLocale(): string
    {
        $container = $this->host->getContainer();
        if ($container instanceof \Symfony\Component\DependencyInjection\Container) {
            foreach (['kernel.default_locale', 'sylius_locale.default'] as $parameter) {
                if ($container->hasParameter($parameter)) {
                    $value = $container->getParameter($parameter);
                    if (\is_string($value) && '' !== $value) {
                        return $value;
                    }
                }
            }
        }

        return 'en';
    }
}
