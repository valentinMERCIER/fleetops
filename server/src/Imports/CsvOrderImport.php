<?php

namespace Fleetbase\FleetOps\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

/**
 * Import class for CSV orders using maatwebsite/excel
 * 
 * This replaces the League\Csv dependency with the existing Excel library
 * and provides the same functionality for CSV parsing with delimiter detection
 */
class CsvOrderImport implements ToCollection, WithCustomCsvSettings
{
    protected string $delimiter;
    protected ?string $encoding;
    
    public function __construct(string $delimiter = ',', ?string $encoding = 'UTF-8')
    {
        $this->delimiter = $delimiter;
        $this->encoding = $encoding;
    }

    /**
     * @return Collection
     */
    public function collection(Collection $rows)
    {
        return $rows;
    }
    
    /**
     * Configure CSV settings for maatwebsite/excel
     * 
     * @return array
     */
    public function getCsvSettings(): array
    {
        return [
            'delimiter' => $this->delimiter,
            'enclosure' => '"',
            'escape_character' => '\\',
            'encoding' => $this->encoding ?: 'UTF-8',
        ];
    }
    
    /**
     * Get the delimiter used
     */
    public function getDelimiter(): string
    {
        return $this->delimiter;
    }
    
    /**
     * Get the encoding used
     */
    public function getEncoding(): ?string
    {
        return $this->encoding;
    }
}