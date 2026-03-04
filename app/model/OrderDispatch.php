<?php

namespace app\model;

class OrderDispatch extends \think\Model
{
    protected $name = 'order_dispatch';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;

    // 状态常量
    public const STATUS_WAIT_CONFIRM = 20;
    public const STATUS_REJECTED = 21;
    public const STATUS_IN_PROGRESS = 30;
    public const STATUS_WAIT_RECEIVE = 40;
    public const STATUS_RECEIVED = 50;

    public static function generateDispatchNo(): string
    {
        return 'DP' . date('YmdHis') . substr(uniqid(), -4);
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function items()
    {
        return $this->hasMany(OrderDispatchItem::class, 'dispatch_id');
    }

    public function getStatusTextAttr($value, $data)
    {
        return self::getStatusText($data['status'] ?? null);
    }

    public static function getStatusText($status = null): string
    {
        $map = [
            self::STATUS_WAIT_CONFIRM => '待确认',
            self::STATUS_REJECTED => '已拒绝',
            self::STATUS_IN_PROGRESS => '制作中',
            self::STATUS_WAIT_RECEIVE => '待收货',
            self::STATUS_RECEIVED => '已收货',
        ];

        return $map[$status] ?? '';
    }
}
