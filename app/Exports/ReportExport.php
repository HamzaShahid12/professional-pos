<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Excel version of a report. Numbers are written as real numbers so they can be summed; text is always written as
 * text, so a product named "=cmd|..." can never become a formula.
 */
class ReportExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithTitle
{
    private const NUMERIC = ['money', 'qty', 'int', 'percent'];

    public function __construct(private array $report) {}

    public function title(): string
    {
        return mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $this->report['title']), 0, 31);
    }

    public function headings(): array
    {
        return array_column($this->report['columns'], 'label');
    }

    public function array(): array
    {
        $rows = $this->report['rows'];
        if ($this->report['totals']) {
            $rows[] = $this->report['totals'];
        }

        return array_map(fn ($row) => array_map(function ($c) use ($row) {
            $v = $row[$c['key']] ?? null;
            if ($v === null || $v === '') {
                return null;
            }

            return in_array($c['type'], self::NUMERIC, true) && is_numeric($v) ? $v + 0 : (string) $v;
        }, $this->report['columns']), $rows);
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
