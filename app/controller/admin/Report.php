<?php
namespace app\controller\admin;

use think\facade\View;
use think\facade\Request;
use think\facade\Db;
use app\service\ExportService;

class Report extends Base
{
    // 订单汇总报表页面
    public function orderSummary()
    {
        View::assign(['title'=>'订单汇总报表']);
        return View::fetch('admin/report/order_summary');
    }

    // JSON: 汇总数据
    public function data_summary()
    {
        $start = input('start', null);
        $end = input('end', null);
        $start = $start ?: '1970-01-01';
        $end = $end ?: date('Y-m-d');
        $startTime = $start.' 00:00:00';
        $endTime = $end.' 23:59:59';

        $total = Db::name('order')->whereBetween('created_at', [$startTime, $endTime])->field('count(*) as cnt, sum(total_amount) as amt')->find();
        $total_count = (int)($total['cnt'] ?? 0);
        $total_amount = (float)($total['amt'] ?? 0);
        $avg = $total_count ? round($total_amount / $total_count, 2) : 0;
        $completed = Db::name('order')->whereBetween('created_at', [$startTime, $endTime])->where('status','>=',50)->count();
        $complete_rate = $total_count ? round($completed / $total_count * 100, 2).'%' : '0%';

        // trend daily
        $days = input('days/d', 30);
        $trend = [];
        for ($i = $days-1; $i>=0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $s = $d.' 00:00:00'; $e = $d.' 23:59:59';
            $r = Db::name('order')->whereBetween('created_at', [$s,$e])->field('count(*) as cnt, sum(total_amount) as amt')->find();
            $trend[] = ['date'=>$d,'orders'=> (int)($r['cnt']??0),'amount'=>(float)($r['amt']??0)];
        }

        $rows = Db::name('order')->whereBetween('created_at', [$startTime, $endTime])->select();

        return json(['code'=>200,'data'=>['total_count'=>$total_count,'total_amount'=>$total_amount,'avg_amount'=>$avg,'complete_rate'=>$complete_rate,'trend'=>$trend,'rows'=>$rows]]);
    }

    // 按采购方统计页面
    public function orderByCustomer()
    {
        View::assign(['title'=>'按采购方统计']);
        return View::fetch('admin/report/by_customer');
    }

    public function data_by_customer()
    {
        $start = input('start','1970-01-01'); $end = input('end', date('Y-m-d'));
        $s = $start.' 00:00:00'; $e = $end.' 23:59:59';
        $rows = Db::name('order')->alias('o')->join('customer c','o.customer_id=c.id')->whereBetween('o.created_at',[$s,$e])->group('o.customer_id')->field('o.customer_id,c.name as customer_name,count(*) as cnt,sum(o.total_amount) as amt')->order('amt','desc')->select();
        // calculate percent
        $totalAmt = array_sum(array_column($rows->toArray(), 'amt')) ?: 0;
        $out = [];
        foreach ($rows as $r) {
            $r['amt_percent'] = $totalAmt ? round($r['amt'] / $totalAmt * 100,2).'%' : '0%';
            $out[] = $r;
        }
        return json(['code'=>200,'data'=>$out]);
    }

    // 按供应商统计页面
    public function orderBySupplier()
    {
        View::assign(['title'=>'按供应商统计']);
        return View::fetch('admin/report/by_supplier');
    }

    public function data_by_supplier()
    {
        $start = input('start','1970-01-01'); $end = input('end', date('Y-m-d'));
        $s = $start.' 00:00:00'; $e = $end.' 23:59:59';
        $rows = Db::name('order')->alias('o')->join('supplier s','o.supplier_id=s.id')->whereBetween('o.created_at',[$s,$e])->group('o.supplier_id')->field('o.supplier_id,s.name as supplier_name,count(*) as cnt,sum(o.total_amount) as amt')->order('amt','desc')->select();
        // enrich with completed counts and avg lead time
        $out = [];
        foreach ($rows as $r) {
            $completed = Db::name('order')->where('supplier_id',$r['supplier_id'])->whereBetween('created_at',[$s,$e])->where('status','>=',50)->count();
            $avgLead = Db::name('order')->where('supplier_id',$r['supplier_id'])->whereBetween('created_at',[$s,$e])->where('delivered_at','not null')->avg('DATEDIFF(delivered_at,created_at)');
            $r['completed'] = (int)$completed;
            $r['complete_rate'] = $r['cnt'] ? round($completed / $r['cnt'] * 100,2).'%' : '0%';
            $r['avg_lead_time'] = $avgLead ? round($avgLead,2) : 0;
            $out[] = $r;
        }
        return json(['code'=>200,'data'=>$out]);
    }

