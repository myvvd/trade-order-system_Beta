<?php

namespace app\model;

class OrderDispatchItem extends \think\Model
{
    protected $name = 'order_dispatch_item';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;

    public function dispatch()
    {
        return $this->belongsTo(OrderDispatch::class, 'dispatch_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
