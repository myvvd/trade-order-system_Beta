<?php

namespace app\service;

use think\facade\Db;
use app\model\Order;
use app\model\OrderDispatch;
use app\model\OrderDispatchItem;
use app\model\OrderItem;
use app\service\StockService;
use app\service\MessageService;

class DispatchService
{
    protected OrderService $orderService;

    public function __construct()
    {
        $this->orderService = new OrderService();
    }

    /**
     * 创建派单
     * $items: [{order_item_id, quantity}, ...]
     */
    public function createDispatch(int $orderId, int $supplierId, array $items, ?int $adminId = null): OrderDispatch
    {
        return Db::transaction(function () use ($orderId, $supplierId, $items, $adminId) {
            $order = Order::find($orderId);
            if (!$order) {
                throw new \RuntimeException('订单不存在');
            }

            // 校验每个 order_item 是否有足够剩余可派数量
            foreach ($items as $it) {
                $orderItemId = $it['order_item_id'] ?? 0;
                $qty = (int)($it['quantity'] ?? 0);
                if (!$orderItemId || $qty <= 0) {
                    throw new \RuntimeException('派单明细参数错误');
                }

                $orderItem = OrderItem::find($orderItemId);
                if (!$orderItem) {
                    throw new \RuntimeException('订单明细不存在');
                }

                $orderedQty = (int)$orderItem->quantity;

                $already = (int)Db::name('order_dispatch_item')
                    ->alias('di')
                    ->join('order_dispatch d', 'di.dispatch_id = d.id')
                    ->where('di.order_item_id', $orderItemId)
                    ->where('d.status', '<>', OrderDispatch::STATUS_REJECTED)
                    ->sum('di.quantity') ?: 0;

                if ($already + $qty > $orderedQty) {
                    throw new \RuntimeException('派单数量超出可用数量 (订单明细ID:' . $orderItemId . ')');
                }
            }

            $dispatch = new OrderDispatch();
            $dispatch->save([
                'dispatch_no' => OrderDispatch::generateDispatchNo(),
                'order_id' => $orderId,
                'supplier_id' => $supplierId,
                'status' => OrderDispatch::STATUS_WAIT_CONFIRM,
            ]);

            foreach ($items as $it) {
                $di = new OrderDispatchItem();
                $di->save([
                    'dispatch_id' => $dispatch->id,
                    'order_item_id' => $it['order_item_id'] ?? null,
                    'quantity' => $it['quantity'] ?? 0,
                ]);
            }

            // 更新订单为待确认
            $order->status = Order::STATUS_WAIT_CONFIRM;
            $order->save();

            // 同步货品状态和订单送货状态
            Order::syncAllItemStatuses($orderId);

            $this->orderService->logStatus($orderId, $order->status, '创建派单', $adminId, $dispatch->id);

            // 发送站内信通知
            try {
                (new MessageService())->notifyDispatchCreated($dispatch, $adminId);
            } catch (\Throwable $e) {}

            return $dispatch->refresh();
        });
    }

    /**
     * 供应商确认派单（开始制作）
     */
    public function confirmDispatch(int $dispatchId, ?int $adminId = null): OrderDispatch
    {
        return Db::transaction(function () use ($dispatchId, $adminId) {
            $dispatch = OrderDispatch::find($dispatchId);
            if (!$dispatch) {
                throw new \RuntimeException('派单不存在');
            }

            $dispatch->status = OrderDispatch::STATUS_IN_PROGRESS;
            $dispatch->save();

            $order = $dispatch->order;
            $order->status = Order::STATUS_IN_PROGRESS;
            $order->save();

            // 同步货品状态和订单送货状态
            Order::syncAllItemStatuses($order->id);

            $this->orderService->logStatus($order->id, $order->status, '派单确认，制作中', $adminId, $dispatch->id);

            // 发送站内信通知
            try {
                (new MessageService())->notifyDispatchConfirmed($dispatch, $adminId);
            } catch (\Throwable $e) {}

            return $dispatch->refresh();
        });
    }

    /**
     * 拒绝派单
     */
    public function rejectDispatch(int $dispatchId, ?int $adminId = null): OrderDispatch
    {
        return Db::transaction(function () use ($dispatchId, $adminId) {
            $dispatch = OrderDispatch::find($dispatchId);
            if (!$dispatch) {
                throw new \RuntimeException('派单不存在');
            }

            $dispatch->status = OrderDispatch::STATUS_REJECTED;
            $dispatch->save();

            $order = $dispatch->order;
            $order->status = Order::STATUS_REJECTED;
            $order->save();

            // 同步货品状态和订单送货状态
            Order::syncAllItemStatuses($order->id);

            $this->orderService->logStatus($order->id, $order->status, '派单被拒绝', $adminId, $dispatch->id);

            // 发送站内信通知
            try {
                (new MessageService())->notifyDispatchRejected($dispatch, $adminId);
            } catch (\Throwable $e) {}

            return $dispatch->refresh();
        });
    }

