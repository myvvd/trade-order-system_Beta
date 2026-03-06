<?php

namespace app\model;

use think\facade\Db;

class OrderItem extends \think\Model
{
    protected $name = 'order_item';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;

    // 货品状态常量
    public const STATUS_PENDING      = 10; // 待派单
    public const STATUS_DISPATCHED   = 20; // 已派单(待确认)
    public const STATUS_IN_PROGRESS  = 30; // 制作中
    public const STATUS_WAIT_RECEIVE = 40; // 待收货
    public const STATUS_RECEIVED     = 50; // 已入库
    public const STATUS_SHIPPED      = 60; // 已发货
    public const STATUS_COMPLETED    = 70; // 已完成

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function goods()
    {
        return $this->belongsTo(Goods::class, 'goods_id');
    }

    public function sku()
    {
        return $this->belongsTo(GoodsSku::class, 'sku_id');
    }

    /**
     * 获取状态文本
     */
    public static function getStatusText($status = null): string
    {
        $map = [
            self::STATUS_PENDING      => '待派单',
            self::STATUS_DISPATCHED   => '已派单',
            self::STATUS_IN_PROGRESS  => '制作中',
            self::STATUS_WAIT_RECEIVE => '待收货',
            self::STATUS_RECEIVED     => '已入库',
            self::STATUS_SHIPPED      => '已发货',
            self::STATUS_COMPLETED    => '已完成',
        ];
        return $map[$status] ?? '未知';
    }

    /**
     * 获取状态颜色 (Arco Design tag color)
     */
    public static function getStatusColor($status = null): string
    {
        $map = [
            self::STATUS_PENDING      => 'arcoblue',
            self::STATUS_DISPATCHED   => 'orange',
            self::STATUS_IN_PROGRESS  => 'cyan',
            self::STATUS_WAIT_RECEIVE => 'purple',
            self::STATUS_RECEIVED     => 'green',
            self::STATUS_SHIPPED      => 'gold',
            self::STATUS_COMPLETED    => 'green',
        ];
        return $map[$status] ?? '';
    }

    /**
     * 根据派单状态同步此货品的 item_status
     * 逻辑：
     *   - 如果该货品的总订单数量 > 已派数量 → 10 (待派单)
     *   - 如果已全部派出，取所有关联派单的最低状态作为货品状态
     */
    public static function syncItemStatus(int $itemId): void
    {
        $item = self::find($itemId);
        if (!$item) return;

        // 已派总量（排除已拒绝的派单）
        $dispatched = (int)Db::name('order_dispatch_item')
            ->alias('di')
            ->join('order_dispatch d', 'di.dispatch_id = d.id')
            ->where('di.order_item_id', $itemId)
            ->where('d.status', '<>', OrderDispatch::STATUS_REJECTED)
            ->sum('di.quantity') ?: 0;

        if ($dispatched < (int)$item->quantity) {
            // 尚有未派数量
            if ($dispatched > 0) {
                // 部分派出：取已派部分的最低状态，但不低于10
                $minDispatchStatus = (int)Db::name('order_dispatch_item')
                    ->alias('di')
                    ->join('order_dispatch d', 'di.dispatch_id = d.id')
                    ->where('di.order_item_id', $itemId)
                    ->where('d.status', '<>', OrderDispatch::STATUS_REJECTED)
                    ->min('d.status') ?: 10;
                // 部分派出时保持待派单状态
                $item->item_status = self::STATUS_PENDING;
            } else {
                $item->item_status = self::STATUS_PENDING;
            }
        } else {
            // 全部派出：取所有关联派单的最低状态
            $minDispatchStatus = (int)Db::name('order_dispatch_item')
                ->alias('di')
                ->join('order_dispatch d', 'di.dispatch_id = d.id')
                ->where('di.order_item_id', $itemId)
                ->where('d.status', '<>', OrderDispatch::STATUS_REJECTED)
                ->min('d.status') ?: 20;

            // 派单状态(20/30/40/50) 直接映射到货品状态
            $item->item_status = $minDispatchStatus;
        }

        $item->save();
    }
}
