<?php
namespace app\service;

use think\facade\Db;

class BackupService
{
    // 返回备份文件路径
    public function backup()
    {
        $runtime = root_path() . 'runtime';
        $dir = $runtime . '/backup';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $file = $dir . '/backup_' . date('Ymd_His') . '.sql';

        // 获取所有表
        $tables = Db::query('SHOW TABLES');
        $tableList = [];
        foreach ($tables as $t) {
            $tableList[] = array_values($t)[0];
        }

        $fp = fopen($file, 'w');
        if (!$fp) throw new \Exception('无法创建备份文件');

        fwrite($fp, "-- Backup created at " . date('Y-m-d H:i:s') . "\n\n");

        foreach ($tableList as $tbl) {
            // structure
            $create = Db::query("SHOW CREATE TABLE `{$tbl}`");
            $createSql = $create[0]['Create Table'] ?? ($create[0]['Create Table'] ?? '');
            fwrite($fp, "-- Table structure for {$tbl}\n");
            fwrite($fp, "DROP TABLE IF EXISTS `{$tbl}`;\n");
            fwrite($fp, $createSql . ";\n\n");

            // data
            $rows = Db::name($tbl)->select();
            if (!empty($rows)) {
                fwrite($fp, "-- Dumping data for {$tbl}\n");
                foreach ($rows as $r) {
                    $cols = array_map(function($c){ return "`$c`"; }, array_keys($r));
                    $vals = array_map(function($v){ if ($v === null) return 'NULL'; return "'" . addslashes($v) . "'"; }, array_values($r));
                    fwrite($fp, "INSERT INTO `{$tbl}` (".implode(',', $cols).") VALUES (".implode(',', $vals).");\n");
                }
                fwrite($fp, "\n");
            }
        }

        fclose($fp);
        return $file;
    }
}
