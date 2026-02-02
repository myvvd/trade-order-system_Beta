<?php
// 中间件配置
return [
    // 别名或分组
    'alias'    => [
        // 管理端登录验证中间件
        'AdminAuth'    => app\middleware\AdminAuth::class,
        // 供应商端登录验证中间件
        'SupplierAuth' => app\middleware\SupplierAuth::class,
        // 操作日志记录中间件
        'OperationLog' => app\middleware\OperationLog::class,
        // 权限验证中间件
        'Permission'   => app\middleware\Permission::class,
    ],
    // 优先级设置，此数组中的中间件会按照数组中的顺序优先执行
    'priority' => [],
];