    /**
     * 发货（供应商标记已发货）
     */
    public function shipDispatch(int $dispatchId, ?int $adminId = null): OrderDispatch
    {
        return Db::transaction(function () use ($dispatchId, $adminId) {
            $dispatch = OrderDispatch::find($dispatchId);
            if (!$dispatch) {
                throw new \RuntimeException('派单不存在');
            }

            $dispatch->status = OrderDispatch::STATUS_WAIT_RECEIVE;
            $dispatch->save();

            $order = $dispatch->order;
            $order->status = Order::STATUS_SHIPPED;
            $order->save();

            // 同步货品状态和订单送货状态
            Order::syncAllItemStatuses($order->id);

            $this->orderService->logStatus($order->id, $order->status, '派单已发货', $adminId, $dispatch->id);

            // 发送站内信通知
            try {
                (new MessageService())->notifyDispatchShipped($dispatch, $adminId);
            } catch (\Throwable $e) {}

            return $dispatch->refresh();
        });
    }

    /**
     * 收货（仓库确认收货）
     */
    /**
     * 收货（仓库确认收货）
     * @param int $dispatchId
     * @param array|null $receivedItems 格式: [ ['dispatch_item_id'=>id, 'quantity'=>num], ... ]
     * @param int|null $adminId
     * @param string|null $qc_result
     * @param string|null $remark
     * @return OrderDispatch
     */
    public function receiveDispatch(int $dispatchId, ?array $receivedItems = null, ?int $adminId = null, ?string $qc_result = null, ?string $remark = null): OrderDispatch
    {
        return Db::transaction(function () use ($dispatchId, $receivedItems, $adminId, $qc_result, $remark) {
            $dispatch = OrderDispatch::with(['items'])->find($dispatchId);
            if (!$dispatch) {
                throw new \RuntimeException('派单不存在');
            }

            $stockService = new StockService();

            // 处理每个派单明细的实际收货数量
            $receivedMap = [];
            if (is_array($receivedItems)) {
                foreach ($receivedItems as $it) {
                    $key = (int)($it['dispatch_item_id'] ?? 0);
                    $qty = (int)($it['quantity'] ?? 0);
                    if ($key > 0 && $qty >= 0) $receivedMap[$key] = $qty;
                }
            }

            foreach ($dispatch->items as $di) {
                $recvQty = $receivedMap[$di->id] ?? (int)$di->quantity;

                // 获取 goods/sku 信息：优先使用 order_item 关联
                $goodsId = $di->order_item->goods_id ?? ($di->goods_id ?? null);
                $skuId = $di->order_item->sku_id ?? ($di->sku_id ?? null);

                if ($recvQty > 0 && $goodsId) {
                    $stockService->inStock($goodsId, $skuId, $recvQty, 'dispatch', $dispatch->id, '派单入库');
                }
            }

            $dispatch->status = OrderDispatch::STATUS_RECEIVED;
            $dispatch->receive_time = date('Y-m-d H:i:s');
            if ($qc_result) $dispatch->qc_result = $qc_result;
            if ($remark) $dispatch->remark = trim(($dispatch->remark ?? '') . "\n" . $remark);
            $dispatch->save();

            // 发送站内信通知
            try {
                (new MessageService())->notifyDispatchReceived($dispatch, $adminId);
            } catch (\Throwable $e) {}

            // 记录状态变更日志
            $this->orderService->logStatus($dispatch->order_id, Order::STATUS_RECEIVED, '派单已入库', $adminId, $dispatch->id);

            // 检查主订单是否所有派单都已完成或拒绝
            $order = Order::find($dispatch->order_id);
            if ($order) {
                $incomplete = (int)Db::name('order_dispatch')->where('order_id', $order->id)
                    ->where('status', '<>', OrderDispatch::STATUS_RECEIVED)
                    ->where('status', '<>', OrderDispatch::STATUS_REJECTED)
                    ->count();
                if ($incomplete === 0) {
                    $order->status = Order::STATUS_RECEIVED;
                    $order->save();
                    $this->orderService->logStatus($order->id, $order->status, '所有派单已入库，订单完成', $adminId, $dispatch->id);
                }

                // 同步货品状态和订单送货状态
                Order::syncAllItemStatuses($order->id);
            }

            return $dispatch->refresh();
        });
    }
}
