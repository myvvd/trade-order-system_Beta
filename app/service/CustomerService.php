<?php

namespace app\service;

use app\model\Customer as CustomerModel;
use think\facade\Db;

/**
 * 采购方服务层（导出逻辑）
 */
class CustomerService
{
    /**
     * 导出 CSV（兼容 Excel）
     * 接受过滤参数数组，返回 CSV 字符串（含标题行）
     * @param array $params
     * @return string
     */
    public static function exportCsv(array $params = []): string
    {
        $query = CustomerModel::where('delete_time', null);

        if (!empty($params['keyword'])) {
            $kw = $params['keyword'];
            $query->whereLike('company_name|contact_name', '%' . $kw . '%');
        }

        if (!empty($params['country'])) {
            $query->where('country', $params['country']);
        }

        if (isset($params['level']) && $params['level'] !== '') {
            $query->where('level', $params['level']);
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }

        $rows = $query->order('id', 'desc')->select()->toArray();

        $headers = [
            'ID', '公司名称', '国家', '联系人', '电话', '邮箱', '地址', '采购方等级', '状态', '创建时间'
        ];

        $lines = [];
        $lines[] = implode(',', array_map(function ($h) {
            return '"' . str_replace('"', '""', $h) . '"';
        }, $headers));

        foreach ($rows as $r) {
            $line = [
                $r['id'] ?? '',
                $r['company_name'] ?? '',
                $r['country'] ?? '',
                $r['contact_name'] ?? '',
                $r['phone'] ?? '',
                $r['email'] ?? '',
                $r['address'] ?? '',
                isset($r['level']) ? ($r['level'] == 2 ? 'VIP' : '普通') : '',
                isset($r['status']) ? ($r['status'] == 1 ? '正常' : '禁用') : '',
                $r['create_time'] ?? '',
            ];

            $lines[] = implode(',', array_map(function ($v) {
                return '"' . str_replace('"', '""', (string)$v) . '"';
            }, $line));
        }

        return implode("\r\n", $lines);
    }

    /**
     * 生成 XLSX（返回 Spreadsheet 对象）或在不可用时回退为 CSV 字符串
     * @param array $params
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet|string
     */
    public static function exportXlsx(array $params = [])
    {
        $query = CustomerModel::where('delete_time', null);

        if (!empty($params['keyword'])) {
            $kw = $params['keyword'];
            $query->whereLike('company_name|contact_name', '%' . $kw . '%');
        }

        if (!empty($params['country'])) {
            $query->where('country', $params['country']);
        }

        if (isset($params['level']) && $params['level'] !== '') {
            $query->where('level', $params['level']);
        }

        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', $params['status']);
        }

        $rows = $query->order('id', 'desc')->select()->toArray();

        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            // phpspreadsheet 未安装，回退为 CSV
            return self::exportCsv($params);
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = [
            'ID', '公司名称', '国家', '联系人', '电话', '邮箱', '地址', '采购方等级', '状态', '创建时间'
        ];

        // 写标题
        $colIndex = 1;
        foreach ($headers as $h) {
            $sheet->setCellValueByColumnAndRow($colIndex, 1, $h);
            $colIndex++;
        }

        $rowNum = 2;
        foreach ($rows as $r) {
            $colIndex = 1;
            $sheet->setCellValueByColumnAndRow($colIndex++, $rowNum, $r['id'] ?? '');
            $sheet->setCellValueByColumnAndRow($colIndex++, $rowNum, $r['company_name'] ?? '');
            $sheet->setCellValueByColumnAndRow($colIndex++, $rowNum, $r['country'] ?? '');
            $sheet->setCellValueByColumnAndRow($colIndex++, $rowNum, $r['contact_name'] ?? '');
            $sheet->setCellValueByColumnAndRow($colIndex++, $rowNum, $r['phone'] ?? '');
            $sheet->setCellValueByColumnAndRow($colIndex++, $rowNum, $r['email'] ?? '');
            $sheet->setCellValueByColumnAndRow($colIndex++, $rowNum, $r['address'] ?? '');
            $sheet->setCellValueByColumnAndRow($colIndex++, $rowNum, isset($r['level']) ? ($r['level'] == 2 ? 'VIP' : '普通') : '');
            $sheet->setCellValueByColumnAndRow($colIndex++, $rowNum, isset($r['status']) ? ($r['status'] == 1 ? '正常' : '禁用') : '');
            $sheet->setCellValueByColumnAndRow($colIndex++, $rowNum, $r['create_time'] ?? '');
            $rowNum++;
        }

        return $spreadsheet;
    }
}
