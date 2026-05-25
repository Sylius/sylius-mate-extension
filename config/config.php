<?php

declare(strict_types=1);

use Sylius\MateExtension\Hook\HookablesReader;
use Sylius\MateExtension\Kernel\HostKernelProvider;
use Sylius\MateExtension\Tool\Admin\RestockViaHttp;
use Sylius\MateExtension\Tool\Cache\CacheClear;
use Sylius\MateExtension\Tool\Email\EmailTemplateSkeleton;
use Sylius\MateExtension\Tool\Grid\ActionsAudit;
use Sylius\MateExtension\Tool\Grid\ListGrids;
use Sylius\MateExtension\Tool\Hook\FindHookForTemplate;
use Sylius\MateExtension\Tool\Hook\ListHookables;
use Sylius\MateExtension\Tool\Hook\ListHooks;
use Sylius\MateExtension\Tool\Hook\ResolveForVisibility;
use Sylius\MateExtension\Tool\Mailer\CaptureStatus as MailerCaptureStatus;
use Sylius\MateExtension\Tool\Mailer\VerifyTemplate as VerifyMailerTemplate;
use Sylius\MateExtension\Tool\Playwright\PlaywrightRecipe;
use Sylius\MateExtension\Tool\Project\InstalledPlugins;
use Sylius\MateExtension\Tool\Project\ProjectAudit;
use Sylius\MateExtension\Tool\Project\ProjectProfile;
use Sylius\MateExtension\Tool\Resource\InspectResource;
use Sylius\MateExtension\Tool\Resource\ListResources;
use Sylius\MateExtension\Tool\Resource\ResourceTemplate;
use Sylius\MateExtension\Tool\Route\InspectRoute;
use Sylius\MateExtension\Tool\Route\ShowRoute;
use Sylius\MateExtension\Tool\Service\ServicesYamlAudit;
use Sylius\MateExtension\Tool\Service\ServicesYamlPatchExclude;
use Sylius\MateExtension\Tool\Service\ServicesYamlProfile;
use Sylius\MateExtension\Tool\Translation\TranslationCreate;
use Sylius\MateExtension\Tool\Twig\ListFunctions;
use Sylius\MateExtension\Tool\Twig\RenderTemplate;
use Sylius\MateExtension\Tool\Twig\VerifyFunction;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set(HostKernelProvider::class)->public();
    $services->set(HookablesReader::class)
        ->args([service(HostKernelProvider::class)])
        ->public()
    ;

    $tools = [
        ListResources::class => [service(HostKernelProvider::class)],
        ResourceTemplate::class => [\dirname(__DIR__) . '/src/Scaffold'],
        InspectResource::class => [service(HostKernelProvider::class)],
        VerifyMailerTemplate::class => [service(HostKernelProvider::class)],
        ListGrids::class => [service(HostKernelProvider::class)],
        ActionsAudit::class => [service(HostKernelProvider::class)],
        ListHooks::class => [service(HookablesReader::class)],
        FindHookForTemplate::class => [service(HookablesReader::class)],
        ListHookables::class => [service(HookablesReader::class)],
        ResolveForVisibility::class => [service(HookablesReader::class), service(HostKernelProvider::class)],
        ListFunctions::class => [service(HostKernelProvider::class)],
        VerifyFunction::class => [service(HostKernelProvider::class)],
        RenderTemplate::class => [service(HostKernelProvider::class)],
        ShowRoute::class => [service(HostKernelProvider::class)],
        InspectRoute::class => [service(HostKernelProvider::class)],
        CacheClear::class => [service(HostKernelProvider::class)],
        MailerCaptureStatus::class => [service(HostKernelProvider::class)],
        RestockViaHttp::class => [service(HostKernelProvider::class)],
        ServicesYamlProfile::class => [service(HostKernelProvider::class)],
        ServicesYamlAudit::class => [service(HostKernelProvider::class)],
        ServicesYamlPatchExclude::class => [service(HostKernelProvider::class)],
        ProjectProfile::class => [service(HostKernelProvider::class)],
        InstalledPlugins::class => [service(HostKernelProvider::class)],
        ProjectAudit::class => [service(HostKernelProvider::class)],
        EmailTemplateSkeleton::class => [\dirname(__DIR__) . '/src/Scaffold'],
        TranslationCreate::class => [service(HostKernelProvider::class)],
        PlaywrightRecipe::class => [\dirname(__DIR__) . '/src/Scaffold'],
    ];

    foreach ($tools as $class => $args) {
        $services->set($class)
            ->args($args)
            ->public()
        ;
    }
};

