<?php

namespace app\validate;

use think\Validate;

class OrderValidate extends Validate
{
    protected $rule = [
        'customer_id' => 'require|integer',
        'items' => 'require|array',
        'items.*.goods_id' => 'require|integer',
        'items.*.quantity' => 'require|integer|gt:0',
        'items.*.price' => 'require|float',
    ];

    protected $message = [
        'customer_id.require' => '请选择客户',
        'items.require' => '请添加货品明细',
        'items.*.goods_id.require' => '请选择货品',
        'items.*.quantity.require' => '请输入数量',
        'items.*.quantity.gt' => '数量必须大于0',
        'items.*.price.require' => '请输入单价',
    ];

    public function sceneCreate()
    {
        return $this->only(['customer_id', 'items', 'items.*.goods_id', 'items.*.quantity', 'items.*.price']);
    }

    public function sceneUpdate()
    {
        return $this->only(['customer_id', 'items', 'items.*.goods_id', 'items.*.quantity', 'items.*.price']);
    }
}
