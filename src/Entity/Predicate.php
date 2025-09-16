<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\PredicateRepository;

#[ORM\Table(name: "predicate")]
#[ORM\Entity(repositoryClass: PredicateRepository::class)]
class Predicate
{
    #[ORM\Id]
    #[ORM\Column(type: "integer")]
    private $id;

    #[ORM\Column(type: "text")]
    private $prefix;

    #[ORM\Column(type: "text")]
    private $name;

    #[ORM\Column(type: "json_document", options: ["jsonb" => true])]
    private $properties;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;
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

    public function getProperties(): ?array
    {
        return $this->properties;
    }

    public function setProperties(array $properties): self
    {
        $this->properties = $properties;
        return $this;
    }
}
