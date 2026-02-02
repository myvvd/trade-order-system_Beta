<?php
namespace app\service;

use think\facade\Db;

class DashboardService
{
    public function getSummaryCards()
    {
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $weekStart = date('Y-m-d 00:00:00', strtotime('this week'));
        $monthStart = date('Y-m-01 00:00:00');

        $today = Db::name('order')->whereBetween('create_time', [$todayStart, $todayEnd])->field('count(*) as cnt, sum(total_amount) as amt')->find();
        $week = Db::name('order')->where('create_time', '>=', $weekStart)->field('count(*) as cnt, sum(total_amount) as amt')->find();
        $month = Db::name('order')->where('create_time', '>=', $monthStart)->field('count(*) as cnt, sum(total_amount) as amt')->find();
        $pending = Db::name('order')->where('status', '<', 50)->count();

        return [
            'today_orders' => (int)($today['cnt'] ?? 0),
            'today_amount' => (float)($today['amt'] ?? 0),
            'week_orders' => (int)($week['cnt'] ?? 0),
            'week_amount' => (float)($week['amt'] ?? 0),
            'month_orders' => (int)($month['cnt'] ?? 0),
            'month_amount' => (float)($month['amt'] ?? 0),
            'pending' => (int)$pending,
        ];
    }

    public function getOrderStatusCount()
    {
        $rows = Db::name('order')->group('status')->field('status, count(*) as cnt')->select();
        $map = [];
        foreach ($rows as $r) $map[$r['status']] = (int)$r['cnt'];
        return $map;
    }

    public function getOrderTrend($days = 30)
    {
        $data = [];
        for ($i = $days -1; $i >=0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $start = $d . ' 00:00:00';
            $end = $d . ' 23:59:59';
            $row = Db::name('order')->whereBetween('create_time', [$start, $end])->field('count(*) as cnt, sum(total_amount) as amt')->find();
            $data[] = [ 'date'=>$d, 'orders'=>(int)($row['cnt']??0), 'amount'=>(float)($row['amt']??0) ];
        }
        return $data;
    }

    public function getTopSuppliers($limit = 10)
    {
        $rows = Db::name('order')->alias('o')
            ->join('supplier s','o.supplier_id = s.id')
            ->group('o.supplier_id')
            ->field('o.supplier_id, s.name as supplier_name, count(*) as cnt, sum(o.total_amount) as amt')
            ->order('amt','desc')
            ->limit($limit)
            ->select();
        return $rows;
    }

    public function getTopGoods($limit = 10)
    {
        $rows = Db::name('order_item')->alias('oi')
            ->join('goods g','oi.goods_id = g.id')
            ->group('oi.goods_id')
            ->field('oi.goods_id, g.name as goods_name, sum(oi.quantity) as qty, sum(oi.quantity*oi.price) as amt')
            ->order('qty','desc')
            ->limit($limit)
            ->select();
        return $rows;
    }

    public function getPendingTasks()
    {
        $tasks = [];
        $tasks[] = ['title'=>'待处理订单', 'count'=> (int)Db::name('order')->where('status','<',50)->count(), 'link'=>'/admin/order/list'];
        $tasks[] = ['title'=>'待发货', 'count'=> (int)Db::name('order')->where('status',50)->count(), 'link'=>'/admin/order/list?status=50'];
        return $tasks;
    }

    public function getWarnings()
    {
        $warnings = [];
        // 低库存 - 预警数量字段在 stock 表中
        $low = Db::name('stock')->alias('s')
            ->join('goods g', 's.goods_id=g.id')
            ->where('s.quantity', '<=', Db::raw('s.warning_quantity'))
            ->where('s.warning_quantity', '>', 0)
            ->field('g.id, g.name, s.quantity, s.warning_quantity')
            ->limit(10)
            ->select();
        foreach ($low as $l) {
            $warnings[] = [
                'type' => 'stock_low',
                'msg' => "{$l['name']} 库存 {$l['quantity']}，预警阈值 {$l['warning_quantity']}"
            ];
        }
        return $warnings;
    }
}
