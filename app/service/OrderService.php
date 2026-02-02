<?php

namespace app\service;

use think\facade\Db;
use app\model\Order;
use app\model\OrderItem;
use app\model\OrderStatusLog;

class OrderService
{
    /**
     * 创建订单（包含明细），并写状态日志
     * @param array $data  包含 customer_id, items(array), total_amount, remark
     * @param int|null $adminId
     * @return Order
     */
    public function createOrder(array $data, ?int $adminId = null): Order
    {
        return Db::transaction(function () use ($data, $adminId) {
            $order = new Order();
            $order->save([
                'order_no' => Order::generateOrderNo(),
                'customer_id' => $data['customer_id'] ?? null,
                'status' => Order::STATUS_PENDING,
                'creator_id' => $adminId,
                'total_amount' => $data['total_amount'] ?? 0,
                'remark' => $data['remark'] ?? ''
            ]);

            $items = $data['items'] ?? [];
            foreach ($items as $it) {
                $oi = new OrderItem();
                $oi->save([
                    'order_id' => $order->id,
                    'goods_id' => $it['goods_id'] ?? null,
                    'sku_id' => $it['sku_id'] ?? null,
                    'quantity' => $it['quantity'] ?? 0,
                    'price' => $it['price'] ?? 0,
                    'remark' => $it['remark'] ?? ''
                ]);
            }

            $this->logStatus($order->id, $order->status, '创建订单', $adminId);

            return $order->refresh();
        });
    }

    /**
     * 取消订单
     */
    public function cancelOrder(int $orderId, ?int $adminId = null): bool
    {
        return Db::transaction(function () use ($orderId, $adminId) {
            $order = Order::find($orderId);
            if (!$order) {
                throw new \RuntimeException('订单不存在');
            }
            // 仅在非完成、非已取消状态允许取消
            if (in_array($order->status, [Order::STATUS_COMPLETED, Order::STATUS_CANCELED])) {
                throw new \RuntimeException('订单不能取消');
            }

            $order->status = Order::STATUS_CANCELED;
            $order->save();

            $this->logStatus($order->id, $order->status, '取消订单', $adminId);

            return true;
        });
    }

    /**
     * 写状态日志
     */
    public function logStatus(int $orderId, int $status, string $remark = '', ?int $adminId = null, ?int $dispatchId = null): OrderStatusLog
    {
        $log = new OrderStatusLog();
        $log->save([
            'order_id' => $orderId,
            'dispatch_id' => $dispatchId,
            'status' => $status,
            'remark' => $remark,
            'admin_id' => $adminId,
        ]);

        return $log->refresh();
    }
}
