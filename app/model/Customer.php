<?php

namespace app\model;

use think\facade\Db;

/**
 * 客户模型
 */
class Customer extends \think\Model
{
    // 表名
    protected $name = 'customer';

    // 主键
    protected $pk = 'id';

    // 自动写入时间戳
    protected $autoWriteTimestamp = true;

    // 隐藏字段
    protected $hidden = ['delete_time'];

    // 字段类型转换
    protected $type = [
        'level'  => 'integer',
        'status' => 'integer',
    ];

    /**
     * 获取历史订单（数组）
     * @return array
     */
    public function getOrders(): array
    {
        if (!$this->id) return [];

        return Db::name('order')
            ->where('customer_id', $this->id)
            ->order('id', 'desc')
            ->select()
            ->toArray();
    }

    /**
     * 获取订单统计信息
     * @return array
     */
    public function getOrderStats(): array
    {
        if (!$this->id) return ['total_orders' => 0, 'total_amount' => 0.00];

        $res = Db::name('order')
            ->field(['COUNT(*) as total_orders', 'IFNULL(SUM(total_amount),0) as total_amount'])
            ->where('customer_id', $this->id)
            ->find();

        return [
            'total_orders' => (int)($res['total_orders'] ?? 0),
            'total_amount' => (float)($res['total_amount'] ?? 0.00),
        ];
    }

    /**
     * 根据公司名称获取（忽略软删除）
     * @param string $companyName
     * @return Customer|null
     */
    public static function getByCompanyName(string $companyName): ?Customer
    {
        return self::where('company_name', $companyName)
            ->where('delete_time', null)
            ->find();
    }

    /**
     * 软删除
     * @return bool
     */
    public function softDelete(): bool
    {
        return $this->save(['delete_time' => date('Y-m-d H:i:s')]);
    }

    // ================= 搜索器 ==================
    public function searchCompanyNameAttr($query, $value): void
    {
        if ($value) {
            $query->whereLike('company_name', '%' . $value . '%');
        }
    }

    public function searchContactNameAttr($query, $value): void
    {
        if ($value) {
            $query->whereLike('contact_name', '%' . $value . '%');
        }
    }

    public function searchCountryAttr($query, $value): void
    {
        if ($value) {
            $query->where('country', $value);
        }
    }
}
