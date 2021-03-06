<?php
/**
 * Created by PhpStorm.
 * User: 白猫
 * Date: 2019/5/16
 * Time: 13:11
 */

namespace ESD\Plugins\EasyRoute\Annotation;

/**
 * @Annotation
 * @Target("CLASS")
 * Class RestController
 * @package ESD\Plugins\EasyRoute\Annotation
 */
class WsController extends Controller
{
    public $portTypes = ["ws"];
    public $defaultMethod = "WS";
}