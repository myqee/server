<?php
namespace MyQEE\Server\Util;

/**
 * 工具库
 *
 * @author     呼吸二氧化碳 <jonwang@myqee.com>
 * @category   MyQEE
 * @package    MyQEE\Server
 * @copyright  Copyright (c) 2008-2019 myqee.com
 * @license    http://www.myqee.com/license.html
 */
abstract class Text
{
    /**
     * 返回一个真实路径
     *
     * 支持在 phar 中获取路径
     *
     * @param $path
     * @return bool|string
     */
    public static function realPath($path)
    {
        if (!(is_file($path) || is_link($path) || is_dir($path)))
        {
            # 文件或目录不存在
            return false;
        }

        # 调用系统的
        $realPath = realpath($path);
        if (false !== $realPath)return $realPath;

        # 如果不是返回 false 则调用下面的方法

        $pathArr = explode('://', $path, 2);
        if (count($pathArr) > 1)
        {
            $path = $pathArr[1];
            $type = $pathArr[0] . '://';
        }
        else
        {
            $type = '';
        }

        $path      = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts     = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
        $absolutes = [];
        foreach ($parts as $part)
        {
            if ('.' == $part)
            {
                continue;
            }
            if ('..' == $part)
            {
                array_pop($absolutes);
            }
            else
            {
                $absolutes[] = $part;
            }
        }

        return $type . implode(DIRECTORY_SEPARATOR, $absolutes);
    }

    /**
     * 清理php系统缓存
     *
     * 包括文件缓存、apc缓存（如果有）、opcache（如果有）、mt_rand重新播种
     */
    public static function clearPhpSystemCache()
    {
        # stat缓存清理
        clearstatcache();

        if (function_exists('apc_clear_cache'))
        {
            apc_clear_cache();
        }
        if (function_exists('opcache_reset'))
        {
            opcache_reset();
        }

        # 在 Swoole 中如果在父进程内调用了 mt_rand，不同的子进程内再调用 mt_rand 返回的结果会是相同的，所以必须在每个子进程内调用 mt_srand 重新播种
        # see https://wiki.swoole.com/wiki/page/732.html
        mt_srand();
    }

    /**
     * 返回一个将根路径移除的路径
     *
     * @param string|array $path
     * @return array|string
     */
    public static function debugPath($path)
    {
        if (is_array($path)) {
            $arr = [];
            foreach ($path as $k => $v) {
                $arr[$k] = self::debugPath($v);
            }

            return $arr;
        }

        if (substr($path, 0, strlen(BASE_DIR)) === BASE_DIR) {
            return substr($path, strlen(BASE_DIR));
        }
        elseif (defined('IN_PHAR_BASE_DIR') && substr($path, 0, strlen(IN_PHAR_BASE_DIR)) === IN_PHAR_BASE_DIR) {
            // 打包工具打包后的路径
            return substr($path, strlen(IN_PHAR_BASE_DIR));
        }
        else {
            return $path;
        }
    }

    /**
     * 支持在 phar 中使用
     *
     * 在phar中需要依赖 Symfony/Finder
     *
     * @param $pattern
     * @return array|false
     */
    public static function glob($pattern) {
        if (!defined('IN_PHAR_BASE_DIR')) {
            return glob($pattern);
        }

        $defBase = false;
        if (strpos($pattern, BASE_DIR) === 0) {
            $pattern = IN_PHAR_BASE_DIR . substr($pattern, strlen(BASE_DIR));
            $defBase = true;
        }

        if (\strlen($pattern) === $i = strcspn($pattern, '*?{[')) {
            $prefix = $pattern;
            $pattern = '';
        } elseif (0 === $i || false === strpos(substr($pattern, 0, $i), '/')) {
            $prefix = '.';
            $pattern = '/'.$pattern;
        } else {
            $prefix = \dirname(substr($pattern, 0, 1 + $i));
            $pattern = substr($pattern, \strlen($prefix));
        }

        if (!class_exists('\\Symfony\\Component\\Finder\\Finder')) {
            \MyQEE\Server\Server::$instance->warn("在 phar 中使用了 MyQEE\Server\Util\Text::glob() 方法，但是系统没有安装 Symfony/Finder");
            return [];
        }

        $rs     = [];
        $finder = new \Symfony\Component\Finder\Finder();
        try {
            foreach ($finder->followLinks()->sortByName()->in($prefix) as $path => $info) {
                $rs[] = $defBase ? BASE_DIR . substr($path, strlen(IN_PHAR_BASE_DIR)) : $path;
            }
        }
        catch (\Symfony\Component\Finder\Exception\DirectoryNotFoundException $e) {
        }
        return $rs;
    }

