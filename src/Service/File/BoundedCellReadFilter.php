<?php

namespace App\Service\File;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Bounds the cells a spreadsheet reader will instantiate, regardless of the
 * dimensions declared in the file, to keep memory usage predictable for
 * attacker-supplied spreadsheets.
 */
class BoundedCellReadFilter implements IReadFilter
{
    public function __construct(
        private readonly int $maxRow,
        private readonly int $maxColumnIndex
    ) {
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        if ($row > $this->maxRow) {
            return false;
        }

        return Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumnIndex;
    }
}
