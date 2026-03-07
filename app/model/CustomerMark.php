<?php

namespace app\model;

use think\Model;

class CustomerMark extends Model
{
    protected $table = 'customer_mark';
    protected $pk = 'id';
    protected $autoWriteTimestamp = 'datetime';

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
