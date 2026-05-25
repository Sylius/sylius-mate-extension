<?php

declare(strict_types=1);

namespace {{ namespace }}\Form\Type;

use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\FormBuilderInterface;

final class {{ model }}Type extends AbstractResourceType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);
    }

    public function getBlockPrefix(): string
    {
        return '{{ block_prefix }}';
    }
}
