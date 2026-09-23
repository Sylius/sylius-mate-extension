<?php

declare(strict_types=1);

namespace Sylius\MateExtension\Tests\TestApplication\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Resource\Model\ResourceInterface;

/**
 * Project-owned resource (mate_test.book) registered through sylius_resource,
 * asserted by the resource tools' integration tests.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mate_test_book')]
class Book implements ResourceInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
}
