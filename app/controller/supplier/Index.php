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
        $pendingCount = 0;
        $recent = [];
        if ($supplierId) {
            $pendingCount = (int)\app\model\OrderDispatch::where('supplier_id', $supplierId)->where('status', \app\model\OrderDispatch::STATUS_WAIT_CONFIRM)->count();
            $recent = \app\model\OrderDispatch::with(['order'])->where('supplier_id', $supplierId)->order('id', 'desc')->limit(5)->select();
        }

        View::assign([
            'title'          => '我的订单 - 外贸订单管理系统',
            'current_page'    => 'my_order',
            'supplier_name'   => $this->getSupplierName(),
            'pending_count'   => $pendingCount,
            'recent'          => $recent,
        ]);

        return View::fetch('supplier/index');
    }
}
