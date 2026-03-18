<?php

namespace App\Service;

use Exception;
use Ramsey\Uuid\Uuid;
use MeekroDB;
use App\Service\JsonSchema\Validator;

class PublicationService
{
    private MeekroDB $db;
    private Validator $validator;
    private const PUBMED_EFETCH_URL = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi';

    public function __construct(MeekroDB $db, Validator $validator)
    {
        $this->db = $db;
        $this->validator = $validator;
    }


    public function fetchPubmeds(array|string $pmids): array
    {
        if (is_array($pmids)) {
            $pmids = implode(',', $pmids);
        }

        $pubmeds = [];
        $url = sprintf('%s?db=pubmed&id=%s&retmode=xml', self::PUBMED_EFETCH_URL, $pmids);
        $response = file_get_contents($url);

        if (!$response) {
            return $pubmeds;
        }

        $xml = simplexml_load_string($response);
        if (!$xml) {
            return $pubmeds;
        }

        foreach ($xml->PubmedArticle ?? [] as $pubmedArticle) {
            $article = $pubmedArticle->MedlineCitation->Article;

            $title = (string) $article->ArticleTitle;
            $pmid = (string) $pubmedArticle->MedlineCitation->PMID;

            $doi = '';
            foreach ($article->ELocationID ?? [] as $eloc) {
                if ((string) $eloc['EIdType'] === 'doi') {
                    $doi = (string) $eloc;
                    break;
                }
            }

            $pubDate = $article->Journal->JournalIssue->PubDate;
            $year = (string) ($pubDate->Year ?? '');
            $month = (string) ($pubDate->Month ?? '01');  // fallback to January
            $day = (string) ($pubDate->Day ?? '01');      // fallback to first day
            $date = $this->formatDate($year, $month, $day);

            $journal = (string) $article->Journal->Title;

            if ($title !== '' && $title !== '0') {
                $pubmeds[$pmid] = [
                    'id' => $pmid,
                    'doi' => $doi,
                    'title' => $title,
                    'journal' => $journal,
                    'date' => $date,
                ];
            }
        }

        return $pubmeds;
    }

    private function formatDate(string $year, string $month, string $day): string
    {
        // Normalize month and day to two digits if numeric
        $month = str_pad($month, 2, '0', STR_PAD_LEFT);
        $day = str_pad($day, 2, '0', STR_PAD_LEFT);

        $dateStr = "$year-$month-$day";
        $timestamp = strtotime($dateStr);

        return $timestamp ? date('Y-m-d', $timestamp) : '';
    }



    /**
     * Process and validate publications, inserting new records as needed.
     *
     * @param array $publications Keyed by pmid, each an associative array of publication data
     *
     * @throws Exception If schemas are unknown or validation errors occur
     */
    public function processPublications(array $publications): void
    {
        if (empty($publications)) {
            return;
        }

        // Fetch and decode schema once
        $jsonSchemas = $this->db->queryFirstField("SELECT properties FROM resource_type WHERE name = 'Publication'");
        if (!$jsonSchemas) {
            throw new Exception('Unknown schemas', 500);
        }

        $schemas = json_decode($jsonSchemas);
        if (!isset($schemas->data_schema)) {
            throw new Exception('Unknown data_schema in schemas', 500);
        }

        $resourceTypeId = $this->db->queryFirstField("SELECT id FROM resource_type WHERE name = 'Publication'");

        foreach ($publications as $pmid => $publication) {
            $publication['id'] = intval($publication['id']);

            $validationErrors = $this->validator->validate((object) $publication, $schemas->data_schema);

            if (!empty($validationErrors)) {
                $message = implode('. ', array_map(fn ($v) => $v['message'], $validationErrors));
                throw new Exception($message, 400);
            }

            $pubResource = [
                'id' => null,
                'properties' => json_encode($publication),
                'resource_type_id' => $resourceTypeId,
                'status_type_id' => 'PUB',
            ];

            $existingId = $this->db->queryFirstField(
                "SELECT id FROM resource WHERE resource.properties ->> 'id' = %s",
                $pmid
            );

            if ($existingId) {
                $pubResource['id'] = $existingId;
            } else {
                $pubResource['id'] = Uuid::uuid4()->toString();
                $this->db->insert('resource', $pubResource);
            }
        }
    }
}
