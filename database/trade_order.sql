-- ============================================
-- 外贸订单管理系统数据库结构
-- 数据库：trade_order
-- 字符集：utf8mb4_general_ci
-- 引擎：InnoDB
-- ============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 系统模块
-- ============================================

-- --------------------------------------------
-- 1. admin_user - 管理员/员工表
-- --------------------------------------------
DROP TABLE IF EXISTS `admin_user`;
CREATE TABLE `admin_user` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `password` varchar(255) NOT NULL COMMENT '密码(password_hash加密)',
  `real_name` varchar(50) DEFAULT NULL COMMENT '真实姓名',
  `phone` varchar(20) DEFAULT NULL COMMENT '手机号',
  `email` varchar(100) DEFAULT NULL COMMENT '邮箱',
  `role_id` int(11) unsigned DEFAULT NULL COMMENT '角色ID 关联admin_role.id',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态 1正常 0禁用',
  `last_login_time` datetime DEFAULT NULL COMMENT '最后登录时间',
  `last_login_ip` varchar(50) DEFAULT NULL COMMENT '最后登录IP',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_status` (`status`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='管理员/员工表';

-- --------------------------------------------
-- 2. admin_role - 角色表
-- --------------------------------------------
DROP TABLE IF EXISTS `admin_role`;
CREATE TABLE `admin_role` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '角色ID',
  `name` varchar(50) NOT NULL COMMENT '角色名称',
  `description` varchar(255) DEFAULT NULL COMMENT '角色描述',
  `permissions` json DEFAULT NULL COMMENT '权限列表JSON格式',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态 1正常 0禁用',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='角色表';

-- --------------------------------------------
-- 3. operation_log - 操作日志表
-- --------------------------------------------
DROP TABLE IF EXISTS `operation_log`;
CREATE TABLE `operation_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `user_id` int(11) unsigned DEFAULT NULL COMMENT '操作人ID',
  `user_type` varchar(20) NOT NULL DEFAULT 'admin' COMMENT '用户类型 admin/supplier',
  `module` varchar(50) DEFAULT NULL COMMENT '模块名称',
  `action` varchar(50) DEFAULT NULL COMMENT '操作动作',
  `method` varchar(10) DEFAULT NULL COMMENT '请求方法 GET/POST',
  `url` varchar(500) DEFAULT NULL COMMENT '请求URL',
  `params` text COMMENT '请求参数',
  `ip` varchar(50) DEFAULT NULL COMMENT '客户端IP',
  `user_agent` varchar(500) DEFAULT NULL COMMENT '用户代理',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '操作时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_user_type` (`user_type`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='操作日志表';

-- ============================================
-- 订单模块
-- ============================================

