<?php
namespace app\controller\admin;

use think\facade\View;
use think\facade\Request;
use app\service\DashboardService;

class Index extends Base
{
    public function index()
    {
        $svc = new DashboardService();
        $cards = $svc->getSummaryCards();
        $statusCount = $svc->getOrderStatusCount();
        $pending = $svc->getPendingTasks();
        $warnings = $svc->getWarnings();
        
        View::assign([
            'title'            => '控制台',
            'cards_json'       => json_encode($cards, JSON_UNESCAPED_UNICODE),
            'status_count_json'=> json_encode($statusCount, JSON_UNESCAPED_UNICODE),
            'pending_json'     => json_encode($pending, JSON_UNESCAPED_UNICODE),
            'warnings_json'    => json_encode($warnings, JSON_UNESCAPED_UNICODE),
            'breadcrumb'       => '控制台',
            'current_page'     => 'dashboard',
        ]);
        return View::fetch('admin/index/index');
    }

    public function statistics()
    {
        $svc = new DashboardService();
        $data = [];
        $data['cards'] = $svc->getSummaryCards();
        $data['status_count'] = $svc->getOrderStatusCount();
        $data['pending'] = $svc->getPendingTasks();
        $data['warnings'] = $svc->getWarnings();
        return json(['code'=>200,'data'=>$data]);
    }

    public function chartData($type = 'orders')
    {
        $svc = new DashboardService();
        if ($type === 'trend') {
            $days = Request::param('days/d', 30);
            $data = $svc->getOrderTrend($days);
            return json(['code'=>200,'data'=>$data]);
        }
        if ($type === 'status') {
            return json(['code'=>200,'data'=>$svc->getOrderStatusCount()]);
        }
        if ($type === 'top_suppliers') {
            return json(['code'=>200,'data'=>$svc->getTopSuppliers(10)]);
        }
        if ($type === 'top_goods') {
            return json(['code'=>200,'data'=>$svc->getTopGoods(10)]);
        }

        return json(['code'=>400,'msg'=>'未知类型']);
    }
}

