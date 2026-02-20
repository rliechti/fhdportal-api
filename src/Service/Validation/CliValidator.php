<?php

namespace App\Service\Validation;

use Exception;
use Symfony\Component\Process\Process;

/**
 * Service to handle validation using the FEGA CLI tool
 */
class CliValidator
{
    private const PHAR_FILENAME = 'fega.phar';
    private const DEFAULT_OUTPUT_FORMAT = 'json';
    private const DEFAULT_RESOURCE_TYPE = 'SubmissionBundle';

    private string $cliPath;
    private string $projectDir;
    private string $schemaDir;

    public function __construct(string $cliPath, string $projectDir, string $schemaDir)
    {
        $this->cliPath = $cliPath;
        $this->projectDir = $projectDir;
        $this->schemaDir = $schemaDir;
    }

    /**
     * Validate a file
     *
     * @param string $filePath Path to the file to validate
     * @param string $resourceType Type of the resource
     * @param string $outputFormat Output format
     * @return array Validation result
     * @throws Exception If validation fails or CLI tool is not accessible
     */
    public function validateFile(
        string $filePath,
        string $resourceType = self::DEFAULT_RESOURCE_TYPE,
        string $outputFormat = self::DEFAULT_OUTPUT_FORMAT
    ): array {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'json') {
            $jsonContent = file_get_contents($filePath);
            if ($jsonContent === false) {
                throw new Exception("Failed to read file: {$filePath}");
            }
            return $this->validateResourceData($jsonContent, $resourceType, $outputFormat);
        }

        $tempFilePath = null;
        if (empty($extension) && $resourceType === 'SubmissionBundle') {
            // Detect if it is a ZIP file by checking magic bytes
            $handle = fopen($filePath, 'rb');
            if ($handle) {
                $magicBytes = fread($handle, 4);
                fclose($handle);
                // Check for ZIP signature (PK\003\004 or PK\005\006)
                if ($magicBytes === "PK\003\004" || $magicBytes === "PK\005\006") {
                    // Create a temporary file with .zip extension
                    $tempFilePath = sys_get_temp_dir() . '/' . uniqid('fega_validation_', true) . '.zip';
                    if (!copy($filePath, $tempFilePath)) {
                        throw new Exception("Failed to create temporary file for validation");
                    }
                    $filePath = $tempFilePath;
                }
            }
        }

        $command = $this->buildValidateCommand($resourceType, $outputFormat, $filePath);
        $process = $this->createProcess($command);

        $result = $this->executeValidation($process, $outputFormat,$resourceType);

        // Clean up temporary file if created
        if ($tempFilePath && file_exists($tempFilePath)) {
            unlink($tempFilePath);
        }

