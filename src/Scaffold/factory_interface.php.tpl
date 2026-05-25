<?php

declare(strict_types=1);

namespace {{ namespace }}\Factory;

use {{ namespace }}\Entity\{{ model }}Interface;
use Sylius\Resource\Factory\FactoryInterface;

interface {{ model }}FactoryInterface extends FactoryInterface
{
    public function createNew(): {{ model }}Interface;
}
