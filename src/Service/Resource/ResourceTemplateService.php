<?php

namespace App\Service\Resource;

use App\Service\Auth\Keycloak;
use Exception;
use MeekroDB;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Service for managing resource templates and downloads.
 */
final class ResourceTemplateService
{
    private MeekroDB $db;

    public function __construct(MeekroDB $db)
    {
        $this->db = $db;
    }

    /**
     * Generates a resource template file (Excel or CSV) based on resource type schema and returns the file path.
     *
     * Uses the x-resource schema structure to determine template columns and constraints.
     *
     * @param Keycloak $auth The authentication service to verify permissions.
     * @param string $resource_type The resource type for which to generate a template.
     * @param string $project_dir Root project directory path for saving the file.
     * @param string $format File format - either 'xlsx' or 'csv'.
     *
     * @return string The filepath to the generated template file.
     *
     * @throws Exception if unauthorized, schema unknown, or x-resource schema not found.
     */
    public function download(Keycloak $auth, string $resource_type, string $project_dir, string $format): string
    {
        // Check if user has submitter role and is authenticated
        if ($auth->isGuest() || !$auth->hasRole('submitter')) {
            throw new Exception("Unauthorized", 401);
        }

        $filename = "template_$resource_type." . $format;

        // Setup headers for Excel format (controller should handle actual responses in a more decoupled design)
        if ($format === 'xlsx') {
            header('Content-disposition: attachment; filename="' . $filename . '"');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
        }

        // Prepare template directory and filepath
        $template_dir = $project_dir . '/data/template/';
        $this->createDirectory($template_dir, true);
        $filepath = $template_dir . "/" . $filename;

        // Retrieve JSON schema properties for resource type
        $json = $this->db->queryFirstField("SELECT properties FROM resource_type WHERE name = %s", $resource_type);
        if (!$json) {
            throw new Exception("Unknown schemas", 500);
        }
        $schemas = json_decode($json, true);

        // Use x-resource schema if available
        if (!isset($schemas['data_schema']['x-resource']['schema']['fields'])) {
            throw new Exception("x-resource schema not found for resource type: $resource_type", 500);
        }

        $tableSchema = $schemas['data_schema']['x-resource']['schema'];
        $fields = $tableSchema['fields'];
        $jsonProperties = $schemas['data_schema']['properties'];

        // Style arrays for cell formatting
        $styleArrayRequired = [
            'font' => ['bold' => true, 'color' => ['argb' => Color::COLOR_RED]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ];
        $styleArray = [
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ];
        $styleArrayBorder = [
            'font' => ['italic' => true, 'color' => ['argb' => '555555']],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN]],
        ];

