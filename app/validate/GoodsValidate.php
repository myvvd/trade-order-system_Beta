<?php

namespace app\validate;

use think\Validate;

/**
 * 货品验证器
 */
class GoodsValidate extends Validate
{
    // 验证规则
    protected $rule = [
        // 货品主表
        'id'           => 'integer',
        'goods_no'      => 'require|length:1,50',
        'name'          => 'require|length:1,200',
        'category_id'   => 'integer',
        'unit'          => 'length:1,20',
        'cost_price'    => 'float|egt:0',
        'sale_price'    => 'float|egt:0',
        'image'         => 'max:500',
        'description'   => 'max:1000',
        'status'        => 'in:0,1',

        // 分类表
        'category_id'   => 'integer',
        'name'          => 'require|length:1,50',
        'parent_id'     => 'integer',
        'sort'          => 'integer|egt:0',

        // SKU 表
        'sku_id'       => 'integer',
        'sku_no'        => 'require|length:1,50',
        'sku_name'      => 'max:100',
        'cost_price'    => 'float|egt:0',
        'sale_price'    => 'float|egt:0',
        'status'        => 'in:0,1',
    ];

    // 验证消息
    protected $message = [
        // 货品主表
        'id.integer'           => '货品ID格式错误',
        'goods_no.require'      => '货品编号不能为空',
        'goods_no.length'       => '货品编号长度为1-50个字符',
        'name.require'          => '货品名称不能为空',
        'name.length'           => '货品名称长度为1-200个字符',
        'category_id.integer'   => '分类ID格式错误',
        'unit.length'           => '单位长度不能超过20个字符',
        'cost_price.float'      => '成本价格式错误',
        'cost_price.egt'       => '成本价必须大于等于0',
        'sale_price.float'      => '销售价格式错误',
        'sale_price.egt'       => '销售价必须大于等于0',
        'image.max'            => '图片路径不能超过500个字符',
        'description.max'       => '描述长度不能超过1000个字符',
        'status.in'            => '状态值错误',

        // 分类表
        'name.require'          => '分类名称不能为空',
        'name.length'           => '分类名称长度为1-50个字符',
        'parent_id.integer'     => '父级分类ID格式错误',
        'sort.integer'          => '排序格式错误',
        'sort.egt'             => '排序必须大于等于0',

        // SKU 表
        'sku_id.integer'        => 'SKU ID格式错误',
        'sku_no.require'        => 'SKU编号不能为空',
        'sku_no.length'         => 'SKU编号长度为1-50个字符',
        'sku_name.max'         => '规格名称不能超过100个字符',
        'status.in'            => '状态值错误',
    ];

    // 验证场景
    protected $scene = [
        // 货品场景
        'goods_create' => ['goods_no', 'name', 'category_id', 'unit', 'cost_price', 'sale_price'],
        'goods_update' => ['id', 'goods_no', 'name', 'category_id', 'unit', 'cost_price', 'sale_price', 'image', 'description', 'status'],
        'goods_delete' => ['id'],

        // 分类场景
        'category_create' => ['name', 'parent_id', 'sort'],
        'category_update' => ['category_id', 'name', 'parent_id', 'sort'],
        'category_delete' => ['category_id'],

        // SKU 场景
        'sku_create' => ['sku_no', 'sku_name', 'cost_price', 'sale_price'],
        'sku_update' => ['sku_id', 'sku_no', 'sku_name', 'cost_price', 'sale_price', 'status'],
        'sku_delete' => ['sku_id'],
    ];

    /**
     * 自定义验证 - 检查货品编号是否唯一
     * @param mixed $value
     * @param mixed $rule
     * @param array $data
     * @param string $field
     * @return bool|string
     */
    public function checkGoodsNoUnique($value, $rule, $data = [], $field = '')
    {
        $id = $data['id'] ?? 0;
        $count = \app\model\Goods::where('goods_no', $value)
            ->where('id', '<>', $id)
            ->where('delete_time', null)
            ->count();

        if ($count > 0) {
            return '货品编号已存在';
        }

        return true;
    }

    /**
     * 自定义验证 - 检查 SKU 编号是否唯一
     * @param mixed $value
     * @param mixed $rule
     * @param array $data
     * @param string $field
     * @return bool|string
     */
    public function checkSkuNoUnique($value, $rule, $data = [], $field = '')
    {
        $skuId = $data['sku_id'] ?? 0;
        $goodsId = $data['goods_id'] ?? 0;
        $count = \app\model\GoodsSku::where('sku_no', $value)
            ->where('id', '<>', $skuId)
            ->where('goods_id', $goodsId)
            ->count();

        if ($count > 0) {
            return 'SKU编号已存在';
        }

        return true;
    }

    /**
     * 自定义验证 - 检查分类是否存在（用于删除和更新）
     * @param mixed $value
     * @param mixed $rule
     * @param array $data
     * @param string $field
     * @return bool|string
     */
    public function checkCategoryExists($value, $rule, $data = [], $field = '')
    {
        $category = \app\model\GoodsCategory::find($value);
        if (!$category) {
            return '分类不存在';
        }

        return true;
    }

    /**
     * 自定义验证 - 检查父级分类不能是自身
     * @param mixed $value
     * @param mixed $rule
     * @param array $data
     * @param string $field
     * @return bool|string
     */
    public function checkParentNotSelf($value, $rule, $data = [], $field = '')
    {
        $categoryId = $data['category_id'] ?? 0;
        if ($value == $categoryId && $value != 0) {
            return '父级分类不能是自身';
        }

        return true;
    }
}
