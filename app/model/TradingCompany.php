<?php

namespace app\model;

/**
 * 贸易公司模型
 */
class TradingCompany extends \think\Model
{
    protected $name = 'trading_company';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;
    protected $hidden = ['delete_time'];
    protected $type = ['status' => 'integer'];

    /**
     * 关联业务员
     */
    public function salesmen()
    {
        return $this->hasMany(Salesman::class, 'trading_company_id')
            ->where('delete_time', null);
    }
}
