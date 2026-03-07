-- 客户表新增微信号字段
ALTER TABLE `customer` ADD COLUMN `wechat` varchar(50) DEFAULT NULL COMMENT '微信号' AFTER `phone`;
