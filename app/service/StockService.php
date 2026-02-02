<?php

namespace app\service;

use think\facade\Db;

class StockService
{
    /**
     * 入库
     * @param int $goodsId
     * @param int|null $skuId
     * @param int $quantity
     * @param string $relatedType
     * @param int $relatedId
     * @param string $remark
     */
    public function inStock(int $goodsId, ?int $skuId, int $quantity, string $relatedType, int $relatedId, string $remark = ''): void
    {
        if ($quantity <= 0) return;
        $where = ['goods_id' => $goodsId, 'sku_id' => $skuId];

        // 为避免并发插入/重复的问题，使用 MySQL 会话锁序列化对同一 goods+sku 的变更。
        // 使用 GET_LOCK 来保证同一时刻只有一个进程在修改同一库存记录。
        $lockKey = 'stock_' . $goodsId . '_' . ($skuId ?? 0);

        Db::transaction(function () use ($where, $goodsId, $skuId, $quantity, $relatedType, $relatedId, $remark, $lockKey) {
            // 获取命名锁，超时 5 秒
            Db::query('SELECT GET_LOCK(?, 5)', [$lockKey]);
            try {
                $exists = Db::name('stock')->where($where)->find();
                if ($exists) {
                    Db::name('stock')->where($where)->inc('quantity', $quantity)->update();
                } else {
                    Db::name('stock')->insert(array_merge($where, ['quantity' => $quantity, 'create_time' => date('Y-m-d H:i:s')]));
                }

                $this->logChange($goodsId, $skuId, $quantity, 'in', $relatedType, $relatedId, $remark);
            } finally {
                // 释放锁
                Db::query('SELECT RELEASE_LOCK(?)', [$lockKey]);
            }
        });
    }

    /**
     * 出库
     */
    public function outStock(int $goodsId, ?int $skuId, int $quantity, string $relatedType, int $relatedId, string $remark = ''): void
    {
        if ($quantity <= 0) return;

        $where = ['goods_id' => $goodsId, 'sku_id' => $skuId];
        $row = Db::name('stock')->where($where)->lock(true)->find();
        $cur = (int)($row['quantity'] ?? 0);
        if ($cur < $quantity) {
            throw new \RuntimeException('库存不足');
        }

        Db::name('stock')->where($where)->dec('quantity', $quantity)->update();
        $this->logChange($goodsId, $skuId, -$quantity, 'out', $relatedType, $relatedId, $remark);
    }

    /**
     * 查询库存
     */
    public function getStock(int $goodsId, ?int $skuId): int
    {
        $row = Db::name('stock')->where(['goods_id' => $goodsId, 'sku_id' => $skuId])->find();
        return (int)($row['quantity'] ?? 0);
    }

    /**
     * 记录变动日志
     */
    public function logChange(int $goodsId, ?int $skuId, int $change, string $type, string $relatedType, int $relatedId, string $remark = ''): void
    {
        Db::name('stock_log')->insert([
            'goods_id' => $goodsId,
            'sku_id' => $skuId,
            'change' => $change,
            'type' => $type,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'remark' => $remark,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }
}
