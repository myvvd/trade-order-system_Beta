<?php

namespace app\model;

use think\facade\Db;

/**
 * 供应商模型
 */
class Supplier extends \think\Model
{
    // 表名
    protected $name = 'supplier';

    // 主键
    protected $pk = 'id';

    // 自动写入时间戳
    protected $autoWriteTimestamp = true;

    // 隐藏字段（JSON输出时自动隐藏）
    protected $hidden = ['login_password', 'delete_time'];

    // 字段类型转换
    protected $type = [
        'status' => 'integer',
    ];

    /**
     * 密码修改器（自动加密）
     * @param mixed $value
     * @return string
     */
    public function setLoginPasswordAttr($value): string
    {
        // 如果已经是 hash 格式，不再加密
        if (password_get_info($value)['algo']) {
            return $value;
        }
        // 使用 password_hash 加密
        return password_hash($value, PASSWORD_DEFAULT);
    }

    /**
     * 验证密码
     * @param string $password 明文密码
     * @return bool
     */
    public function checkPassword(string $password): bool
    {
        return password_verify($password, $this->login_password);
    }

    /**
     * 根据登录账号获取供应商
     * @param string $account
     * @return static|null
     */
    public static function getByAccount(string $account): ?Supplier
    {
        return self::where('login_account', $account)
            ->where('delete_time', null)
            ->find();
    }

    /**
     * 检查账号状态
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status == 1;
    }

    /**
     * 记录登录信息
     * @param string $ip
     * @return bool
     */
    public function recordLogin(string $ip = ''): bool
    {
        return $this->save([
            'last_login_time' => date('Y-m-d H:i:s'),
            'last_login_ip'   => $ip,
        ]);
    }

    /**
     * 获取供应商分类信息
     * @return array|null
     */
    public function getCategory(): ?array
    {
        if (!$this->category_id) return null;

        return \app\model\SupplierCategory::find($this->category_id)?->toArray() ?? null;
    }

    /**
     * 关联：派单记录
     * @return \think\Model
     */
    public function dispatches()
    {
        return $this->hasMany(\app\model\OrderDispatch::class, 'supplier_id');
    }

    /**
     * 关联：分类（belongsTo）
     * @return \think\Model
     */
    public function category()
    {
        return $this->belongsTo(\app\model\SupplierCategory::class, 'category_id');
    }

    /**
     * 获取待处理的订单数量
     * @return int
     */
    public function getPendingOrderCount(): int
    {
        return Db::name('order_dispatch')
            ->where('supplier_id', $this->id)
            ->whereIn('status', [20, 30]) // 待确认、制作中
            ->count();
    }

    /**
     * 获取今日订单数量
     * @return int
     */
    public function getTodayOrderCount(): int
    {
        return Db::name('order_dispatch')
            ->where('supplier_id', $this->id)
            ->whereDay('create_time', date('Y-m-d'))
            ->count();
    }

    /**
     * 软删除
     * @return bool
     */
    public function softDelete(): bool
    {
        return $this->save(['delete_time' => date('Y-m-d H:i:s')]);
    }

    /**
     * 搜索器 - 按状态搜索
     * @param \think\query $query
     * @param mixed $value
     * @return void
     */
    public function searchStatusAttr($query, $value): void
    {
        if ($value !== '' && $value !== null) {
            $query->where('status', $value);
        }
    }

    /**
     * 搜索器 - 按关键词搜索
     * @param \think\query $query
     * @param mixed $value
     * @return void
     */
    public function searchKeywordAttr($query, $value): void
    {
        if ($value) {
            $query->whereLike('name|contact|phone|email', '%' . $value . '%');
        }
    }

    /**
     * 搜索器 - 按登录账号搜索
     * @param \think\query $query
     * @param mixed $value
     * @return void
     */
    public function searchLoginAccountAttr($query, $value): void
    {
        if ($value) {
            $query->where('login_account', $value);
        }
    }
}
