<?php
namespace app\model;

use think\Model;

class Shipping extends Model
{
    protected $name = 'shipping';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;

    public function items()
    {
        return $this->hasMany(ShippingItem::class, 'shipping_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'creator_id');
    }
}