    // 按货品统计页面
    public function orderByGoods()
    {
        View::assign(['title'=>'按货品统计']);
        return View::fetch('admin/report/by_goods');
    }

    public function data_by_goods()
    {
        $start = input('start','1970-01-01'); $end = input('end', date('Y-m-d'));
        $s = $start.' 00:00:00'; $e = $end.' 23:59:59';
        $rows = Db::name('order_item')->alias('oi')->join('goods g','oi.goods_id=g.id')->whereBetween('oi.created_at',[$s,$e])->group('oi.goods_id')->field('oi.goods_id,g.name as goods_name,sum(oi.quantity) as qty,sum(oi.quantity*oi.price) as amt')->order('qty','desc')->select();
        return json(['code'=>200,'data'=>$rows]);
    }

    // 导出报表
    public function export($type)
    {
        $start = Request::param('start');
        $end = Request::param('end');

        // 根据类型构建数据与列
        if ($type === 'summary') {
            $rows = Db::name('order')->whereBetween('created_at', [$start, $end])->select();
            $columns = ['订单ID','订单号','采购方','金额','状态','创建时间'];
            $data = [];
            foreach ($rows as $r) $data[] = [ $r['id'], $r['order_no'] ?? '', $r['customer_id'] ?? '', $r['total_amount'] ?? 0, $r['status'] ?? '', $r['created_at'] ?? '' ];
            $filename = 'order_summary_'.date('YmdHis').'.xlsx';
        } elseif ($type === 'by_customer') {
            $rows = Db::name('order')->alias('o')->join('customer c','o.customer_id=c.id')->whereBetween('o.created_at',[$start,$end])->group('o.customer_id')->field('o.customer_id,c.name as customer_name,count(*) as cnt,sum(o.total_amount) as amt')->select();
            $columns = ['采购方ID','采购方名称','订单数','订单金额'];
            $data = [];
            foreach ($rows as $r) $data[] = [$r['customer_id'],$r['customer_name'],$r['cnt'],$r['amt']];
            $filename = 'order_by_customer_'.date('YmdHis').'.xlsx';
        } elseif ($type === 'by_supplier') {
            $rows = Db::name('order')->alias('o')->join('supplier s','o.supplier_id=s.id')->whereBetween('o.created_at',[$start,$end])->group('o.supplier_id')->field('o.supplier_id,s.name as supplier_name,count(*) as cnt,sum(o.total_amount) as amt')->select();
            $columns = ['供应商ID','供应商','接单数','订单金额'];
            $data = [];
            foreach ($rows as $r) $data[] = [$r['supplier_id'],$r['supplier_name'],$r['cnt'],$r['amt']];
            $filename = 'order_by_supplier_'.date('YmdHis').'.xlsx';
        } elseif ($type === 'by_goods') {
            $rows = Db::name('order_item')->alias('oi')->join('goods g','oi.goods_id=g.id')->whereBetween('oi.created_at',[$start,$end])->group('oi.goods_id')->field('oi.goods_id,g.name as goods_name,sum(oi.quantity) as qty,sum(oi.quantity*oi.price) as amt')->select();
            $columns = ['货品ID','货品名称','销售数量','销售金额'];
            $data = [];
            foreach ($rows as $r) $data[] = [$r['goods_id'],$r['goods_name'],$r['qty'],$r['amt']];
            $filename = 'order_by_goods_'.date('YmdHis').'.xlsx';
        } else {
            return $this->error('未知导出类型');
        }

        $exporter = new ExportService();
        return $exporter->exportExcel($data, $columns, $filename);
    }
}
