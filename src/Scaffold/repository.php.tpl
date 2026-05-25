<?php

declare(strict_types=1);

namespace {{ namespace }}\Repository;

use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;

class {{ model }}Repository extends EntityRepository implements {{ model }}RepositoryInterface
{
}
