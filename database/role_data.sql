-- ============================================
-- 预置角色数据
-- ============================================

USE trade_order;

-- 清空现有角色数据（保留 ID=1 的超级管理员）
DELETE FROM admin_role WHERE id > 1;

-- 插入预置角色
INSERT INTO `admin_role` (`id`, `name`, `description`, `permissions`, `status`) VALUES
-- 超级管理员（使用 UPDATE 更新）
(2, '订单管理员', '管理订单相关功能，包括订单、客户、供应商管理',
'["order.list","order.create","order.edit","order.delete","order.dispatch","order.confirm","order.receive","order.complete","order.cancel",
"customer.list","customer.create","customer.edit","customer.delete",
"supplier.list","supplier.edit"]',
1),

-- 仓库管理员
(3, '仓库管理员', '管理库存、收货、发货相关功能',
'["order.list","order.receive","order.complete",
"goods.list",
"stock.list","stock.in","stock.out",
"inventory.list","inventory.create","inventory.check",
"shipping.list","shipping.create","shipping.print"]',
1),

-- 普通员工
(4, '普通员工', '基础查看权限，仅可查看数据',
'["order.list","customer.list","supplier.list","goods.list","inventory.list","shipping.list"]',
1);

-- 确保超级管理员存在且权限为 NULL（表示所有权限）
INSERT IGNORE INTO `admin_role` (`id`, `name`, `description`, `permissions`, `status`) VALUES
(1, '超级管理员', '拥有所有权限', NULL, 1);

-- 更新超级管理员权限为 NULL（表示所有权限）
UPDATE admin_role SET permissions = NULL WHERE id = 1;
