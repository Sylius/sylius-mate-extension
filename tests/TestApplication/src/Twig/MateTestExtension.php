<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\TestApplication\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class MateTestExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('mate_test_greet', $this->greet(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('mate_test_shout', $this->shout(...)),
        ];
    }

    public function greet(string $name, string $greeting = 'Hello'): string
    {
        return sprintf('%s, %s!', $greeting, $name);
    }

    public function shout(string $text): string
    {
        return strtoupper($text);
    }
}
