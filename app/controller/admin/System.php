<?php
namespace app\controller\admin;

use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use think\facade\Cache;
use app\service\ConfigService;
use app\service\BackupService;

class System extends Base
{
    // 系统配置页面（分组 tabs）
    public function config()
    {
        $cfg = new ConfigService();
        $basic = $cfg->getGroup('basic');
        $order = $cfg->getGroup('order');
        $stock = $cfg->getGroup('stock');

        View::assign(['title'=>'系统设置','basic'=>$basic,'order'=>$order,'stock'=>$stock]);
        return View::fetch('admin/system/config');
    }

    // 保存配置
    public function saveConfig()
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $data = $this->post();
        $cfg = new ConfigService();
        try{
            // 期望数据以 group[key]=value 形式，或 flat key => value
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    foreach ($v as $subk=>$subv) {
                        $cfg->set("{$k}.{$subk}", $subv);
                    }
                } else {
                    $cfg->set($k, $v);
                }
            }
            return $this->success(null, '保存成功');
        }catch(\Exception $e){
            return $this->error('保存失败：'.$e->getMessage());
        }
    }

    // 操作日志列表
    public function logs()
    {
        $operator = input('operator', '');
        $module = input('module', '');
        $start = input('start', '');
        $end = input('end', '');
        $pageSize = input('page_size/d', 20);

        $query = Db::name('operation_log');
        if ($operator) $query->where('operator', 'like', "%{$operator}%");
        if ($module) $query->where('module', $module);
        if ($start) $query->where('created_at', '>=', $start.' 00:00:00');
        if ($end) $query->where('created_at', '<=', $end.' 23:59:59');

        $list = $query->order('id','desc')->paginate(['list_rows'=>$pageSize]);
        View::assign(['title'=>'操作日志','list'=>$list,'operator'=>$operator,'module'=>$module,'start'=>$start,'end'=>$end]);
        return View::fetch('admin/system/logs');
    }

    // 日志详情
    public function logDetail($id)
    {
        $row = Db::name('operation_log')->find($id);
        if (!$row) return $this->redirectError('/admin/system/logs','日志不存在');
        View::assign(['title'=>'日志详情','row'=>$row]);
        return View::fetch('admin/system/log_detail');
    }

    // JSON: 操作日志数据（用于前端异步加载）
    public function data_logs()
    {
        $operator = input('operator', '');
        $module = input('module', '');
        $start = input('start', '');
        $end = input('end', '');
        $page = input('page/d', 1);
        $pageSize = input('page_size/d', 20);

        $query = Db::name('operation_log');
        if ($operator) $query->where('operator', 'like', "%{$operator}%");
        if ($module) $query->where('module', $module);
        if ($start) $query->where('created_at', '>=', $start.' 00:00:00');
        if ($end) $query->where('created_at', '<=', $end.' 23:59:59');

        $list = $query->order('id','desc')->paginate(['list_rows'=>$pageSize, 'page'=>$page]);
        $items = [];
        foreach ($list->items() as $r) $items[] = $r;

        return json(['code'=>200,'data'=>['list'=>$items,'total'=>$list->total(),'page'=>$list->currentPage(),'page_size'=>$pageSize,'page_total'=>$list->lastPage()]]);
    }

    // 清除系统缓存
    public function clearCache()
    {
        try{
            Cache::clear();
            // delete runtime/temp files if needed
            $runtime = root_path() . 'runtime';
            // simple cleanup: remove runtime/temp/* if exists
            $tempDir = $runtime . '/temp';
            if (is_dir($tempDir)) {
                $files = glob($tempDir . '/*');
                foreach ($files as $f) @unlink($f);
            }
            return $this->success(null, '缓存已清除');
        }catch(\Exception $e){
            return $this->error('清理失败：'.$e->getMessage());
        }
    }

    // 数据库备份
    public function backup()
    {
        try{
            $svc = new BackupService();
            $file = $svc->backup();
            return $this->success(['file'=>$file], '备份完成');
        }catch(\Exception $e){
            return $this->error('备份失败：'.$e->getMessage());
        }
    }
}
