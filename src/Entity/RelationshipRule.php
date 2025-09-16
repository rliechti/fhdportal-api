<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\RelationshipRuleRepository;

#[ORM\Table(name: "relationship_rule")]
#[ORM\Entity(repositoryClass: RelationshipRuleRepository::class)]
class RelationshipRule
{
    #[ORM\Id]
    #[ORM\Column(type: "integer")]
    private $id;

    #[ORM\Column(type: "integer")]
    private $domain_type_id;

    #[ORM\Column(type: "integer")]
    private $predicate_id;

    #[ORM\Column(type: "integer")]
    private $range_type_id;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getDomainTypeId(): ?int
    {
        return $this->domain_type_id;
    }

    public function setDomainTypeId(int $domain_type_id): self
    {
        $this->domain_type_id = $domain_type_id;
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

    public function getRangeTypeId(): ?int
    {
        return $this->range_type_id;
    }

    public function setRangeTypeId(int $range_type_id): self
    {
        $this->range_type_id = $range_type_id;
        return $this;
    }
}