    /**
     * 获取一个随机字符串
     *
     * alnum
     * :  Upper and lower case a-z, 0-9 (default)
     *
     * alpha
     * :  Upper and lower case a-z
     *
     * hexdec
     * :  Hexadecimal characters a-f, 0-9
     *
     * distinct
     * :  Uppercase characters and numbers that cannot be confused
     *
     * punctuation
     * : alnum的基础上加上了标点符号内容
     *
     * @param integer 长度
     * @param string 类型，包括: alnum, alpha, hexdec, numeric(num), nozero, distinct, punctuation
     * @return string
     */
    public static function random($length = 8, $type = null)
    {
        if (null === $type)$type = 'alnum';

        switch ($type)
        {
            case 1:
            case 'alnum':
                $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                break;
            case 2:
            case 'alpha':
                $pool = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                break;
            case 3:
            case 'hexdec':
                $pool = '0123456789abcdef';
                break;
            case 4:
            case 'numeric':
            case 'num':
                $pool = '0123456789';
                break;
            case 5:
            case 'nozero':
                $pool = '123456789';
                break;
            case 6:
            case 'distinct':
                $pool = '2345679ACDEFHJKLMNPRSTUVWXYZ';
                break;
            case 0:
            case 'punctuation':
            default :
                $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ~`!@#$%^&*()_-+={}|[]:;<>?,.';
                break;
        }

        $pool = str_split($pool, 1);
        $max  = count($pool) - 1;
        $str  = '';

        for($i = 0; $i < $length; $i++)
        {
            $str .= $pool[mt_rand(0, $max)];
        }

        if ($type === 'alnum' && $length > 1)
        {
            if (ctype_alpha($str))
            {
                // Add a random digit
                $str[mt_rand(0, $length - 1)] = chr(mt_rand(48, 57));
            }
            elseif (ctype_digit($str))
            {
                // Add a random letter
                $str[mt_rand(0, $length - 1)] = chr(mt_rand(65, 90));
            }
        }

        return $str;
    }

    /**
     * 获取bin2hex的字符
     *
     * 使用
     *
     * `echo Text::hexString('你好')` 输出: \xe4\xbd\xa0\xe5\xa5\xbd
     * `echo Text::hexString('你好', ' ', true)` 输出: e4 bd a0 e5 a5 bd
     *
     * @param string $string
     * @param string $prefix 前缀字符
     * @param bool $glueMode 是否连接模式，连接模式则第一个字符串不含 `$prefix`
     * @return string
     */
    public static function hexString($string, $prefix = '\\x', $glueMode = false)
    {
        $string = bin2hex($string);
        $string = chunk_split($string, 2, $prefix);
        $string = ($glueMode ? '' : $prefix). substr($string, 0, - strlen($prefix));
        return $string;
    }

    /**
     * 将一个 `Util::hexString()` 的字符串重新解析成二进制数据
     *
     * ```
     * $debugStr = Util::hexString('你好');
     * $str      = Util::hexString2bin($debugStr);
     *
     * echo $debugStr;
     * echo $str;
     * ```
     *
     * @param string $hex
     * @param string $prefix
     * @return false|string
     */
    public static function hexString2bin($hex, $prefix = '\\x')
    {
        if ($prefix !== '')
        {
            $hex = str_replace($prefix, '', $hex);
        }
        $hex = hex2bin($hex);
        return $hex;
    }

    /**
     * 解析一个Yaml
     *
     * 如果有 yaml 扩展则使用 yaml 扩展的解析，否则尝试用 Symfony\Component\Yaml\Yaml 解析
     * 支持在 phar 包中运行，解决 `yaml_parse_file($file)` 在 phar 中无法使用的问题
     *
     * @param string $fileOrContent 文件路径
     * @param bool   $isContent     true则表示第一个参数为一个yaml内容而不是一个文件路径
     * @return array|false
     */
    public static function yamlParse($fileOrContent, $isContent = false)
    {
        switch (self::yamlSupportType())
        {
            case 1:
                if ($isContent)
                {
                    $config = yaml_parse($fileOrContent);
                }
                elseif (substr($fileOrContent, 0, 7) === 'phar://')
                {
                    # 在 phar 里使用 yaml_parse_file() 会出现文件不存在的错误
                    $config = yaml_parse(file_get_contents($fileOrContent));
                }
                else
                {
                    $config = yaml_parse_file($fileOrContent);
                }
                break;

            case 2:
                try
                {
                    $config = \Symfony\Component\Yaml\Yaml::parse($isContent ? $fileOrContent : file_get_contents($fileOrContent));
                }
                catch (\Symfony\Component\Yaml\Exception\ParseException $e)
                {
                    $config = false;
                }
                break;

            case 0:
            default:
                return false;
        }

        if (!is_array($config))
        {
            return false;
        }

        return $config;
    }

    /**
     * yaml扩展支持类型
     *
     * 0: 不支持
     * 1: yaml 扩展
     * 2: Symfony\Component\Yaml\Yaml 包
     *
     * @return int
     */
    public static function yamlSupportType()
    {
        static $yamlType = null;

        if (null === $yamlType)
        {
            if (function_exists('\\yaml_parse_file'))
            {
                $yamlType = 1;
            }
            elseif (class_exists('\\Symfony\\Component\\Yaml\\Yaml'))
            {
                $yamlType = 2;
            }
            else
            {
                $yamlType = 0;
            }
        }
        return $yamlType;
    }
}