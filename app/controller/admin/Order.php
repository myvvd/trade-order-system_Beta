<?php

namespace app\controller\admin;

use app\controller\admin\Base;
use app\model\Order as OrderModel;
use app\model\Customer as CustomerModel;
use app\service\OrderService;
use think\facade\View;

class Order extends Base
{
    public function list()
    {
        $keyword = input('keyword', '');
        $status = input('status', '');
        $date_from = input('date_from', '');
        $date_to = input('date_to', '');
        $page = input('page/d', 1);
        $pageSize = input('page_size/d', 15);

        $query = OrderModel::where('id', '>', 0);

        if ($keyword) {
            $query->whereLike('order_no|remark', '%' . $keyword . '%');
            $query->whereOr('customer_id', function ($q) use ($keyword) {
                $q->name('customer')->whereLike('company_name|contact_name', '%' . $keyword . '%');
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($date_from) {
            $query->where('create_time', '>=', $date_from . ' 00:00:00');
        }
        if ($date_to) {
            $query->where('create_time', '<=', $date_to . ' 23:59:59');
        }

        $orders = $query->with(['customer'])->order('id', 'desc')->paginate([
            'list_rows' => $pageSize,
            'page' => $page,
        ]);

        $tabs = [
            '' => '全部',
            OrderModel::STATUS_PENDING => '待派单',
            OrderModel::STATUS_WAIT_CONFIRM => '待确认',
            OrderModel::STATUS_IN_PROGRESS => '制作中',
            OrderModel::STATUS_WAIT_RECEIVE => '待收货',
            OrderModel::STATUS_RECEIVED => '已入库',
            OrderModel::STATUS_SHIPPED => '已发货',
            OrderModel::STATUS_COMPLETED => '已完成',
        ];

        return View::fetch('admin/order/list', [
            'orders' => $orders,
            'keyword' => $keyword,
            'status' => $status,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'tabs' => $tabs,
            'title' => '订单管理',
            'isSuper' => $this->isSuperAdmin(),
        ]);
    }

    public function create()
    {
        $customers = CustomerModel::where('delete_time', null)->limit(50)->select();
        return View::fetch('admin/order/form', [
            'order' => null,
            'customers' => $customers,
            'title' => '新增订单',
            'isSuper' => $this->isSuperAdmin(),
        ]);
    }

    public function save()
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $params = $this->post();

        try {
            validate(\app\validate\OrderValidate::class)->scene('create')->check($params);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        try {
            $service = new OrderService();
            $order = $service->createOrder($params, $this->getAdminId());
            return $this->success(['id' => $order->id], '保存成功');
        } catch (\Exception $e) {
            return $this->error('保存失败：' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $order = OrderModel::find($id);
        if (!$order) {
            return redirect('admin/order/list')->with('error', '订单不存在');
        }
        if ($order->status != OrderModel::STATUS_PENDING) {
            return redirect('admin/order/list')->with('error', '仅待派单订单可编辑');
        }

        $customers = CustomerModel::where('delete_time', null)->limit(50)->select();
        return View::fetch('admin/order/form', [
            'order' => $order->toArray(),
            'customers' => $customers,
            'title' => '编辑订单',
            'isSuper' => $this->isSuperAdmin(),
        ]);
    }

    public function update($id)
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $params = $this->post();

        try {
            validate(\app\validate\OrderValidate::class)->scene('update')->check(array_merge(['id' => $id], $params));
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $order = OrderModel::find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }
        if ($order->status != OrderModel::STATUS_PENDING) {
            return $this->error('仅待派单订单可编辑');
        }

        try {
            // 简单实现：删除原有明细后重建
            \think\facade\Db::transaction(function () use ($order, $params) {
                \think\facade\Db::name('order_item')->where('order_id', $order->id)->delete();
                $items = $params['items'] ?? [];
                foreach ($items as $it) {
                    \think\facade\Db::name('order_item')->insert([
                        'order_id' => $order->id,
                        'goods_id' => $it['goods_id'] ?? null,
                        'sku_id' => $it['sku_id'] ?? null,
                        'quantity' => $it['quantity'] ?? 0,
                        'price' => $it['price'] ?? 0,
                        'remark' => $it['remark'] ?? ''
                    ]);
                }

                $order->total_amount = $params['total_amount'] ?? $order->total_amount;
                $order->remark = $params['remark'] ?? $order->remark;
                $order->save();
            });

            return $this->success(null, '更新成功');
        } catch (\Exception $e) {
            return $this->error('更新失败：' . $e->getMessage());
        }
    }

    public function detail($id)
    {
        $order = OrderModel::with(['items', 'dispatches', 'statusLogs', 'attachments', 'customer'])->find($id);
        if (!$order) {
            return redirect('admin/order/list')->with('error', '订单不存在');
        }

        return View::fetch('admin/order/detail', [
            'order' => $order,
            'title' => '订单详情',
            'isSuper' => $this->isSuperAdmin(),
        ]);
    }

    public function delete($id)
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        $order = OrderModel::find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }
        if ($order->status != OrderModel::STATUS_PENDING) {
            return $this->error('仅待派单订单可删除');
        }

        try {
            \think\facade\Db::name('order_item')->where('order_id', $order->id)->delete();
            $order->delete();
            return $this->success(null, '删除成功');
        } catch (\Exception $e) {
            return $this->error('删除失败：' . $e->getMessage());
        }
    }

    public function cancel($id)
    {
        if (!$this->isPost()) {
            return $this->error('请求方式错误');
        }

        try {
            $service = new OrderService();
            $service->cancelOrder((int)$id, $this->getAdminId());
            return $this->success(null, '取消成功');
        } catch (\Exception $e) {
            return $this->error('操作失败：' . $e->getMessage());
        }
    }

    /**
     * 初始化订单测试数据
     */
    public function initTestData()
    {
        if (!$this->isSuper()) {
            return $this->error('权限不足');
        }

        try {
            \think\facade\Db::startTrans();

            // 删除现有测试数据
            OrderModel::where('order_no', 'like', 'TEST%')->delete();
            \app\model\OrderItem::where('order_id', 'in', function($query) {
                $query->table('order')->where('order_no', 'like', 'TEST%')->field('id');
            })->delete();

            // 创建 10 个测试订单，涵盖所有状态
            $statuses = [
                OrderModel::STATUS_PENDING,        // 待派单
                OrderModel::STATUS_WAIT_CONFIRM,   // 待确认
                OrderModel::STATUS_REJECTED,       // 已拒绝
                OrderModel::STATUS_IN_PROGRESS,    // 制作中
                OrderModel::STATUS_WAIT_RECEIVE,   // 待收货
                OrderModel::STATUS_RECEIVED,       // 已入库
                OrderModel::STATUS_SHIPPED,        // 已发货
                OrderModel::STATUS_COMPLETED,      // 已完成
                OrderModel::STATUS_CANCELED,       // 已取消
                OrderModel::STATUS_PENDING,        // 再次添加待派单
            ];

            $customers = \app\model\Customer::where('delete_time', null)->limit(5)->column('id');
            if (empty($customers)) {
                throw new \Exception('请先创建客户数据');
            }

            for ($i = 1; $i <= 10; $i++) {
                $orderNo = 'TEST' . date('Ymd') . str_pad($i, 4, '0', STR_PAD_LEFT);
                $status = $statuses[$i - 1];
                $customerId = $customers[($i - 1) % count($customers)];
                
                // 创建订单
                $order = OrderModel::create([
                    'order_no' => $orderNo,
                    'customer_id' => $customerId,
                    'status' => $status,
                    'total_amount' => rand(1000, 50000) / 100,
                    'currency' => ['CNY', 'USD', 'EUR'][rand(0, 2)],
                    'delivery_date' => date('Y-m-d', strtotime('+' . rand(10, 90) . ' days')),
                    'remark' => '测试订单 ' . $i . ' - 状态：' . OrderModel::getStatusText($status),
                    'creator_id' => $this->getAdminId(),
                ]);

                // 为每个订单创建 1-3 个订单项
                $itemCount = rand(1, 3);
                for ($j = 1; $j <= $itemCount; $j++) {
                    $unitPrice = rand(100, 2000) / 100;
                    $quantity = rand(10, 500);
                    $amount = $unitPrice * $quantity;

                    \app\model\OrderItem::create([
                        'order_id' => $order->id,
                        'goods_name' => '测试商品' . $j . '_订单' . $i,
                        'sku_name' => '规格' . $j,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'amount' => $amount,
                        'remark' => $j == 1 ? '主要商品' : '配件商品',
                    ]);
                }
            }

            \think\facade\Db::commit();
            return $this->success([], '测试数据初始化成功！已创建10个不同状态的测试订单');
            
        } catch (\Exception $e) {
            \think\facade\Db::rollback();
            return $this->error('初始化失败：' . $e->getMessage());
        }
    }
}
