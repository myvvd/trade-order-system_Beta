<?php

namespace app\model;

use think\facade\Db;

class Order extends \think\Model
{
    protected $name = 'order';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;

    // 订单状态常量（保留兼容）
    public const STATUS_PENDING = 10; // 待派单
    public const STATUS_WAIT_CONFIRM = 20; // 待确认
    public const STATUS_REJECTED = 21; // 已拒绝
    public const STATUS_IN_PROGRESS = 30; // 制作中
    public const STATUS_WAIT_RECEIVE = 40; // 待收货
    public const STATUS_RECEIVED = 50; // 已入库/已收货
    public const STATUS_SHIPPED = 60; // 已发货
    public const STATUS_COMPLETED = 70; // 已完成
    public const STATUS_CANCELED = 99; // 已取消

    // 送货状态
    public const DELIVERY_NONE    = 0; // 未送货
    public const DELIVERY_PARTIAL = 1; // 部分送货
    public const DELIVERY_ALL     = 2; // 全部送货

    // 收款状态
    public const PAYMENT_NONE    = 0; // 未收款
    public const PAYMENT_PARTIAL = 1; // 部分收款
    public const PAYMENT_ALL     = 2; // 全部收款

    // 关联: 采购方
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    // 关联: 明细（排除逻辑删除的）
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id')->where('is_deleted', 0);
    }

    // 关联: 派单
    public function dispatches()
    {
        return $this->hasMany(OrderDispatch::class, 'order_id');
    }

    // 关联: 状态日志
    public function statusLogs()
    {
        return $this->hasMany(OrderStatusLog::class, 'order_id')->order('id', 'asc');
    }

    // 关联: 附件
    public function attachments()
    {
        return $this->hasMany(OrderAttachment::class, 'order_id');
    }

    // 关联: 创建人
    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'creator_id');
    }

    // 获取状态文本
    public function getStatusTextAttr($value, $data)
    {
        return self::getStatusText($data['status'] ?? null);
    }

    public static function getStatusText($status = null): string
    {
        $map = [
            self::STATUS_PENDING => '待派单',
            self::STATUS_WAIT_CONFIRM => '待确认',
            self::STATUS_REJECTED => '已拒绝',
            self::STATUS_IN_PROGRESS => '制作中',
            self::STATUS_WAIT_RECEIVE => '待收货',
            self::STATUS_RECEIVED => '已入库',
            self::STATUS_SHIPPED => '已发货',
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_CANCELED => '已取消',
        ];

        return $map[$status] ?? '';
    }

    // 生成订单号
    public static function generateOrderNo(): string
    {
        return 'O' . date('YmdHis') . substr(uniqid(), -6);
    }

    /**
     * 获取送货状态文本
     */
    public static function getDeliveryStatusText($status = null): string
    {
        $map = [
            self::DELIVERY_NONE    => '未送货',
            self::DELIVERY_PARTIAL => '部分送货',
            self::DELIVERY_ALL     => '全部送货',
        ];
        return $map[$status] ?? '未知';
    }

    /**
     * 获取收款状态文本
     */
    public static function getPaymentStatusText($status = null): string
    {
        $map = [
            self::PAYMENT_NONE    => '未收款',
            self::PAYMENT_PARTIAL => '部分收款',
            self::PAYMENT_ALL     => '全部收款',
        ];
        return $map[$status] ?? '未知';
    }

    /**
     * 获取送货状态颜色
     */
    public static function getDeliveryStatusColor($status = null): string
    {
        $map = [
            self::DELIVERY_NONE    => 'arcoblue',
            self::DELIVERY_PARTIAL => 'orange',
            self::DELIVERY_ALL     => 'green',
        ];
        return $map[$status] ?? '';
    }

    /**
     * 获取收款状态颜色
     */
    public static function getPaymentStatusColor($status = null): string
    {
        $map = [
            self::PAYMENT_NONE    => 'red',
            self::PAYMENT_PARTIAL => 'orange',
            self::PAYMENT_ALL     => 'green',
        ];
        return $map[$status] ?? '';
    }

    /**
     * 根据所有货品的 item_status 同步订单的 delivery_status
     * 送货 = item_status >= 60 (已发货)
     */
    public static function syncDeliveryStatus(int $orderId): void
    {
        $order = self::find($orderId);
        if (!$order) return;

        $items = OrderItem::where('order_id', $orderId)
            ->where('is_deleted', 0)
            ->select();

        if ($items->isEmpty()) return;

        $totalItems = $items->count();
        $shippedItems = 0;
        foreach ($items as $item) {
            if ((int)$item->item_status >= OrderItem::STATUS_SHIPPED) {
                $shippedItems++;
            }
        }

        if ($shippedItems == 0) {
            $order->delivery_status = self::DELIVERY_NONE;
        } elseif ($shippedItems < $totalItems) {
            $order->delivery_status = self::DELIVERY_PARTIAL;
        } else {
            $order->delivery_status = self::DELIVERY_ALL;
        }
        $order->save();
    }

    /**
     * 同步订单下所有货品的状态，并更新订单送货状态
     */
    public static function syncAllItemStatuses(int $orderId): void
    {
        $items = OrderItem::where('order_id', $orderId)
            ->where('is_deleted', 0)
            ->select();

        foreach ($items as $item) {
            OrderItem::syncItemStatus($item->id);
        }

        self::syncDeliveryStatus($orderId);
    }
}
