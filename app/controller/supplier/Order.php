<?php

namespace app\controller\supplier;

use think\facade\View;
use think\facade\Db;
use app\model\OrderDispatch as OrderDispatchModel;
use app\model\Order as OrderModel;
use app\service\DispatchService;

class Order extends Base
{
    /**
     * 列表（我的订单）
     */
    public function list()
    {
        $supplierId = $this->getSupplierId();
        if (!$supplierId) {
            return $this->redirectError('/supplier/login', '请先登录');
        }

        $status = input('status', '');
        $pageSize = input('page_size/d', 15);

        $query = OrderDispatchModel::with(['order', 'items'])->where('supplier_id', $supplierId);
        if ($status !== '') {
            $query->where('status', $status);
        }

        // 使用基础分页助手返回结构化分页数据
        $pageData = $this->paginate($query, $pageSize);

        View::assign([
            'title' => '我的派单',
            'dispatches' => $pageData['list'],
            'total' => $pageData['total'],
            'page' => $pageData['page'],
            'page_size' => $pageData['page_size'],
            'page_total' => $pageData['page_total'],
            'status' => $status,
        ]);

        return View::fetch('supplier/order/list');
    }

    // 兼容的别名
    public function my()
    {
        return $this->list();
    }

    /**
     * 派单详情 (确保属于当前供应商)
     */
    public function detail($id)
    {
        $supplierId = $this->getSupplierId();
        $dispatch = OrderDispatchModel::with(['order', 'items'])->where('id', $id)->where('supplier_id', $supplierId)->find();
        if (!$dispatch) {
            return $this->redirectError('/supplier/order/my', '派单不存在或无权限查看');
        }

        View::assign(['title' => '派单详情', 'dispatch' => $dispatch]);
        return View::fetch('supplier/order/detail');
    }

    /**
     * 确认接单
     */
    public function confirm($id)
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $supplierId = $this->getSupplierId();
        $dispatch = OrderDispatchModel::where('id', $id)->where('supplier_id', $supplierId)->find();
        if (!$dispatch) return $this->error('派单不存在或无权限操作');

        try {
            $service = new DispatchService();
            $service->confirmDispatch((int)$id, $this->getSupplierUserId());
            return $this->success(null, '已确认接单');
        } catch (\Exception $e) {
            return $this->error('操作失败：' . $e->getMessage());
        }
    }

    /**
     * 拒绝接单
     */
    public function reject($id)
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $reason = $this->post('reason', '');
        $supplierId = $this->getSupplierId();
        $dispatch = OrderDispatchModel::where('id', $id)->where('supplier_id', $supplierId)->find();
        if (!$dispatch) return $this->error('派单不存在或无权限操作');

        try {
            $service = new DispatchService();
            $service->rejectDispatch((int)$id, $this->getSupplierUserId());
            // 保存拒绝原因到备注（兼容字段）
            $dispatch->reject_reason = $reason;
            $dispatch->remark = trim(($dispatch->remark ?? '') . "\n拒绝原因：" . $reason);
            $dispatch->save();
            return $this->success(null, '已拒绝派单');
        } catch (\Exception $e) {
            return $this->error('操作失败：' . $e->getMessage());
        }
    }

    /**
     * 更新制作进度（可选）
     */
    public function updateProgress($id)
    {
        if (!$this->isPost()) return $this->error('请求方式错误');
        $progress = $this->post('progress', '');
        $supplierId = $this->getSupplierId();
        $dispatch = OrderDispatchModel::where('id', $id)->where('supplier_id', $supplierId)->find();
        if (!$dispatch) return $this->error('派单不存在或无权限操作');

        $dispatch->progress = $progress;
        $dispatch->save();
        return $this->success(null, '已更新进度');
    }

    /**
     * 发货（GET 显示表单，POST 提交）
     */
    public function ship($id)
    {
        $supplierId = $this->getSupplierId();
        $dispatch = OrderDispatchModel::with(['order', 'items'])->where('id', $id)->where('supplier_id', $supplierId)->find();
        if (!$dispatch) {
            return $this->redirectError('/supplier/order/my', '派单不存在或无权限操作');
        }

        if ($this->isGet()) {
            View::assign(['title' => '发货 - 派单' . $dispatch->id, 'dispatch' => $dispatch]);
            return View::fetch('supplier/order/ship');
        }

        // POST 提交发货信息
        if (!$this->isPost()) return $this->error('请求方式错误');
        $ship_no = $this->post('ship_no', '');
        $carrier = $this->post('carrier', '');
        $remark = $this->post('remark', '');

        try {
            $service = new DispatchService();
            $service->shipDispatch((int)$id, $this->getSupplierUserId());

            $dispatch->ship_info = json_encode(['carrier' => $carrier, 'ship_no' => $ship_no]);
            $dispatch->remark = trim(($dispatch->remark ?? '') . "\n发货信息：" . $carrier . ' / ' . $ship_no . '\n' . $remark);
            $dispatch->save();

            return $this->success(null, '已标记为已发货');
        } catch (\Exception $e) {
            return $this->error('操作失败：' . $e->getMessage());
        }
    }

    /**
     * 历史订单
     */
    public function history()
    {
        $supplierId = $this->getSupplierId();
        $page = input('page/d', 1);
        $pageSize = input('page_size/d', 15);

        $query = OrderDispatchModel::with(['order', 'items'])->where('supplier_id', $supplierId)->where('status', '<>', OrderDispatchModel::STATUS_WAIT_CONFIRM);
        $pageData = $this->paginate($query, $pageSize);

        View::assign(['title' => '历史订单', 'dispatches' => $pageData['list'], 'total' => $pageData['total'], 'page' => $pageData['page'], 'page_size' => $pageData['page_size'], 'page_total' => $pageData['page_total']]);
        return View::fetch('supplier/order/list');
    }

    /**
     * 异步获取待处理派单数（用于首页轮询）
     */
    public function pendingCount()
    {
        $supplierId = $this->getSupplierId();
        if (!$supplierId) {
            return $this->error('未登录', 401);
        }

        $count = (int)OrderDispatchModel::where('supplier_id', $supplierId)->where('status', OrderDispatchModel::STATUS_WAIT_CONFIRM)->count();
        return $this->success(['pending' => $count], 'ok');
    }
}