        return $result;
    }

    /**
     * Validate resource data from array/object using STDIN
     *
     * @param array|object|string $data The resource data to validate
     * @param string $resourceType Type of the resource
     * @param string $outputFormat Output format
     * @return array Validation result
     * @throws Exception If validation fails
     */
    public function validateResourceData(
        $data,
        string $resourceType,
        string $outputFormat = self::DEFAULT_OUTPUT_FORMAT
    ): array {
        $jsonData = is_string($data) ? $data : json_encode($data);

        $command = $this->buildValidateCommand($resourceType, $outputFormat, '-');
        $process = $this->createProcess($command, $jsonData);

        return $this->executeValidation($process, $outputFormat,$resourceType);
    }

    /**
     * Check if the CLI tool is available
     *
     * @return bool True if CLI tool is available
     */
    public function isAvailable(): bool
    {
        try {
            $pharPath = $this->getPharPath();

            if (!file_exists($pharPath) || !is_readable($pharPath)) {
                return false;
            }

            $process = new Process(['php', $pharPath, 'list'], $this->projectDir);
            $process->run();

            return $process->isSuccessful();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Set the CLI tool path
     *
     * @param string $cliPath Path to the CLI tool directory
     */
    public function setCliPath(string $cliPath): void
    {
        $this->cliPath = $cliPath;
    }

    /**
     * Get the current CLI tool path
     *
     * @return string Current CLI tool path
     */
    public function getCliPath(): string
    {
        return $this->cliPath;
    }

    /**
     * Get the full path to the PHAR file
     *
     * @return string Full path to the PHAR file
     * @throws Exception If CLI path or PHAR file is not accessible
     */
    private function getPharPath(): string
    {
        if (!is_dir($this->cliPath)) {
            throw new Exception("CLI tool path not found: {$this->cliPath}");
        }

        $pharPath = $this->cliPath . '/' . self::PHAR_FILENAME;

        if (!file_exists($pharPath)) {
            throw new Exception("CLI PHAR file not found: {$pharPath}");
        }

        if (!is_readable($pharPath)) {
            throw new Exception("CLI PHAR file is not readable: {$pharPath}");
        }

        return $pharPath;
    }

    /**
     * Build the validation command array
     *
     * @param string $resourceType Type of the resource
     * @param string $outputFormat Output format
     * @param string $target File path or '-' for STDIN
     * @return array Command array
     * @throws Exception If PHAR file is not accessible
     */
    private function buildValidateCommand(string $resourceType, string $outputFormat, string $target): array
    {
        $pharPath = $this->getPharPath();

        return [
            'php',
            $pharPath,
            'validate',
            '--resource-type=' . $resourceType,
            '--output-format=' . $outputFormat,
            $target
        ];
    }

    /**
     * Create and configure a Process instance
     *
     * @param array $command Command array
     * @param string|null $input Optional input for STDIN
     * @return Process Configured process instance
     */
    private function createProcess(array $command, ?string $input = null): Process
    {
        $env = $_ENV;
        $env['FEGA_SCHEMA_DIR'] = $this->schemaDir;
        $process = new Process($command, $this->projectDir, $env);

        if ($input !== null) {
            $process->setInput($input);
        }

        return $process;
    }

    /**
     * Execute validation and process the result
     *
     * @param Process $process Process to execute
     * @param string $outputFormat Output format
     * @return array Validation result
     */
    private function executeValidation(Process $process, string $outputFormat, string $resourceType): array
    {
        $process->run();
        $output = trim($process->getOutput());

        $errorOutput = trim($process->getErrorOutput());
        $outputStatus = "";
        if (is_string($output)) {
            $parsedOutput = json_decode($output, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (isset($parsedOutput['status'])) {
                    $outputStatus = strtolower($parsedOutput['status']);
                }
            }
        } elseif (is_array($output) && isset($output['status'])) {
            $outputStatus = strtolower($output['status']);
        }


        if (!$process->isSuccessful() || ($outputStatus && $outputStatus !== 'success')) {
            return $this->buildErrorResponse($process, $output, $errorOutput, $outputFormat,$resourceType);
        }
        return $this->buildSuccessResponse($output, $outputFormat);
    }

    /**
     * Build error response from failed validation
     *
     * @param Process $process Failed process
     * @param string $output Standard output
     * @param string $errorOutput Error output
     * @param string $outputFormat Output format
     * @return array Error response array
     */
    private function buildErrorResponse(
        Process $process,
        string $output,
        string $errorOutput,
        string $outputFormat,
        string $resourceType
    ): array {
        $error = [
            'success' => false,
            'message' => 'Validation failed',
            'errors' => [],
            'exit_code' => $process->getExitCode(),
            'debug' => null
        ];

        // Parse JSON output if format is JSON
        $jsonParsedSuccessfully = false;

		if($resourceType === 'SubmissionBundle'){
			$json_output = json_decode($output, true);
			$json_output['success'] = false;
			return $json_output;
		} 

        if ($outputFormat === 'json' && !empty($output)) {
            $parsedOutput = json_decode($output, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $jsonParsedSuccessfully = true;
                // Extract structured error information
                if (isset($parsedOutput['status']) && $parsedOutput['status'] === 'FAIL') {
                    $error['message'] = $parsedOutput['message'] ?? 'Validation failed';

                    // Format error messages
                    if (isset($parsedOutput['errors']) && is_array($parsedOutput['errors'])) {
                        foreach ($parsedOutput['errors'] as $location => $errorMessages) {
                            // Handle different error structures
                            if (is_array($errorMessages)) {
                                // Check if it is a nested structure with line numbers
                                if (isset($errorMessages['errors']) && is_array($errorMessages['errors'])) {
                                    // Format: {"errors": {"field": ["error1", "error2"]}}
                                    foreach ($errorMessages['errors'] as $field => $fieldErrors) {
                                        foreach ((array)$fieldErrors as $fieldError) {
                                            $error['errors'][] = "Line $location: " . trim($field, '/') . " - " . $fieldError;
                                        }
                                    }
                                } elseif (isset($errorMessages['message'])) {
                                    // Format: {"message": "error text"}
                                    $error['errors'][] = "Line $location: " . $errorMessages['message'];
                                } elseif (isset($errorMessages['status'])) {
                                    // Format: {"status": "FAIL", "message": "..."}
                                    $msg = $errorMessages['message'] ?? 'Validation failed';
                                    $error['errors'][] = "Line $location: " . $msg;
                                } else {
                                    // Simple array of error messages: ["error1", "error2"]
                                    foreach ($errorMessages as $errorMsg) {
                                        if (is_string($errorMsg)) {
                                            $locationLabel = is_numeric($location) ? "Line $location" : ($location === '/' ? 'Root' : trim($location, '/'));
                                            $error['errors'][] = "$locationLabel: $errorMsg";
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                // Store full output for debugging if needed
                if (!empty($output)) {
                    $error['debug'] = $output;
                }
            } else {
                $error['errors'][] = $output;
            }
        } else {
            if (!empty($output)) {
                $error['errors'][] = $output;
            }
        }

        // Only include stderr if JSON parsing failed or non-JSON format
        if (!$jsonParsedSuccessfully && !empty($errorOutput)) {
            $error['errors'][] = $errorOutput;
        }

        if (empty($error['errors'])) {
            $error['errors'][] = 'Unknown validation error';
        }
        return $error;
    }

    /**
     * Build success response from successful validation
     *
     * @param string $output Output string
     * @param string $outputFormat Output format
     * @return array Success response array
     */
    private function buildSuccessResponse(string $output, string $outputFormat): array
    {
        $response = [
            'success' => true,
            'message' => 'Validation successful',
        ];
        
        if ($outputFormat === 'json' && !empty($output)) {
            $result = json_decode($output, true);            
            // fega cli might be running in verbose mode...don't know why            
            if (json_last_error() !== JSON_ERROR_NONE){
                $pos = strpos($output, '{');
                if ($pos !== false){
                    $output = substr($output, $pos); 
                    $result = json_decode($output, true);
                    $result = (json_last_error() === JSON_ERROR_NONE) ? $result : $output;
                }
                else $result = $output;
            }
            $response['result'] = $result;
        } else {
            $response['result'] = $output ?: 'Data is valid';
        }

        return $response;
    }
}
