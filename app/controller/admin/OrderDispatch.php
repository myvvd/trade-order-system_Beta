<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use think\facade\View;
use think\facade\Db;
use app\model\Order as OrderModel;
use app\model\Supplier as SupplierModel;
use app\model\OrderDispatch as OrderDispatchModel;
use app\service\DispatchService;

class OrderDispatch extends Base
{
    /**
     * 显示派单页面
     */
    public function create($orderId)
    {
        $order = OrderModel::with(['items', 'customer'])->find($orderId);
        if (!$order) {
            return redirect('admin/order/list')->with('error', '订单不存在');
        }

        $suppliers = SupplierModel::where('status', 1)->select();

        // 计算每个 order_item 已被派出的数量（排除已拒绝的派单）
        $rows = Db::name('order_dispatch_item')
            ->alias('di')
            ->join('order_dispatch d', 'di.dispatch_id = d.id')
            ->where('d.order_id', $orderId)
            ->where('d.status', '<>', OrderDispatchModel::STATUS_REJECTED)
            ->group('di.order_item_id')
            ->field('di.order_item_id, SUM(di.quantity) as dispatched')
            ->select();

        $dispatched_counts = [];
        foreach ($rows as $r) {
            $dispatched_counts[$r['order_item_id']] = (int)($r['dispatched'] ?? 0);
        }

        return View::fetch('admin/order/dispatch', [
            'order' => $order,
            'suppliers' => $suppliers,
            'dispatched_counts' => $dispatched_counts,
            'title' => '派单',
        ]);
    }

    /**
     * 保存派单
     */
    public function save()
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $params = $this->post();
        $orderId = (int)($params['order_id'] ?? 0);
        $supplierId = (int)($params['supplier_id'] ?? 0);
        $items = $params['items'] ?? [];

        if (!$orderId || !$supplierId || empty($items)) {
            return $this->error('参数不完整');
        }

        try {
            $service = new DispatchService();
            $dispatch = $service->createDispatch($orderId, $supplierId, $items, $this->admin->id ?? null);
            return $this->success(['id' => $dispatch->id], '派单已创建');
        } catch (\Exception $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
    }

    /**
     * 派单列表
     */
    public function list()
    {
        $supplierId = input('supplier_id/d', 0);
        $status = input('status', '');
        $date_from = input('date_from', '');
        $date_to = input('date_to', '');
        $page = input('page/d', 1);
        $pageSize = input('page_size/d', 15);

        $query = OrderDispatchModel::where('id', '>', 0)->with(['order', 'supplier']);
        if ($supplierId) $query->where('supplier_id', $supplierId);
        if ($status !== '') $query->where('status', $status);
        if ($date_from) $query->where('create_time', '>=', $date_from . ' 00:00:00');
        if ($date_to) $query->where('create_time', '<=', $date_to . ' 23:59:59');

        $dispatches = $query->order('id', 'desc')->paginate(['list_rows' => $pageSize, 'page' => $page]);
        // add status_text for frontend
        foreach ($dispatches as $d) {
            $d->status_text = \app\model\OrderDispatch::getStatusText($d->status ?? null);
        }
        $suppliers = SupplierModel::where('status', 1)->select();

        return View::fetch('admin/order_dispatch/list', [
            'dispatches' => $dispatches,
            'suppliers' => $suppliers,
            'supplier_id' => $supplierId,
            'status' => $status,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'title' => '派单管理',
        ]);
    }

    /**
     * 派单详情
     */
    public function detail($id)
    {
        $dispatch = OrderDispatchModel::with(['order', 'items', 'supplier'])->find($id);
        if (!$dispatch) {
            return redirect('admin/order_dispatch/list')->with('error', '派单不存在');
        }

        return View::fetch('admin/order_dispatch/detail', [
            'dispatch' => $dispatch,
            'title' => '派单详情',
        ]);
    }

    /**
     * 取消派单（管理员）
     */
    public function cancel($id)
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        try {
            $service = new DispatchService();
            $service->rejectDispatch((int)$id, $this->admin->id ?? null);
            return $this->success(null, '派单已取消');
        } catch (\Exception $e) {
            return $this->error('操作失败：' . $e->getMessage());
        }
    }

    /**
     * 收货页面与提交（管理员/仓库）
     */
    public function receive($id)
    {
        $dispatch = OrderDispatchModel::with(['order', 'items'])->find($id);
        if (!$dispatch) {
            return $this->redirectError('admin/order_dispatch/list', '派单不存在');
        }

        if ($this->isGet()) {
            return View::fetch('admin/order_dispatch/receive', [
                'dispatch' => $dispatch,
                'title' => '收货确认',
            ]);
        }

        // POST - 执行收货
        $params = $this->post();
        $items = $params['items'] ?? [];
        $qc_result = $params['qc_result'] ?? '';
        $remark = $params['remark'] ?? '';

        try {
            $service = new DispatchService();
            $service->receiveDispatch((int)$id, $items, $this->admin->id ?? null, $qc_result, $remark);
            return $this->success(null, '收货已处理');
        } catch (\Exception $e) {
            return $this->error('操作失败：' . $e->getMessage());
        }
    }
}
