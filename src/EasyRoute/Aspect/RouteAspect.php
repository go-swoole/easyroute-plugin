<?php
/**
 * Created by PhpStorm.
 * User: 白猫
 * Date: 2019/4/24
 * Time: 14:54
 */

namespace ESD\Plugins\EasyRoute\Aspect;


use ESD\BaseServer\Plugins\Logger\GetLogger;
use ESD\BaseServer\Server\AbstractServerPort;
use ESD\BaseServer\Server\Beans\Response;
use ESD\BaseServer\Server\Beans\WebSocketFrame;
use ESD\BaseServer\Server\Server;
use ESD\Plugins\EasyRoute\Controller\IController;
use ESD\Plugins\EasyRoute\EasyRouteConfig;
use ESD\Plugins\EasyRoute\PackTool\IPack;
use ESD\Plugins\EasyRoute\RouteException;
use ESD\Plugins\EasyRoute\RouteTool\IRoute;
use Go\Aop\Aspect;
use Go\Aop\Intercept\MethodInvocation;
use Go\Lang\Annotation\Around;

class RouteAspect implements Aspect
{
    use GetLogger;
    /**
     * @var EasyRouteConfig[]
     */
    protected $easyRouteConfigs;
    /**
     * @var IPack[]
     */
    protected $packTools = [];
    /**
     * @var IRoute[]
     */
    protected $routeTools = [];

    /**
     * @var IController[]
     */
    protected $controllers = [];

    /**
     * RouteAspect constructor.
     * @param $easyRouteConfigs
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    public function __construct($easyRouteConfigs)
    {
        $this->easyRouteConfigs = $easyRouteConfigs;
        foreach ($this->easyRouteConfigs as $easyRouteConfig) {
            if (!empty($easyRouteConfig->getPackTool())) {
                if (!isset($this->packTools[$easyRouteConfig->getPackTool()])) {
                    $className = $easyRouteConfig->getPackTool();
                    $this->packTools[$easyRouteConfig->getPackTool()] = Server::$instance->getContainer()->get($className);
                }
            }
            if (!isset($this->routeTools[$easyRouteConfig->getRouteTool()])) {
                $className = $easyRouteConfig->getRouteTool();
                $this->routeTools[$easyRouteConfig->getRouteTool()] = Server::$instance->getContainer()->get($className);
            }
        }
    }

    /**
     * around onHttpRequest
     *
     * @param MethodInvocation $invocation Invocation
     * @throws \ESD\BaseServer\Exception
     * @throws \Throwable
     * @Around("within(ESD\BaseServer\Server\IServerPort+) && execution(public **->onHttpRequest(*))")
     */
    protected function aroundHttpRequest(MethodInvocation $invocation)
    {
        list($request, $response) = $invocation->getArguments();
        $abstractServerPort = $invocation->getThis();
        if ($abstractServerPort instanceof AbstractServerPort && $response instanceof Response) {
            $easyRouteConfig = $this->easyRouteConfigs[$abstractServerPort->getPortConfig()->getPort()];
            setContextValue("EasyRouteConfig", $easyRouteConfig);
            $routeTool = $this->routeTools[$easyRouteConfig->getRouteTool()];
            try {
                $result = $routeTool->handleClientRequest($request, $response, $easyRouteConfig);
                if (!$result) return;
                $controllerInstance = $this->getController($easyRouteConfig, $routeTool->getControllerName());
                $result = $controllerInstance->handle($routeTool->getControllerName(), $routeTool->getMethodName(), $routeTool->getParams());
                if ($easyRouteConfig->isAutoJson() && (is_array($result) || is_object($result))) {
                    $result = json_encode($result, JSON_UNESCAPED_UNICODE);
                    $response->addHeader("Content-Type", "application/json");
                }
                $response->append($result);
            } catch (\Throwable $e) {
                try {
                    //这里的错误会移交给IndexController处理
                    $controllerInstance = $this->getController($easyRouteConfig, $easyRouteConfig->getControllerNameSpace() . "\\" . $easyRouteConfig->getIndexControllerName());
                    $controllerInstance->initialization($routeTool->getControllerName(), $routeTool->getMethodName());
                    $response->append($controllerInstance->onExceptionHandle($e));
                } catch (\Throwable $e) {
                    $this->warn($e);
                }
                throw $e;
            }
        }
        return;
    }

