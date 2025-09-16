<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\StatusTypeRepository;

#[ORM\Table(name: "status_type")]
#[ORM\Entity(repositoryClass: StatusTypeRepository::class)]
class StatusType
{
    #[ORM\Id]
    #[ORM\Column(type: "text")]
    private $id;

    #[ORM\Column(type: "text")]
    private $name;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }
}
