<?php

namespace app\model;

class OrderStatusLog extends \think\Model
{
    protected $name = 'order_status_log';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function dispatch()
    {
        return $this->belongsTo(OrderDispatch::class, 'dispatch_id');
    }

    public function admin()
    {
        return $this->belongsTo(\app\model\AdminUser::class, 'admin_id');
    }
}
