<?php

namespace app\model;

use think\Model;

class OrderAttachment extends Model
{
    protected $name = 'order_attachment';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;

    // 关联订单
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
