<?php

namespace app\model;

use think\facade\Db;

class Order extends \think\Model
{
    protected $name = 'order';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;

    // 状态常量
    public const STATUS_PENDING = 10; // 待派单
    public const STATUS_WAIT_CONFIRM = 20; // 待确认
    public const STATUS_REJECTED = 21; // 已拒绝
    public const STATUS_IN_PROGRESS = 30; // 制作中
    public const STATUS_WAIT_RECEIVE = 40; // 待收货
    public const STATUS_RECEIVED = 50; // 已入库/已收货
    public const STATUS_SHIPPED = 60; // 已发货
    public const STATUS_COMPLETED = 70; // 已完成
    public const STATUS_CANCELED = 99; // 已取消

    // 关联: 客户
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    // 关联: 明细
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
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
}
