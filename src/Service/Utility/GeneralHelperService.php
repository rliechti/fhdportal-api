<?php

namespace App\Service\Utility;

use Symfony\Component\HttpFoundation\JsonResponse;

class GeneralHelperService
{
    public function createDirectory(string $path, bool $recursive = true): string|JsonResponse|null
    {
        if (!file_exists($path)) {
            mkdir($path, 0770, true);
            if (!file_exists($path)) {
                return new JsonResponse($path . " does not exist", 400);
            }
        }
        if (!is_writable($path)) {
            chmod($path, 0770);
            if (!is_writable($path)) {
                return new JsonResponse($path . " does not exist", 400);
            }
        }
        return $path;
    }

    public function checkUuid(string $uuid): bool
    {
        return (is_string($uuid) && preg_match("/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i", $uuid));
    }
}