<?php

namespace app\model;

use think\Model;

/**
 * 站内信消息模型
 */
class Message extends Model
{
    protected $name = 'message';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;

    // 消息分类常量
    const CATEGORY_ORDER_CREATE   = 'order_create';
    const CATEGORY_ORDER_EDIT     = 'order_edit';
    const CATEGORY_ORDER_DISPATCH = 'order_dispatch';
    const CATEGORY_ORDER_CONFIRM  = 'order_confirm';
    const CATEGORY_ORDER_REJECT   = 'order_reject';
    const CATEGORY_ORDER_PROGRESS = 'order_progress';
    const CATEGORY_ORDER_SHIP     = 'order_ship';
    const CATEGORY_ORDER_RECEIVE  = 'order_receive';
    const CATEGORY_ORDER_CANCEL   = 'order_cancel';
    const CATEGORY_SHIPPING       = 'shipping';
    const CATEGORY_SYSTEM         = 'system';
    const CATEGORY_SECURITY       = 'security';

    // 接收者类型
    const RECEIVER_ADMIN    = 'admin';
    const RECEIVER_SUPPLIER = 'supplier';

    // 发送者类型
    const SENDER_SYSTEM   = 'system';
    const SENDER_ADMIN    = 'admin';
    const SENDER_SUPPLIER = 'supplier';

    /**
     * 关联发送者（管理员）
     */
    public function senderAdmin()
    {
        return $this->belongsTo(AdminUser::class, 'sender_id');
    }

    /**
     * 关联发送者（供应商）
     */
    public function senderSupplier()
    {
        return $this->belongsTo(Supplier::class, 'sender_id');
    }

    /**
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * 关联派单
     */
    public function dispatch()
    {
        return $this->belongsTo(OrderDispatch::class, 'dispatch_id');
    }

    /**
     * 分类标签映射
     */
    public static function getCategoryLabel(string $category): string
    {
        $map = [
            self::CATEGORY_ORDER_CREATE   => '订单创建',
            self::CATEGORY_ORDER_EDIT     => '订单修改',
            self::CATEGORY_ORDER_DISPATCH => '订单派单',
            self::CATEGORY_ORDER_CONFIRM  => '订单确认',
            self::CATEGORY_ORDER_REJECT   => '订单拒绝',
            self::CATEGORY_ORDER_PROGRESS => '制作进度',
            self::CATEGORY_ORDER_SHIP     => '订单发货',
            self::CATEGORY_ORDER_RECEIVE  => '订单入库',
            self::CATEGORY_ORDER_CANCEL   => '订单取消',
            self::CATEGORY_SHIPPING       => '出货通知',
            self::CATEGORY_SYSTEM         => '系统通知',
            self::CATEGORY_SECURITY       => '安全提醒',
        ];
        return $map[$category] ?? '系统通知';
    }
}
