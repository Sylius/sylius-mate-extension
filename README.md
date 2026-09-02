<p align="center">
    <a href="https://sylius.com" target="_blank">
        <picture>
          <source media="(prefers-color-scheme: dark)" srcset="https://media.sylius.com/sylius-logo-800-dark.png">
          <source media="(prefers-color-scheme: light)" srcset="https://media.sylius.com/sylius-logo-800.png">
          <img alt="Sylius Logo." src="https://media.sylius.com/sylius-logo-800.png">
        </picture>
    </a>
</p>

<h1 align="center">Sylius Mate Extension</h1>

<p align="center">Dev-only Symfony AI Mate extension exposing the Sylius runtime domain to AI coding agents via the Mate CLI.</p>

## About

The Sylius Mate Extension is an [AI Mate](https://symfony.com/doc/current/ai/components/mate.html) extension for [**Sylius**](https://sylius.com).
It exposes the Sylius runtime domain — resources, hooks, grids, routes, services, Twig helpers, mailer configuration — to AI coding agents as [Mate CLI tools](https://symfony.com/doc/current/ai/components/mate.html), invoked with `vendor/bin/mate tools:call <tool>`.

Every tool runs against the host project's booted Symfony kernel, so listings, inspections, and audits reflect the real container, real grid configuration, real route map, real installed plugins. Agents generate Sylius code against the actual shape of your application instead of hallucinating Twig helpers, routes, or resource patterns.

The extension is **dev-only**. Install it as a `require-dev` dependency; never ship it to production.

## Documentation

See [INSTRUCTIONS.md](INSTRUCTIONS.md) for the full description of every tool, when to call it, and the hard rules agents must follow (Twig helper verification, route lookup, resource scaffolding, mutating-tool confirmation).

General Sylius documentation is available at [docs.sylius.com](https://docs.sylius.com).

## Installation

Add the extension as a dev dependency of your Sylius project:

```bash
$ composer require --dev sylius/sylius-mate-extension
```

Initialize the Mate environment and discover the extension:

```bash
$ vendor/bin/mate init
$ vendor/bin/mate discover
```

Extension discovery runs automatically after Composer install and update. Run `vendor/bin/mate discover` manually to refresh discovery state and regenerate agent instruction artifacts.

## Available Tools

Tools are grouped by domain. Full reference in [INSTRUCTIONS.md](INSTRUCTIONS.md).

- **Resource** — `sylius_domain_list_resources`, `sylius_domain_resource_template`, `sylius_resource_inspect`
- **Grid** — `sylius_domain_list_grids`, `sylius_grid_actions_audit`
- **Hook** — `sylius_hooks_list`, `sylius_hooks_find_for_template`, `sylius_hooks_list_hookables`, `sylius_hooks_resolve_for_visibility`
- **Twig** — `sylius_twig_list_functions`, `sylius_twig_function_verify`, `sylius_test_render_template`
- **Route** — `sylius_routes_show`, `sylius_route_inspect`
- **Mailer / Email** — `sylius_email_capture_status`, `sylius_mailer_verify_template`, `sylius_email_template_skeleton`
- **Service configuration** — `sylius_services_yaml_profile`, `sylius_services_yaml_audit`, `sylius_services_yaml_patch_exclude`
- **Project** — `sylius_project_profile`, `sylius_installed_plugins`, `sylius_service_decorators`, `sylius_plugin_compatibility`, `sylius_project_audit`
- **Translation** — `sylius_translation_create`
- **Admin / Playwright** — `sylius_admin_restock_via_http`, `sylius_playwright_recipe`
- **Cache** — `sylius_cache_clear`

Generic Symfony introspection is not duplicated here. The `sylius/sylius-ai-dev-tools` pack installs this extension together with [`symfony/ai-symfony-mate-extension`](https://github.com/symfony/ai-symfony-mate-extension), whose `symfony-services` / `symfony-service-detail` tools and `symfony-profiler-*` tools and resources cover container lookups and profiler access; the `sylius-dev` skill delegates to them instead of shelling out to `bin/console debug:container`.

## Development

```bash
$ composer install
$ vendor/bin/phpunit
```

Useful Mate commands while developing:

```bash
$ vendor/bin/mate debug:capabilities
$ vendor/bin/mate debug:extensions
$ vendor/bin/mate tools:list
$ vendor/bin/mate tools:call sylius_project_profile
```

## Contributing

Would like to help us build a more capable Sylius developer experience? Start from reading our [Contribution Guide](https://docs.sylius.com/en/latest/contributing/).

## Bug Tracking

If you want to report a bug or suggest an idea, please use [GitHub issues](https://github.com/Sylius/sylius-mate-extension/issues).

## Community Support

Get Sylius support on [Slack](https://sylius.com/slack), [Forum](https://forum.sylius.com/) or [Stack Overflow](https://stackoverflow.com/questions/tagged/sylius).

## MIT License

The Sylius Mate Extension is released under the terms of the [MIT License](LICENSE).
