<?php

namespace app\controller\supplier;

use think\facade\View;

/**
 * 供应商端首页控制器
 */
class Index extends Base
{
    /**
     * 首页
     */
    public function index()
    {
        $supplierId = $this->getSupplierId();
        // 统计待处理派单数量
        $counts = [
            'wait_confirm' => 0,
            'producing' => 0,
            'completed' => 0,
            'canceled' => 0,
        ];
        $recent = [];
        if ($supplierId) {
            $statusCounts = \app\model\OrderDispatch::where('supplier_id', $supplierId)
                ->field('status, count(id) as c')
                ->group('status')
                ->select()
                ->column('c', 'status');
                
            $counts['wait_confirm'] = $statusCounts[\app\model\OrderDispatch::STATUS_WAIT_CONFIRM] ?? 0;
            // 制作中为30
            $counts['producing'] = $statusCounts[\app\model\OrderDispatch::STATUS_IN_PROGRESS] ?? 0;
            // 已完成（待收货40、已入库/已收货50）
            $counts['completed'] = ($statusCounts[\app\model\OrderDispatch::STATUS_WAIT_RECEIVE] ?? 0) + ($statusCounts[\app\model\OrderDispatch::STATUS_RECEIVED] ?? 0);
            // 已取消/已拒绝为21
            $counts['canceled'] = $statusCounts[\app\model\OrderDispatch::STATUS_REJECTED] ?? 0;

            // 获取订单列表，这里暂时取最新的50条以供展示
            $recent = \app\model\OrderDispatch::with(['order', 'items'])->where('supplier_id', $supplierId)->order('id', 'desc')->limit(50)->select();
        }

        View::assign([
            'title'          => '我的订单 - 外贸订单管理系统',
            'current_page'    => 'home',
            'supplier_name'   => $this->getSupplierName(),
            'counts'          => $counts,
            'recent'          => $recent,
        ]);

        return View::fetch('supplier/index');
    }
}
