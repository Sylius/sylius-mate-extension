<?php

declare(strict_types=1);

namespace {{ namespace }}\Factory;

use {{ namespace }}\Entity\{{ model }};
use {{ namespace }}\Entity\{{ model }}Interface;

final class {{ model }}Factory implements {{ model }}FactoryInterface
{
    public function createNew(): {{ model }}Interface
    {
        return new {{ model }}();
    }
}
