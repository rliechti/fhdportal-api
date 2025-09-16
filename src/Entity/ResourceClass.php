<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Doctrine\UuidGenerator;

#[ORM\Table(name: "resource")]
#[ORM\Entity(repositoryClass: "App\Repository\ResourceRepository")]
class Resource
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: "CUSTOM")]
    #[ORM\Column(type: "uuid")]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private $id;

    #[ORM\Column(type: "json_document", options: ["jsonb" => true])]
    private $properties;

    #[ORM\ManyToOne(targetEntity: "App\Entity\ResourceType")]
    #[ORM\JoinColumn(name: "resource_type_id", referencedColumnName: "id", nullable: false, onDelete: "RESTRICT")]
    private $type;

    #[ORM\ManyToOne(targetEntity: "App\Entity\StatusType")]
    #[ORM\JoinColumn(name: "status_type_id", referencedColumnName: "id", nullable: false, onDelete: "RESTRICT")]
    private $status;

    public function getId(): ?string
    {
        return $this->id;
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

    public function getType(): ?ResourceType
    {
        return $this->type;
    }

    public function setType(ResourceType $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getStatus(): ?StatusType
    {
        return $this->status;
    }

    public function setStatus(StatusType $status): self
    {
        $this->status = $status;
        return $this;
    }
}
