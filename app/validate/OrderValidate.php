<?php

namespace app\validate;

use think\Validate;

class OrderValidate extends Validate
{
    protected $rule = [
        'customer_id' => 'require|integer',
        'items' => 'require|array',
        'items.*.goods_name' => 'require',
        'items.*.quantity' => 'require|integer|gt:0',
        'items.*.price' => 'require|float',
    ];

    protected $message = [
        'customer_id.require' => '请选择采购方',
        'items.require' => '请添加货品明细',
        'items.*.goods_name.require' => '请输入货品名称',
        'items.*.quantity.require' => '请输入数量',
        'items.*.quantity.gt' => '数量必须大于0',
        'items.*.price.require' => '请输入单价',
    ];

    public function sceneCreate()
    {
        return $this->only(['customer_id', 'items', 'items.*.goods_name', 'items.*.quantity', 'items.*.price']);
    }

    public function sceneUpdate()
    {
        return $this->only(['customer_id', 'items', 'items.*.goods_name', 'items.*.quantity', 'items.*.price']);
    }
}
