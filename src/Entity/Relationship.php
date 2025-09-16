<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\RelationshipRepository;
use Ramsey\Uuid\Doctrine\UuidGenerator;

#[ORM\Table(name: "relationship")]
#[ORM\Entity(repositoryClass: RelationshipRepository::class)]
class Relationship
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: "CUSTOM")]
    #[ORM\Column(type: "uuid")]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    private $id;

    #[ORM\Column(type: "integer")]
    private $relationship_rule_id;

    #[ORM\Column(type: "uuid")]
    private $domain_resource_id;

    #[ORM\Column(type: "integer")]
    private $predicate_id;

    #[ORM\Column(type: "uuid")]
    private $range_resource_id;

    #[ORM\Column(type: "integer", options: ["default" => 1])]
    private $sequence_number;

    #[ORM\Column(type: "boolean", options: ["default" => true])]
    private $is_active;

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getRelationshipRuleId(): ?int
    {
        return $this->relationship_rule_id;
    }

    public function setRelationshipRuleId(int $relationship_rule_id): self
    {
        $this->relationship_rule_id = $relationship_rule_id;
        return $this;
    }

    public function getDomainResourceId(): ?string
    {
        return $this->domain_resource_id;
    }

    public function setDomainResourceId(string $domain_resource_id): self
    {
        $this->domain_resource_id = $domain_resource_id;
        return $this;
    }

    public function getPredicateId(): ?int
    {
        return $this->predicate_id;
    }

    public function setPredicateId(int $predicate_id): self
    {
        $this->predicate_id = $predicate_id;
        return $this;
    }

    public function getRangeResourceId(): ?string
    {
        return $this->range_resource_id;
    }

    public function setRangeResourceId(string $range_resource_id): self
    {
        $this->range_resource_id = $range_resource_id;
        return $this;
    }

    public function getSequenceNumber(): ?int
    {
        return $this->sequence_number;
    }

    public function setSequenceNumber(int $sequence_number): self
    {
        $this->sequence_number = $sequence_number;
        return $this;
    }

    public function getIsActive(): ?bool
    {
        return $this->is_active;
    }

    public function setIsActive(bool $is_active): self
    {
        $this->is_active = $is_active;
        return $this;
    }
}
