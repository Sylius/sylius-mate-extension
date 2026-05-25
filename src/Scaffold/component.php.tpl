<?php

declare(strict_types=1);

namespace {{ namespace }}\TwigComponent\{{ component_section }};

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('{{ component_name }}', template: 'components/{{ component_name }}.html.twig')]
final class {{ model }}Component
{
}
