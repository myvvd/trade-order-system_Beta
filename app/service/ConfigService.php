<?php
namespace app\service;

use think\facade\Db;
use think\facade\Cache;

class ConfigService
{
    protected $cachePrefix = 'sys_config_';

    public function get($key, $default = null)
    {
        $cacheKey = $this->cachePrefix . $key;
        $val = Cache::get($cacheKey);
        if ($val !== null) return $val;

        $row = Db::name('config')->where('key', $key)->find();
        if ($row) {
            $value = $row['value'];
            Cache::set($cacheKey, $value);
            return $value;
        }
        return $default;
    }

    public function set($key, $value)
    {
        $exists = Db::name('config')->where('key', $key)->find();
        if ($exists) {
            Db::name('config')->where('key', $key)->update(['value'=>$value]);
        } else {
            // try extract group from key like group.name
            $group = strpos($key, '.') !== false ? explode('.', $key)[0] : 'default';
            Db::name('config')->insert(['`key`'=>$key,'value'=>$value,'`group`'=>$group]);
        }
        Cache::set($this->cachePrefix . $key, $value);
        return true;
    }

    public function getGroup($group)
    {
        $rows = Db::name('config')->where('`group`', $group)->select();
        $out = [];
        foreach ($rows as $r) {
            $k = $r['key'];
            $short = strpos($k, '.') !== false ? substr($k, strpos($k, '.')+1) : $k;
            $out[$short] = $r['value'];
        }
        return $out;
    }
}