        // Create spreadsheet and worksheets
        $spreadsheet = new Spreadsheet();
        $activeWorksheet = $spreadsheet->getActiveSheet();
        $activeWorksheet->setTitle(preg_replace("/([A-Z])/", " $1", $resource_type));
        $vocabulary = $spreadsheet->createSheet();
        $vocabulary->setTitle("Vocabulary");
        $vocabulary->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);
        $activeWorksheet = $spreadsheet->setActiveSheetIndexByName(preg_replace("/([A-Z])/", " $1", $resource_type));
        $activeWorksheet->getDefaultColumnDimension()->setWidth(20);
        $activeWorksheet->getDefaultRowDimension()->setRowHeight(15);

        if ($format === 'xlsx') {
            $activeWorksheet->setCellValue('B1', 'Required fields');
            $activeWorksheet->getStyle('B1')->applyFromArray($styleArrayRequired);
            $activeWorksheet->setCellValue('B2', 'extra_attributes: replace this column title with the attribute title. If unit can be mentionned, append the term between square brackets (e.g. volume [ml]). Multiple extra columns can be added');
        }

        $colidx = 0;
        $rowIdx = ($format === 'csv') ? 1 : 3;

        // Process required fields first
        foreach ($fields as $field) {
            $isRequired = isset($field['constraints']['required']) && $field['constraints']['required'];
            if (!$isRequired) {
                continue;
            }

            $fieldName = $field['name'];
            $propertyName = $field['aliasOf'] ?? $fieldName;

            // Skip public_id if present
            if ($propertyName === 'public_id') {
                continue;
            }

            $col = chr(65 + $colidx);
            $activeWorksheet->setCellValue($col . $rowIdx, $fieldName);

            if ($format === 'csv') {
                $colidx++;
                continue;
            }
            $activeWorksheet->getStyle($col . "3")->applyFromArray($styleArrayRequired);

            // Get property details from JSON schema for enum handling
            $property = $jsonProperties[$propertyName] ?? null;

            if ($property && isset($property['enum']) && is_string($property['enum'][0] ?? null)) {
                $activeWorksheet = $spreadsheet->setActiveSheetIndexByName('Vocabulary');
                foreach ($property['enum'] as $v => $enumVal) {
                    $activeWorksheet->setCellValue($col . ($v + 1), $enumVal);
                }
                $activeWorksheet = $spreadsheet->setActiveSheetIndexByName(preg_replace("/([A-Z])/", " $1", $resource_type));

                $dv = new DataValidation();
                $dv->setType(DataValidation::TYPE_NONE);
                $activeWorksheet->setDataValidation($col . "1:" . $col . "4", $dv);

                $dv = new DataValidation();
                $dv->setType(DataValidation::TYPE_LIST);
                $dv->setErrorStyle(DataValidation::STYLE_INFORMATION);
                $dv->setAllowBlank(false);
                $dv->setShowInputMessage(true);
                $dv->setShowErrorMessage(true);
                $dv->setShowDropDown(true);
                $dv->setErrorTitle('Input error');
                $dv->setError('Value is not in list.');
                $dv->setPromptTitle('Pick from list');
                $dv->setPrompt('Please pick a value from the drop-down list.');
                $dv->setFormula1("'Vocabulary'!\${$col}\$1:\${$col}\$" . count($property['enum']));
                $activeWorksheet->setDataValidation($col . ':' . $col, $dv);
            } elseif ($property && isset($property['type'])) {
                $type = is_array($property['type']) ? $property['type'][0] : $property['type'];
                // Check if the field specifies type override
                if (isset($field['type'])) {
                    $fieldType = $field['type'];
                    $desc = ($fieldType === 'list' || $type === 'array') ? "values separated by ;" : $type;
                } else {
                    $desc = ($type === 'array') ? "values separated by ;" : $type;
                }
                $activeWorksheet->setCellValue($col . "4", $desc);
                $activeWorksheet->getStyle($col . "4")->applyFromArray($styleArrayBorder);
            }
            $colidx++;
        }

        // Process optional fields
        foreach ($fields as $field) {
            $isRequired = isset($field['constraints']['required']) && $field['constraints']['required'];
            if ($isRequired) {
                continue;
            }

            $fieldName = $field['name'];
            $propertyName = $field['aliasOf'] ?? $fieldName;

            // Skip public_id if present
            if ($propertyName === 'public_id') {
                continue;
            }

            $col = chr(65 + $colidx);
            $activeWorksheet->setCellValue($col . $rowIdx, $fieldName);

            if ($format === 'csv') {
                $colidx++;
                continue;
            }
            $activeWorksheet->getStyle($col . "3")->applyFromArray($styleArray);

            $desc = null;
            $property = $jsonProperties[$propertyName] ?? null;

            if ($fieldName === 'extra_attributes') {
                $desc = "rename title";
            } elseif ($property && isset($property['enum']) && is_string($property['enum'][0] ?? null)) {
                $desc = 'from list';
                $dv = new DataValidation();
                $dv->setType(DataValidation::TYPE_NONE);
                $activeWorksheet->setDataValidation($col . "1:" . $col . "4", $dv);

                $dv = new DataValidation();
                $dv->setType(DataValidation::TYPE_LIST);
                $dv->setErrorStyle(DataValidation::STYLE_INFORMATION);
                $dv->setAllowBlank(false);
                $dv->setShowInputMessage(true);
                $dv->setShowErrorMessage(true);
                $dv->setShowDropDown(true);
                $dv->setErrorTitle('Input error');
                $dv->setError('Value is not in list.');
                $dv->setPromptTitle('Pick from list');
                $dv->setPrompt('Please pick a value from the drop-down list.');
                $dv->setFormula1('"' . implode(",", $property['enum']) . '"');
                $activeWorksheet->setDataValidation($col . ':' . $col, $dv);
            } elseif ($property && isset($property['type'])) {
                $type = is_array($property['type']) ? $property['type'][0] : $property['type'];
                // Check if the field specifies type override
                if (isset($field['type'])) {
                    $fieldType = $field['type'];
                    $desc = ($fieldType === 'list' || $type === 'array') ? "values separated by ;" : $type;
                } else {
                    $desc = ($type === 'array') ? "values separated by ;" : $type;
                }
            }

            if ($desc !== null) {
                $activeWorksheet->setCellValue($col . "4", $desc);
                $activeWorksheet->getStyle($col . "4")->applyFromArray($styleArrayBorder);
            }

            $colidx++;
        }

        // Add extra_attributes column if it exists in JSON schema but not in x-resource fields
        $extraAttributesInFields = false;
        foreach ($fields as $field) {
            if ($field['name'] === 'extra_attributes' || ($field['aliasOf'] ?? null) === 'extra_attributes') {
                $extraAttributesInFields = true;
                break;
            }
        }

        if (!$extraAttributesInFields && isset($jsonProperties['extra_attributes'])) {
            $col = chr(65 + $colidx);
            $activeWorksheet->setCellValue($col . $rowIdx, 'extra_attributes');

            if ($format !== 'csv') {
                $activeWorksheet->getStyle($col . "3")->applyFromArray($styleArray);
                $activeWorksheet->setCellValue($col . "4", "rename title");
                $activeWorksheet->getStyle($col . "4")->applyFromArray($styleArrayBorder);
            }
            $colidx++;
        }

        // Create appropriate writer and save file
        if ($format === 'xlsx') {
            $writer = new Xlsx($spreadsheet);
        } elseif ($format === 'csv') {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Csv($spreadsheet);
            $writer->setDelimiter("\t");
            $writer->setEnclosure('"');
            $writer->setLineEnding("\n");
            $writer->setSheetIndex(0);
        }

        $writer->save($filepath);

        return $filepath;
    }

    private function createDirectory(string $destination, bool $writable = false): ?JsonResponse
    {
        if (!file_exists($destination)) {
            mkdir($destination, 0770, true);
            if (!file_exists($destination)) {
                return new JsonResponse($destination . " does not exist", 400);
            }
        }
        if ($writable && !is_writable($destination)) {
            chmod($destination, 0777);
            if (!is_writable($destination)) {
                return new JsonResponse($destination . " does not exist", 400);
            }
        }
        return null;
    }
}
