<?php
namespace app\service;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use think\facade\Response;

class ExportService
{
    public function exportExcel(array $data, array $columns, string $filename = 'export.xlsx')
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // header
        $colIndex = 1;
        foreach ($columns as $c) {
            $sheet->setCellValueByColumnAndRow($colIndex++, 1, $c);
        }

        $rowIndex = 2;
        foreach ($data as $row) {
            $colIndex = 1;
            foreach ($row as $cell) {
                $sheet->setCellValueByColumnAndRow($colIndex++, $rowIndex, $cell);
            }
            $rowIndex++;
        }

        $writer = new Xlsx($spreadsheet);
        $tempFile = sys_get_temp_dir().'/'.uniqid('export_').'.xlsx';
        $writer->save($tempFile);

        return Response::create($tempFile, 'file')->header([
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.basename($filename).'"'
        ]);
    }
}
