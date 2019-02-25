<?php
namespace MyQEE\Server;

/**
 * 穿梭服务
 *
 * 提高对复杂数据流处理业务的编程体验，降低编程人员对业务处理程序理解难度
 *
 * @author     呼吸二氧化碳 <jonwang@myqee.com>
 * @category   MyQEE
 * @package    MyQEE\Server
 * @subpackage Traits
 * @copyright  Copyright (c) 2008-2019 myqee.com
 * @license    http://www.myqee.com/license.html
 */
class ShuttleJob
{
    /**
     * 输入内容
     *
     * @var string|mixed
     */
    public $input;

    /**
     * 输出内容
     *
     * @var mixed
     */
    public $output;

    /**
     * 协程ID
     *
     * @var int
     */
    public $coId;

    /**
     * 是否完成
     *
     * @var bool
     */
    public $isDone = false;
}