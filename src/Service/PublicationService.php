<?php

namespace App\Service;

use Exception;
use Ramsey\Uuid\Uuid;
use MeekroDB;
use App\Service\JsonSchema\Validator;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PublicationService
{
    private MeekroDB $db;
    private Validator $validator;
    private const PUBMED_EFETCH_URL = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi';
    private const PMID_PATTERN = '/^\d{1,9}$/';
    private const MAX_IDS = 50;

    public function __construct(
        MeekroDB $db,
        Validator $validator,
        private HttpClientInterface $httpClient,
        private CacheInterface $cache
    ) {
        $this->db = $db;
        $this->validator = $validator;
    }

    /**
     * PMIDs are validated against a strict pattern before ever reaching the outbound
     * request (no query-parameter injection into the eutils URL), the batch is capped,
     * and results are cached since PubMed records are effectively immutable - a repeated
     * request for the same PMID never has to leave this process again (security audit M-10).
     */
    public function fetchPubmeds(array|string $pmids): array
    {
        $ids = is_array($pmids) ? $pmids : explode(',', $pmids);
        $ids = array_values(array_unique(array_filter(
            array_map('trim', $ids),
            fn ($id) => (bool) preg_match(self::PMID_PATTERN, (string) $id)
        )));

        if (!$ids) {
            return [];
        }
        if (count($ids) > self::MAX_IDS) {
            throw new Exception('Too many PubMed identifiers requested (max ' . self::MAX_IDS . ')', 400);
        }

        sort($ids);
        $cacheKey = 'pubmed_batch_' . md5(implode(',', $ids));

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($ids) {
            $item->expiresAfter(86400);
            return $this->doFetchPubmeds($ids);
        });
    }

    private function doFetchPubmeds(array $ids): array
    {
        $pubmeds = [];

        try {
            $response = $this->httpClient->request('GET', self::PUBMED_EFETCH_URL, [
                'query' => ['db' => 'pubmed', 'id' => implode(',', $ids), 'retmode' => 'xml'],
                'timeout' => 5,
                'max_duration' => 10,
            ]);
            if ($response->getStatusCode() !== 200) {
                return $pubmeds;
            }
            $content = $response->getContent();
        } catch (\Throwable $e) {
            return $pubmeds;
        }

        $xml = simplexml_load_string($content, null, LIBXML_NONET | LIBXML_NOENT);
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
