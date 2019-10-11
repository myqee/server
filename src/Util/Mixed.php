<?php
namespace MyQEE\Server\Util;

/**
 * 杂项工具库
 *
 * @author     呼吸二氧化碳 <jonwang@myqee.com>
 * @category   MyQEE
 * @package    MyQEE\Server
 * @copyright  Copyright (c) 2008-2019 myqee.com
 * @license    http://www.myqee.com/license.html
 */
abstract class Mixed {

    /**
     * 深度合并数组对象
     *
     * @param array|\ArrayIterator $arr1
     * @param array|\ArrayIterator $arr2
     * @return array|\ArrayIterator
     */
    public static function deepMergeConfig(& $arr1, $arr2) {
        foreach ($arr2 as $k => $v) {
            if (is_array($v) && isset($arr1[$k]) && is_array($arr1[$k])) {
                self::deepMergeConfig($arr1[$k], $v);
            }
            else {
                $arr1[$k] = $v;
            }
        }
        return $arr1;
    }
}