<?php
namespace MyQEE\Server\Http;

use \MyQEE\Server\Server;

class Request extends \Swoole\Http\Request
{
    public function __destruct()
    {
        # 移除临时文件
        if (isset($this->files) && $this->files)
        {
            $rm = function($file)
            {
                if ($file && is_file($file))
                {
                    $rs = @unlink($file);
                    Server::$instance->debug('Remove upload tmp file '. ($rs ? 'success' : 'fail') .': '. $file);
                }
            };

            foreach ($this->files as $v)
            {
                if (isset($v['tmp_name']))
                {
                    /*
                    [
                        'aaa' => [
                            "tmp_name" => [
                                'a' => '...',
                                'b' => '...',
                                'c' => '...',
                            ],
                        ],
                        'bbb' => [
                            'tmp_name' => [
                                'a' => [
                                    '...',
                                    '...',
                                    '...',
                                ],
                                'b' => [
                                    'c' => '...',
                                    'd' => '...',
                                ],
                            ],
                        ],
                        'ccc' => [
                            'tmp_name' => '...',
                        ],
                    ]
                     */
                    if (is_array($v['tmp_name']))
                    {
                        $files = self::_each($v['tmp_name']);
                        array_walk($files, $rm);
                    }
                    else
                    {
                        $rm($v['tmp_name']);
                    }
                }
                else
                {
                    /*
                    [
                        'aaa' => [
                            'test' => [
                                "tmp_name" => '...',
                                //...
                            ],
                            'test' => [
                                "tmp_name" => '...',
                                //...
                            ],
                        ],
                        'bbb' => [
                            [
                                'tmp_name' => '...',
                                //...
                            ],
                            [
                                'tmp_name' => '...',
                                //...
                            ],
                        ],
                        'ccc' => [
                            'tmp_name' => '...',
                            //...
                        ],
                    ]
                     */
                    if (preg_match_all('#"tmp_name":"([^"]+)"#', json_encode($v), $m))
                    {
                        array_walk($m[1], $rm);
                    }
                }
            }
        }
    }

    protected static function _each($arr)
    {
        $rs = [];
        foreach ($arr as $item)
        {
            if (is_array($item))
            {
                $rs = array_merge($rs, self::_each($item));
            }
            else
            {
                $rs[] = $item;
            }
        }

        return $rs;
    }

}