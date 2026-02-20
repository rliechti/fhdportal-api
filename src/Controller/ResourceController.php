<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use App\Service\Dac\DacRequestService;
use App\Service\JsonSchema\Validator;
use App\Service\Resource\ResourceEditService;
use App\Service\Resource\ResourceExportService;
use App\Service\Resource\ResourceReadService;
use App\Service\Resource\ResourceRelationService;
use App\Service\Resource\ResourceTemplateService;
use App\Service\Dac\DatasetRequestService;
use MeekroDB;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;

abstract class ResourceController extends AbstractController
{
    protected ResourceReadService $resourceRead;
    protected ResourceEditService $resourceEdit;
    protected ResourceExportService $resourceExport;
    protected ResourceTemplateService $resourceTemplate;
    protected ResourceRelationService $resourceRelation;
    protected SerializerInterface $serializer;
    protected MeekroDB $db;
    protected DacRequestService $dac;
    protected DatasetRequestService $datasetRequest;

    public function __construct(
        ResourceReadService $resourceRead,
        ResourceEditService $resourceEdit,
        ResourceExportService $resourceExport,
        ResourceTemplateService $resourceTemplate,
        ResourceRelationService $resourceRelation,
        SerializerInterface $serializer,
        MeekroDB $db,
        DacRequestService $dac,
        DatasetRequestService $datasetRequest
    ) {
        $this->resourceRead = $resourceRead;
        $this->resourceEdit = $resourceEdit;
        $this->resourceExport = $resourceExport;
        $this->resourceTemplate = $resourceTemplate;
        $this->resourceRelation = $resourceRelation;
        $this->serializer = $serializer;
        $this->db = $db;
        $this->dac = $dac;
        $this->datasetRequest = $datasetRequest;
    }

    // Abstract method to define resource type in child controllers (e.g. 'Sample', 'Run')
    abstract protected function getResourceType(): string;

    // Abstract method for route prefix (e.g. 'samples', 'runs')
    abstract protected function getRoutePrefix(): string;

    public function listResources(Keycloak $auth, ?string $studyId = null): JsonResponse
    {
        $resources = $this->resourceRead->listResources($auth, $this->getResourceType(), $studyId, 'read');
        $content = json_encode($resources);
        return new JsonResponse($content, 200, [], true);
    }

    public function getResources(Keycloak $auth): JsonResponse
    {
        $resources = $this->resourceRead->listResources($auth, $this->getResourceType(), null, 'read');
        $content = json_encode($resources);
        return new JsonResponse($content, 200, [], true);
    }

    public function postResource(Request $request, Keycloak $auth, string $study_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $content = json_decode($request->getContent(), true);
        $resourceData = $content['properties'] ?? null;
        $resourceType = $content[strtolower($this->getResourceType()) . '_type'] ?? null;

        try {
            $result = $this->resourceEdit->editResource($resourceData, $resourceType, $study_id, $auth, $projectDir);
            if ($result['success']) {
                $resources = $this->resourceRead->listResources($auth, $resourceType, $study_id, 'read', null, $result['resources'][0]['public_id']);
                $resources[0]['action_type_id'] = $result['resources'][0]['action_type_id'];
                $content = json_encode($resources[0]);
                return new JsonResponse($content, 201, [], true);
            } else {
                $content = json_encode($result);
                return new JsonResponse($content, 400, [], true);
            }
        } catch (\Exception $e) {
            return new JsonResponse(['message' => $e->getMessage()], 500);
        }
    }

    public function putResource(Request $request, Keycloak $auth, string $study_id, string $resource_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        if (!$this->resourceRelation->checkRelationship($resource_id, $study_id)) {
            return new JsonResponse(['message' => "Error: this {$this->getResourceType()} is not part of this study"], 400);
        }

        $projectDir = $this->getParameter('kernel.project_dir');
        $content = json_decode($request->getContent(), true);
        $resourceData = $content['properties'] ?? null;
        $resourceType = $content[strtolower($this->getResourceType()) . '_type'] ?? null;

        try {
            $result = $this->resourceEdit->editResource($resourceData, $resourceType, $study_id, $auth, $projectDir);
            if ($result['success']) {
                $resources = $this->resourceRead->listResources($auth, $resourceType, $study_id, 'read', null, $resource_id);
                $resources[0]['action_type_id'] = $result['resources'][0]['action_type_id'];
                $content = json_encode($resources[0]);
                return new JsonResponse($content, 200, [], true);
            } else {
                $content = json_encode($result);
                return new JsonResponse($content, 400, [], true);
            }
        } catch (\Exception $e) {
            return new JsonResponse(['message' => $e->getMessage()], 500);
        }
    }

    public function deleteResource(Keycloak $auth, string $study_id, string $resource_id): JsonResponse
    {
        if (!$this->resourceRelation->checkRelationship($resource_id, $study_id)) {
            return new JsonResponse(['message' => "Error: this {$this->getResourceType()} is not part of this study"], 400);
        }

        $deletedId = $this->resourceEdit->setResourceStatus($auth, $resource_id, 'DEL');
        $content = json_encode($deletedId);
        return new JsonResponse($content, 200, [], true);
    }

    public function uploadResources(Request $request, Keycloak $auth, string $study_id): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], 401);
        }

        $content = $request->request->all();
        $projectDir = $this->getParameter('kernel.project_dir');

        $uploadResponse = $this->resourceEdit->uploadResources($auth, $study_id, $request, $projectDir, $content);
        return new JsonResponse($uploadResponse, 200, [], true);
    }
}
