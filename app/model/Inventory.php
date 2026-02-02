<?php
namespace app\model;

use think\Model;

class Inventory extends Model
{
    protected $name = 'inventory';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;

    public function items()
    {
        return $this->hasMany(InventoryItem::class, 'inventory_id');
    }

    public function operator()
    {
        return $this->belongsTo(AdminUser::class, 'operator_id');
    }
}
