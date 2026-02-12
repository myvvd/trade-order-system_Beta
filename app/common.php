<?php
// 应用公共文件

/**
 * 获取订单状态颜色
 * @param int $status
 * @return string
 */
function order_status_color($status)
{
    $colors = [
        10 => 'primary',    // 待派单
        20 => 'warning',    // 待确认  
        21 => 'danger',     // 已拒绝
        30 => 'info',       // 制作中
        40 => 'secondary',  // 待收货
        50 => 'success',    // 已入库
        60 => 'warning',    // 已发货
        70 => 'success',    // 已完成
        99 => 'secondary'   // 已取消
    ];
    
    return $colors[$status] ?? 'secondary';
}