    /**
     * around onTcpReceive
     *
     * @param MethodInvocation $invocation Invocation
     * @throws \ESD\BaseServer\Exception
     * @Around("within(ESD\BaseServer\Server\IServerPort+) && execution(public **->onTcpReceive(*))")
     */
    protected function aroundTcpReceive(MethodInvocation $invocation)
    {
        list($fd, $reactorId, $data) = $invocation->getArguments();
        $abstractServerPort = $invocation->getThis();
        if ($abstractServerPort instanceof AbstractServerPort) {
            $easyRouteConfig = $this->easyRouteConfigs[$abstractServerPort->getPortConfig()->getName()];
            setContextValue("EasyRouteConfig", $easyRouteConfig);
            $packTool = $this->packTools[$easyRouteConfig->getPackTool()];
            $routeTool = $this->routeTools[$easyRouteConfig->getRouteTool()];
            try {
                $clientData = $packTool->unPack($data, $easyRouteConfig);
                setContextValue("ClientData", $clientData);
                $result = $routeTool->handleClientData($clientData, $easyRouteConfig);
                if (!$result) return;
                $controllerInstance = $this->getController($easyRouteConfig, $routeTool->getControllerName());
                $result = $controllerInstance->handle($routeTool->getControllerName(), $routeTool->getMethodName(), $routeTool->getParams());
                if ($result != null) {
                    Server::$instance->send($fd, $packTool->pack($result, $easyRouteConfig));
                }
            } catch (\Throwable $e) {
                try {
                    //这里的错误会移交给IndexController处理
                    $controllerInstance = $this->getController($easyRouteConfig, $easyRouteConfig->getControllerNameSpace() . "\\" . $easyRouteConfig->getIndexControllerName());
                    $controllerInstance->initialization($routeTool->getControllerName(), $routeTool->getMethodName());
                    $controllerInstance->onExceptionHandle($e);
                } catch (\Throwable $e) {
                    $this->warn($e);
                }
            }
        }
        return;
    }

    /**
     * around onWsMessage
     *
     * @param MethodInvocation $invocation Invocation
     * @throws \ESD\BaseServer\Exception
     * @Around("within(ESD\BaseServer\Server\IServerPort+) && execution(public **->onWsMessage(*))")
     */
    protected function aroundWsMessage(MethodInvocation $invocation)
    {
        list($frame) = $invocation->getArguments();
        $abstractServerPort = $invocation->getThis();
        if ($abstractServerPort instanceof AbstractServerPort && $frame instanceof WebSocketFrame) {
            $easyRouteConfig = $this->easyRouteConfigs[$abstractServerPort->getPortConfig()->getName()];
            setContextValue("EasyRouteConfig", $easyRouteConfig);
            $packTool = $this->packTools[$easyRouteConfig->getPackTool()];
            $routeTool = $this->routeTools[$easyRouteConfig->getRouteTool()];
            try {
                $clientData = $packTool->unPack($frame->getData(), $easyRouteConfig);
                setContextValue("ClientData", $clientData);
                $result = $routeTool->handleClientData($clientData, $easyRouteConfig);
                if (!$result) return;
                $controllerInstance = $this->getController($easyRouteConfig, $routeTool->getControllerName());
                $result = $controllerInstance->handle($routeTool->getControllerName(), $routeTool->getMethodName(), $routeTool->getParams());
                if ($result != null) {
                    Server::$instance->wsPush($frame->getFd(), $packTool->pack($result, $easyRouteConfig), $easyRouteConfig->getWsOpcode());
                }
            } catch (\Throwable $e) {
                try {
                    //这里的错误会移交给IndexController处理
                    $controllerInstance = $this->getController($easyRouteConfig, $easyRouteConfig->getControllerNameSpace() . "\\" . $easyRouteConfig->getIndexControllerName());
                    $controllerInstance->initialization($routeTool->getControllerName(), $routeTool->getMethodName());
                    $controllerInstance->onExceptionHandle($e);
                } catch (\Throwable $e) {
                    $this->warn($e);
                }
            }
        }
        return;
    }

