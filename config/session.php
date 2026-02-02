<?php
// +----------------------------------------------------------------------
// | 会话设置
// +----------------------------------------------------------------------

return [
    // session name
    'name'           => 'PHPSESSID',
    // SESSION_ID的提交变量,解决flash上传跨域
    'var_session_id' => '',
    // 驱动方式 支持file cache
    'type'           => 'file',
    // 存储连接标识 当type使用cache的时候有效
    'store'          => null,
    // 过期时间
    'expire'         => 1440,
    // 前缀
    'prefix'         => '',
    // Cookie 安全设置
    // 注意：在开发环境下可以关闭 secure（HTTPS 强制）以便本地调试，生产环境请设置为 true
    'secure'         => false,
    'httponly'       => true,
    'same_site'      => 'Lax',
];
