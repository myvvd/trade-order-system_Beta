<?php

namespace app\model;

/**
 * 业务员模型
 */
class Salesman extends \think\Model
{
    protected $name = 'salesman';
    protected $pk = 'id';
    protected $autoWriteTimestamp = true;
    protected $hidden = ['delete_time'];
    protected $type = ['status' => 'integer'];

    /**
     * 关联贸易公司
     */
    public function tradingCompany()
    {
        return $this->belongsTo(TradingCompany::class, 'trading_company_id');
    }
}
