<?php

/**
 * 系统权限定义
 * 返回权限数组，格式为：模块 => [标题, actions]
 */
return [
    // ==================== 订单模块 ====================
    'order' => [
        'title' => '订单管理',
        'actions' => [
            'order.list'      => '查看订单',
            'order.create'    => '创建订单',
            'order.edit'      => '编辑订单',
            'order.delete'    => '删除订单',
            'order.dispatch'   => '派单',
            'order.confirm'    => '确认订单',
            'order.receive'    => '收货',
            'order.ship'      => '发货',
            'order.complete'   => '完成订单',
            'order.cancel'    => '取消订单',
        ],
    ],

    // ==================== 客户模块 ====================
    'customer' => [
        'title' => '客户管理',
        'actions' => [
            'customer.list'   => '查看客户',
            'customer.create' => '新增客户',
            'customer.edit'   => '编辑客户',
            'customer.delete' => '删除客户',
        ],
    ],

    // ==================== 供应商模块 ====================
    'supplier' => [
        'title' => '供应商管理',
        'actions' => [
            'supplier.list'   => '查看供应商',
            'supplier.create' => '新增供应商',
            'supplier.edit'   => '编辑供应商',
            'supplier.delete' => '删除供应商',
            'supplier.status' => '修改供应商状态',
        ],
    ],

    // ==================== 货品模块 ====================
    'goods' => [
        'title' => '货品管理',
        'actions' => [
            'goods.list'       => '查看货品',
            'goods.create'     => '新增货品',
            'goods.edit'       => '编辑货品',
            'goods.delete'     => '删除货品',
            'goods.category'   => '管理货品分类',
            'goods.sku'        => '管理货品规格',
        ],
    ],

    // ==================== 库存模块 ====================
    'inventory' => [
        'title' => '库存管理',
        'actions' => [
            'stock.list'      => '查看库存',
            'stock.in'        => '入库',
            'stock.out'       => '出库',
            'inventory.list'  => '盘点列表',
            'inventory.create'=> '创建盘点',
            'inventory.check'  => '完成盘点',
        ],
    ],

    // ==================== 发货模块 ====================
    'shipping' => [
        'title' => '发货管理',
        'actions' => [
            'shipping.list'   => '查看出货单',
            'shipping.create' => '创建出货单',
            'shipping.edit'   => '编辑出货单',
            'shipping.delete' => '删除出货单',
            'shipping.print'  => '打印出货单',
        ],
    ],

    // ==================== 员工管理模块 ====================
    'employee' => [
        'title' => '员工管理',
        'actions' => [
            'employee.list'   => '查看员工',
            'employee.create' => '新增员工',
            'employee.edit'   => '编辑员工',
            'employee.delete' => '删除员工',
            'employee.status'=> '修改员工状态',
        ],
    ],

    // ==================== 角色管理模块 ====================
    'role' => [
        'title' => '角色管理',
        'actions' => [
            'role.list'   => '查看角色',
            'role.create' => '创建角色',
            'role.edit'   => '编辑角色',
            'role.delete' => '删除角色',
            'role.perm'   => '分配权限',
        ],
    ],

    // ==================== 系统设置模块 ====================
    'system' => [
        'title' => '系统设置',
        'actions' => [
            'system.basic'    => '基本设置',
            'system.config'   => '配置管理',
            'system.log'      => '查看日志',
            'system.backup'   => '数据备份',
        ],
    ],
];