    /**
     * around onUdpPacket
     *
     * @param MethodInvocation $invocation Invocation
     * @Around("within(ESD\BaseServer\Server\IServerPort+) && execution(public **->onUdpPacket(*))")
     */
    protected function aroundUdpPacket(MethodInvocation $invocation)
    {
        list($data, $clientInfo) = $invocation->getArguments();
        $abstractServerPort = $invocation->getThis();
        if ($abstractServerPort instanceof AbstractServerPort) {
            $easyRouteConfig = $this->easyRouteConfigs[$abstractServerPort->getPortConfig()->getPort()];
            $packTool = $this->packTools[$easyRouteConfig->getPackTool()];
            $routeTool = $this->routeTools[$easyRouteConfig->getRouteTool()];
            try {
                setContextValue("EasyRouteConfig", $easyRouteConfig);
                $clientData = $packTool->unPack($data, $easyRouteConfig);
                setContextValue("ClientData", $clientData);
                $result = $routeTool->handleClientData($clientData, $easyRouteConfig);
                if (!$result) return;
                $controllerInstance = $this->getController($easyRouteConfig, $routeTool->getControllerName());
                $controllerInstance->handle($routeTool->getControllerName(), $routeTool->getMethodName(), $routeTool->getParams());
            } catch (\Throwable $e) {
                try {
                    //这里的错误会移交给IndexController处理
                    $controllerInstance = $this->getController($easyRouteConfig, $easyRouteConfig->getControllerNameSpace() . "\\" . $easyRouteConfig->getIndexControllerName());
                    $controllerInstance->initialization($routeTool->getControllerName(), $routeTool->getMethodName());
                    $controllerInstance->onExceptionHandle($e);
                } catch (\Throwable $e) {
                    $this->warn($e);
                }
            }
        }
        return;
    }

    /**
     * @param EasyRouteConfig $easyRouteConfig
     * @param $controllerName
     * @return IController
     * @throws RouteException
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    private function getController(EasyRouteConfig $easyRouteConfig, $controllerName)
    {
        if (!isset($this->controllers[$controllerName])) {
            if (class_exists($controllerName)) {
                $controller = Server::$instance->getContainer()->get($controllerName);
                if ($controller instanceof IController) {
                    $this->controllers[$controllerName] = $controller;
                    return $controller;
                } else {
                    throw new RouteException("类{$controllerName}应该继承IController");
                }
            } else {
                throw new RouteException("没有找到类$controllerName");
            }
        } else {
            return $this->controllers[$controllerName];
        }
    }

    /**
     * 增强send，可以根据不同协议转码发送
     * @param $fd
     * @param $data
     * @return bool
     */
    public function autoBoostSend($fd, $data): bool
    {
        $clientInfo = Server::$instance->getClientInfo($fd);
        $easyRouteConfig = $this->easyRouteConfigs[$clientInfo->getServerPort()];
        $pack = $this->packTools[$easyRouteConfig->getPackTool()];
        $data = $pack->pack($easyRouteConfig, $data);
        if (Server::$instance->isEstablished($fd)) {
            return Server::$instance->wsPush($fd, $data, $easyRouteConfig->getWsOpcode());
        } else {
            return Server::$instance->send($fd, $data);
        }
    }
}