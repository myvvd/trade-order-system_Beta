CREATE TABLE IF NOT EXISTS `trading_company` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '公司名称',
  `name_en` varchar(100) DEFAULT NULL COMMENT '英文名称',
  `country` varchar(50) DEFAULT NULL COMMENT '国家',
  `address` varchar(255) DEFAULT NULL COMMENT '详细地址',
  `remark` text DEFAULT NULL COMMENT '备注',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:1正常0禁用',
  `create_time` int DEFAULT NULL,
  `update_time` int DEFAULT NULL,
  `delete_time` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='贸易公司';

CREATE TABLE IF NOT EXISTS `salesman` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '姓名',
  `phone` varchar(50) DEFAULT NULL COMMENT '联系方式',
  `trading_company_id` int DEFAULT NULL COMMENT '所属贸易公司ID',
  `remark` text DEFAULT NULL COMMENT '备注',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态:1正常0禁用',
  `create_time` int DEFAULT NULL,
  `update_time` int DEFAULT NULL,
  `delete_time` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='业务员';
