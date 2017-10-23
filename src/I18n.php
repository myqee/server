<?php
namespace MyQEE\Server;

/**
 * 语言包对象
 *
 * @package MyQEE\Server
 */
class I18n
{
    /**
     * 语言列表
     *
     * @var array
     */
    public $lang = [];

    /**
     * 语言包
     *
     * @var null
     */
    public $package = 'global';

    const DEFAULT_LANG = 'zh-cn';

    protected static $i18nPackage = [];
    protected static $i18nAll = [];

    function __construct($package = null, array $lang = null)
    {
        $this->package = $package;
        if (!$lang)
        {
            $this->lang = [static::DEFAULT_LANG];
        }
        else
        {
            $this->lang = $lang;
            if (!in_array(static::DEFAULT_LANG, $this->lang))
            {
                $this->lang[] = static::DEFAULT_LANG;
            }
        }

        $this->init();
    }

    public function init()
    {
        foreach ($this->lang as $lang)
        {
            if (!isset(self::$i18nAll[$lang]))
            {
                # 初始化当前语言包的数据
                static::reloadI18n($lang);
            }
        }
    }

    /**
     * 输出
     *
     * @param            $string
     * @param array|null $values
     * @return string
     */
    public function __invoke($string, array $values = null)
    {
        foreach ($this->lang as $lang)
        {
            if (isset(self::$i18nPackage[$lang][$string]))
            {
                $string = self::$i18nPackage[$lang][$string];
                break;
            }
            elseif (isset(self::$i18nAll[$lang][$string]))
            {
                $string = self::$i18nAll[$lang][$string];
                break;
            }
        }

        return empty($values) ? $string : strtr($string, $values);
    }

    /**
     * 获取语言包路径
     *
     * @return array
     */
    protected static function getI18nPath()
    {
        return [
            BASE_DIR .'i18n/',
            __DIR__ .'/../i18n/',
        ];
    }

    /**
     * 重新加载语言
     *
     * 不设置则重新加载全部已经加载过的语言包（未加载过的不加载）
     *
     * @param null $lang
     */
    public static function reloadI18n($lang = null)
    {
        if (null === $lang)
        {
            foreach (array_keys(self::$i18nAll) as $lang)
            {
                static::reloadI18n($lang);
            }
            return;
        }

        # 重新加载指定语言
        self::$i18nAll[$lang]     = [];
        self::$i18nPackage[$lang] = [];
        foreach (static::getI18nPath() as $path)
        {
            $file = "{$path}{$lang}.lang";
            if (is_file($file))
            {
                static::loadFromFile($lang, $file);
            }
        }
    }

    /**
     * 从语言包文件中加载数据
     *
     * @param string $lang 语言
     * @param string $file 语言包文件路径
     */
    protected static function loadFromFile($lang, $file)
    {
        $string  = file_get_contents($file);
        $string  = explode("\n", $string);
        $package = 'global';

        if (!isset(self::$i18nAll[$lang]))
        {
            self::$i18nPackage[$lang] = [];
            self::$i18nAll[$lang]     = [];
        }
        $i18nPackage =& self::$i18nPackage[$lang];
        $i18nAll     =& self::$i18nAll[$lang];

        foreach ($string as $item)
        {
            $item = trim($item);
            if (!$item)continue;

            $item0 = $item[0];
            if ($item0 === '[')
            {
                if (preg_match('#\[([a-zA-Z0-9_\.\-]+)\]#', $item, $m))
                {
                    # 新的分组
                    $package = $m[1];
                }
            }
            elseif ($item0 === ';' || $item0 === '#' || $item0 === '/')continue;  # 忽略注释

            $item = explode('=', $item, 2);
            if (isset($item[1]))
            {
                $key   = trim($item[0]);
                $value = str_replace(['\\n', "\\'", '\\"'], ["\n", "'", '"'], trim($item[1]));

                # 加入当前包中
                if (!isset($i18nPackage[$package]))$i18nPackage[$package] = [];
                $i18nPackage[$package][$key] = $value;

                if ($package === 'global' || !isset($i18nPackage['global'][$key]))
                {
                    # 当前是全局包或者全局包中没有定义，则加入全局
                    $i18nAll[$key] = $value;
                }
            }
        }
        unset($i18nPackage, $i18nAll);
    }

    /**
     * 获取语言包列表
     *
     * @return array
     */
    public static function getAcceptLanguage($language)
    {
        static $cached = [];
        if (isset($cached[$language]))return $cached[$language];

        # 客户端语言包
        $acceptLanguage = [];

        # 匹配语言设置
        if ($language && false === strpos($language, ';'))
        {
            # zh-cn
            if (preg_match('#^([a-z]+(?:\-[a-z]+)?),#i', $language, $m))
            {
                $acceptLanguage = [rtrim(strtolower($language), ',')];
            }
            else
            {
                $acceptLanguage = [strtolower($language)];
            }
        }
        elseif ($language)
        {
            $language = strtolower(trim(str_replace(',', ';', preg_replace('#(,)?q=[0-9\.]+(,)?#', '', $language)), ';'));

            $acceptLanguage = explode(';', $language);
            $acceptLanguage = array_values(array_slice($acceptLanguage, 0, 4));    //只取前4个语言设置
        }

        if (!in_array(static::DEFAULT_LANG, $acceptLanguage))
        {
            $acceptLanguage[] = static::DEFAULT_LANG;
        }

        /*
        $acceptLanguage 整理之前
        Array
        (
            [0] => ko-kr
            [1] => en-us
            [2] => zh-cn
        )
        $acceptLanguage 整理之后
        Array
        (
            [0] => ko-kr
            [1] => ko
            [2] => en-us
            [3] => en
            [4] => zh-cn
            [5] => zh
        )
        */
        $renewAcceptLanguage = [];
        foreach($acceptLanguage as $item)
        {
            $sub_lang = explode('-', $item);

            $renewAcceptLanguage[] = $item;
            if (count($sub_lang) > 1)
            {
                $renewAcceptLanguage[] = $sub_lang[0];
            }
        }
        $cached[$language] = $acceptLanguage = array_unique($renewAcceptLanguage);

        if (count($cached[$language]) > 1000)
        {
            # 避免占用太大
            $cached[$language] = array_slice($cached[$language], -100, 100, true);
        }

        return $acceptLanguage;
    }
}