-- --------------------------------------------
-- 4. order - 订单主表
-- --------------------------------------------
DROP TABLE IF EXISTS `order`;
CREATE TABLE `order` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '订单ID',
  `order_no` varchar(32) NOT NULL COMMENT '订单编号',
  `customer_id` int(11) unsigned DEFAULT NULL COMMENT '客户ID 关联customer.id',
  `status` tinyint(2) NOT NULL DEFAULT 10 COMMENT '订单状态 10待派单 20待确认 21已拒绝 30制作中 40待收货 50已入库 60已发货 70已完成 99已取消',
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '订单总金额',
  `currency` varchar(10) NOT NULL DEFAULT 'CNY' COMMENT '币种 CNY USD EUR',
  `delivery_date` date DEFAULT NULL COMMENT '交货日期',
  `remark` varchar(500) DEFAULT NULL COMMENT '备注',
  `creator_id` int(11) unsigned DEFAULT NULL COMMENT '创建人ID',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_status` (`status`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='订单主表';

-- --------------------------------------------
-- 5. order_item - 订单明细表
-- --------------------------------------------
DROP TABLE IF EXISTS `order_item`;
CREATE TABLE `order_item` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '明细ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID 关联order.id',
  `goods_id` int(11) unsigned DEFAULT NULL COMMENT '货品ID 关联goods.id',
  `goods_name` varchar(200) NOT NULL COMMENT '货品名称（快照）',
  `sku_id` int(11) unsigned DEFAULT NULL COMMENT '规格ID 关联goods_sku.id',
  `sku_name` varchar(100) DEFAULT NULL COMMENT '规格名称（快照）',
  `quantity` int(11) NOT NULL DEFAULT 0 COMMENT '数量',
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '单价',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '小计金额',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_goods_id` (`goods_id`),
  KEY `idx_sku_id` (`sku_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='订单明细表';

-- --------------------------------------------
-- 6. order_dispatch - 派单表
-- --------------------------------------------
DROP TABLE IF EXISTS `order_dispatch`;
CREATE TABLE `order_dispatch` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '派单ID',
  `dispatch_no` varchar(32) NOT NULL COMMENT '派单编号',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID 关联order.id',
  `supplier_id` int(11) unsigned NOT NULL COMMENT '供应商ID 关联supplier.id',
  `status` tinyint(2) NOT NULL DEFAULT 20 COMMENT '状态 20待确认 30制作中 40待收货 50已收货',
  `require_date` date DEFAULT NULL COMMENT '要求完成日期',
  `confirm_time` datetime DEFAULT NULL COMMENT '确认时间',
  `ship_time` datetime DEFAULT NULL COMMENT '发货时间',
  `receive_time` datetime DEFAULT NULL COMMENT '收货时间',
  `remark` varchar(500) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dispatch_no` (`dispatch_no`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_supplier_id` (`supplier_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='派单表';

-- --------------------------------------------
-- 7. order_dispatch_item - 派单明细表
-- --------------------------------------------
DROP TABLE IF EXISTS `order_dispatch_item`;
CREATE TABLE `order_dispatch_item` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '派单明细ID',
  `dispatch_id` bigint(20) unsigned NOT NULL COMMENT '派单ID 关联order_dispatch.id',
  `order_item_id` bigint(20) unsigned NOT NULL COMMENT '订单明细ID 关联order_item.id',
  `quantity` int(11) NOT NULL DEFAULT 0 COMMENT '派单数量',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  PRIMARY KEY (`id`),
  KEY `idx_dispatch_id` (`dispatch_id`),
  KEY `idx_order_item_id` (`order_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='派单明细表';

-- --------------------------------------------
-- 8. order_status_log - 订单状态日志表
-- --------------------------------------------
DROP TABLE IF EXISTS `order_status_log`;
CREATE TABLE `order_status_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `order_id` bigint(20) unsigned DEFAULT NULL COMMENT '订单ID 关联order.id',
  `dispatch_id` bigint(20) unsigned DEFAULT NULL COMMENT '派单ID 关联order_dispatch.id',
  `from_status` tinyint(2) DEFAULT NULL COMMENT '原状态',
  `to_status` tinyint(2) NOT NULL COMMENT '新状态',
  `operator_id` int(11) unsigned DEFAULT NULL COMMENT '操作人ID',
  `operator_type` varchar(20) DEFAULT 'admin' COMMENT '操作人类型 admin/supplier',
  `remark` varchar(500) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_dispatch_id` (`dispatch_id`),
  KEY `idx_operator_id` (`operator_id`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='订单状态日志表';

-- --------------------------------------------
-- 9. order_attachment - 订单附件表
-- --------------------------------------------
DROP TABLE IF EXISTS `order_attachment`;
CREATE TABLE `order_attachment` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '附件ID',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID 关联order.id',
  `file_name` varchar(255) NOT NULL COMMENT '文件名',
  `file_path` varchar(500) NOT NULL COMMENT '文件路径',
  `file_size` bigint(20) DEFAULT NULL COMMENT '文件大小（字节）',
  `file_type` varchar(50) DEFAULT NULL COMMENT '文件类型 image/pdf/doc等',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='订单附件表';

-- ============================================
-- 客户模块
-- ============================================

-- --------------------------------------------
-- 10. customer - 客户表
-- --------------------------------------------
DROP TABLE IF EXISTS `customer`;
CREATE TABLE `customer` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '客户ID',
  `company_name` varchar(200) NOT NULL COMMENT '公司名称',
  `country` varchar(100) DEFAULT NULL COMMENT '国家',
  `contact_name` varchar(50) DEFAULT NULL COMMENT '联系人姓名',
  `phone` varchar(20) DEFAULT NULL COMMENT '联系电话',
  `email` varchar(100) DEFAULT NULL COMMENT '邮箱',
  `address` varchar(500) DEFAULT NULL COMMENT '地址',
  `level` tinyint(1) NOT NULL DEFAULT 1 COMMENT '客户等级 1普通 2VIP',
  `remark` varchar(500) DEFAULT NULL COMMENT '备注',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态 1正常 0禁用',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_level` (`level`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='客户表';

-- ============================================
-- 供应商模块
-- ============================================

-- --------------------------------------------
-- 11. supplier - 供应商表（含登录信息）
-- --------------------------------------------
DROP TABLE IF EXISTS `supplier`;
CREATE TABLE `supplier` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '供应商ID',
  `name` varchar(200) NOT NULL COMMENT '供应商名称',
  `contact` varchar(50) DEFAULT NULL COMMENT '联系人',
  `phone` varchar(20) DEFAULT NULL COMMENT '联系电话',
  `email` varchar(100) DEFAULT NULL COMMENT '邮箱',
  `address` varchar(500) DEFAULT NULL COMMENT '地址',
  `login_account` varchar(50) NOT NULL COMMENT '登录账号',
  `login_password` varchar(255) NOT NULL COMMENT '登录密码(password_hash加密)',
  `last_login_time` datetime DEFAULT NULL COMMENT '最后登录时间',
  `last_login_ip` varchar(50) DEFAULT NULL COMMENT '最后登录IP',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态 1正常 0禁用',
  `remark` varchar(500) DEFAULT NULL COMMENT '备注',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_login_account` (`login_account`),
  KEY `idx_status` (`status`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='供应商表';

-- --------------------------------------------
-- 12. supplier_category - 供应商分类表
-- --------------------------------------------
DROP TABLE IF EXISTS `supplier_category`;
CREATE TABLE `supplier_category` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '分类ID',
  `name` varchar(50) NOT NULL COMMENT '分类名称',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='供应商分类表';

-- ============================================
-- 货品模块
-- ============================================

-- --------------------------------------------
-- 13. goods - 货品表
-- --------------------------------------------
DROP TABLE IF EXISTS `goods`;
CREATE TABLE `goods` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '货品ID',
  `goods_no` varchar(50) NOT NULL COMMENT '货品编号',
  `name` varchar(200) NOT NULL COMMENT '货品名称',
  `category_id` int(11) unsigned DEFAULT NULL COMMENT '分类ID 关联goods_category.id',
  `unit` varchar(20) DEFAULT '件' COMMENT '单位',
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '成本价',
  `sale_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '销售价',
  `image` varchar(500) DEFAULT NULL COMMENT '图片路径',
  `description` text COMMENT '商品描述',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态 1上架 0下架',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `delete_time` datetime DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_goods_no` (`goods_no`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='货品表';

-- --------------------------------------------
-- 14. goods_category - 货品分类表
-- --------------------------------------------
DROP TABLE IF EXISTS `goods_category`;
CREATE TABLE `goods_category` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '分类ID',
  `name` varchar(50) NOT NULL COMMENT '分类名称',
  `parent_id` int(11) unsigned DEFAULT 0 COMMENT '父级分类ID 0表示顶级',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='货品分类表';

-- --------------------------------------------
-- 15. goods_sku - 货品规格表
-- --------------------------------------------
DROP TABLE IF EXISTS `goods_sku`;
CREATE TABLE `goods_sku` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '规格ID',
  `goods_id` int(11) unsigned NOT NULL COMMENT '货品ID 关联goods.id',
  `sku_no` varchar(50) NOT NULL COMMENT '规格编号',
  `sku_name` varchar(100) DEFAULT NULL COMMENT '规格名称（如 颜色-尺寸）',
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '成本价',
  `sale_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '销售价',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态 1启用 0禁用',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sku_no` (`sku_no`),
  KEY `idx_goods_id` (`goods_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='货品规格表';

-- ============================================
-- 库存模块
-- ============================================

-- --------------------------------------------
-- 16. stock - 库存表
-- --------------------------------------------
DROP TABLE IF EXISTS `stock`;
CREATE TABLE `stock` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '库存ID',
  `goods_id` int(11) unsigned NOT NULL COMMENT '货品ID 关联goods.id',
  `sku_id` int(11) unsigned DEFAULT NULL COMMENT '规格ID 关联goods_sku.id',
  `quantity` int(11) NOT NULL DEFAULT 0 COMMENT '库存数量',
  `warning_quantity` int(11) NOT NULL DEFAULT 10 COMMENT '预警数量',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_goods_sku` (`goods_id`, `sku_id`),
  KEY `idx_quantity` (`quantity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='库存表';

-- --------------------------------------------
-- 17. stock_log - 库存变动日志表
-- --------------------------------------------
DROP TABLE IF EXISTS `stock_log`;
CREATE TABLE `stock_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `goods_id` int(11) unsigned NOT NULL COMMENT '货品ID',
  `sku_id` int(11) unsigned DEFAULT NULL COMMENT '规格ID',
  `type` varchar(20) NOT NULL COMMENT '类型 in入库 out出库 check盘点',
  `quantity` int(11) NOT NULL DEFAULT 0 COMMENT '变动数量（正数入库 负数出库）',
  `before_quantity` int(11) NOT NULL DEFAULT 0 COMMENT '变动前数量',
  `after_quantity` int(11) NOT NULL DEFAULT 0 COMMENT '变动后数量',
  `related_type` varchar(50) DEFAULT NULL COMMENT '关联类型 order/discheck/inventory等',
  `related_id` bigint(20) unsigned DEFAULT NULL COMMENT '关联ID',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `operator_id` int(11) unsigned DEFAULT NULL COMMENT '操作人ID',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_goods_id` (`goods_id`),
  KEY `idx_sku_id` (`sku_id`),
  KEY `idx_type` (`type`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='库存变动日志表';

-- --------------------------------------------
-- 18. inventory - 盘点单表
-- --------------------------------------------
DROP TABLE IF EXISTS `inventory`;
CREATE TABLE `inventory` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '盘点单ID',
  `inventory_no` varchar(32) NOT NULL COMMENT '盘点单号',
  `status` tinyint(2) NOT NULL DEFAULT 0 COMMENT '状态 0草稿 1进行中 2已完成',
  `remark` varchar(500) DEFAULT NULL COMMENT '备注',
  `creator_id` int(11) unsigned NOT NULL COMMENT '创建人ID',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `check_time` datetime DEFAULT NULL COMMENT '盘点完成时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inventory_no` (`inventory_no`),
  KEY `idx_status` (`status`),
  KEY `idx_creator_id` (`creator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='盘点单表';

-- --------------------------------------------
-- 19. inventory_item - 盘点明细表
-- --------------------------------------------
DROP TABLE IF EXISTS `inventory_item`;
CREATE TABLE `inventory_item` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '明细ID',
  `inventory_id` bigint(20) unsigned NOT NULL COMMENT '盘点单ID 关联inventory.id',
  `goods_id` int(11) unsigned NOT NULL COMMENT '货品ID',
  `sku_id` int(11) unsigned DEFAULT NULL COMMENT '规格ID',
  `system_quantity` int(11) NOT NULL DEFAULT 0 COMMENT '系统库存',
  `actual_quantity` int(11) NOT NULL DEFAULT 0 COMMENT '实际库存',
  `diff_quantity` int(11) NOT NULL DEFAULT 0 COMMENT '差异数量',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  PRIMARY KEY (`id`),
  KEY `idx_inventory_id` (`inventory_id`),
  KEY `idx_goods_id` (`goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='盘点明细表';

-- ============================================
-- 发货模块
-- ============================================

-- --------------------------------------------
-- 20. shipping - 出货单表
-- --------------------------------------------
DROP TABLE IF EXISTS `shipping`;
CREATE TABLE `shipping` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '出货单ID',
  `shipping_no` varchar(32) NOT NULL COMMENT '出货单号',
  `customer_id` int(11) unsigned DEFAULT NULL COMMENT '客户ID 关联customer.id',
  `logistics_company` varchar(100) DEFAULT NULL COMMENT '物流公司',
  `logistics_no` varchar(100) DEFAULT NULL COMMENT '物流单号',
  `status` tinyint(2) NOT NULL DEFAULT 0 COMMENT '状态 0待发货 1已发货 2已完成',
  `ship_date` date DEFAULT NULL COMMENT '发货日期',
  `remark` varchar(500) DEFAULT NULL COMMENT '备注',
  `creator_id` int(11) unsigned NOT NULL COMMENT '创建人ID',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shipping_no` (`shipping_no`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='出货单表';

-- --------------------------------------------
-- 21. shipping_item - 出货明细表
-- --------------------------------------------
DROP TABLE IF EXISTS `shipping_item`;
CREATE TABLE `shipping_item` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '明细ID',
  `shipping_id` bigint(20) unsigned NOT NULL COMMENT '出货单ID 关联shipping.id',
  `order_id` bigint(20) unsigned NOT NULL COMMENT '订单ID',
  `order_item_id` bigint(20) unsigned NOT NULL COMMENT '订单明细ID 关联order_item.id',
  `quantity` int(11) NOT NULL DEFAULT 0 COMMENT '发货数量',
  PRIMARY KEY (`id`),
  KEY `idx_shipping_id` (`shipping_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_order_item_id` (`order_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='出货明细表';

-- ============================================
-- 系统配置
-- ============================================

-- --------------------------------------------
-- 22. system_config - 系统配置表
-- --------------------------------------------
DROP TABLE IF EXISTS `system_config`;
CREATE TABLE `system_config` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '配置ID',
  `group` varchar(50) NOT NULL DEFAULT 'basic' COMMENT '配置分组 basic/email/attachment等',
  `key` varchar(50) NOT NULL COMMENT '配置键',
  `value` text COMMENT '配置值',
  `description` varchar(255) DEFAULT NULL COMMENT '配置说明',
  `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_key` (`group`, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统配置表';

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================
-- 初始化数据
-- ============================================

-- 插入默认管理员账号（密码：admin123）
INSERT INTO `admin_user` (`username`, `password`, `real_name`, `role_id`, `status`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '超级管理员', 1, 1);

-- 插入默认角色
INSERT INTO `admin_role` (`id`, `name`, `description`, `status`) VALUES
(1, '超级管理员', '拥有所有权限', 1),
(2, '订单管理员', '管理订单相关功能', 1),
(3, '仓库管理员', '管理库存和发货', 1),
(4, '普通员工', '基础查看权限', 1);

-- 插入默认货品分类
INSERT INTO `goods_category` (`id`, `name`, `parent_id`, `sort`) VALUES
(1, '青铜器', 0, 1),
(2, '陶瓷', 0, 2),
(3, '木雕', 0, 3),
(4, '摆件', 1, 1),
(5, '茶具', 2, 1),
(6, '雕像', 3, 1);

-- 插入默认供应商分类
INSERT INTO `supplier_category` (`id`, `name`, `sort`) VALUES
(1, '青铜器供应商', 1),
(2, '陶瓷供应商', 2),
(3, '综合供应商', 3);

-- 插入默认系统配置
INSERT INTO `system_config` (`group`, `key`, `value`, `description`) VALUES
('basic', 'site_name', '外贸订单管理系统', '网站名称'),
('basic', 'currency', 'CNY', '默认币种'),
('email', 'smtp_host', '', 'SMTP服务器'),
('email', 'smtp_port', '465', 'SMTP端口'),
('email', 'smtp_user', '', 'SMTP账号'),
('email', 'smtp_password', '', 'SMTP密码');
