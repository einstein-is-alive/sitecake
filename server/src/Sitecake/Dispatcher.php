<?php

namespace Sitecake;

use Silex\Application;
use Sitecake\Exception\Http\UnauthorizedException;
use Sitecake\Exception\MissingActionException;
use Sitecake\Exception\MissingServiceException;
use Sitecake\Services\Service;
use Sitecake\Services\ServiceRegistry;
use Sitecake\Util\Utils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Dispatcher
{
    /**
     * @var Application
     */
    protected $_context;

    /**
     * @var SessionManager
     */
    protected $_sm;

    public function __construct($context, SessionManagerInterface $sm)
    {
        $this->_context = $context;
        $this->_sm = $sm;
        ServiceRegistry::initialize($context);
    }

    public function dispatch(Request $request)
    {
        if ($request->query->has('service'))
        {
            $service = ServiceRegistry::get($request->query->get('service'));

            if ($service instanceof Service)
            {
                $action = $request->query->has('action') ? $request->query->get('action') : null;

                return $this->_execute($service, $action, $request);
            }

            throw new MissingServiceException([
                'service' => ucfirst($request->query->get('service'))
            ]);
        }
        else
        {
            /** @var Renderer $renderer */
            $renderer = $this->_context['renderer'];

            return $this->_sm->isLoggedIn() ?
                $renderer->editResponse($request->query->get('page')) :
                $renderer->loginResponse();
        }
    }

    protected function _execute(Service $service, $action, $request)
    {
        if (!$service->actionExists($action))
        {
            throw new MissingActionException([
                'action' => $action,
                'service' => ucfirst($request->query->get('service'))
            ], 400);
        }

        if ($service->isAuthRequired($action) && !$this->_sm->isLoggedIn())
        {
            throw new UnauthorizedException('Unauthorized access');
        }

        return $this->response($service, $action, $request);
    }

    protected function response($service, $action, $request)
    {
        try
        {
            $res = $service->$action($request);
            if ($res instanceof Response)
            {
                return $res;
            }
            else
            {
                return new JsonResponse($res, 200);
            }
        }
        catch (\Exception $e)
        {
            $code = $e->getCode();
            $httpCodes = [400, 401, 403, 404, 405, 500];

            // TODO : Need to send real http status when new client is implemented
			if(empty($code) || !in_array($code, $httpCodes))
            {
                $code = 500;
            }
            $res = [
                'status' => -1,
                'code' => $code,
                'errMessage' => $e->getMessage()
            ];
            if($this->_context['debug'])
            {
                $res['trace'] = Utils::formatTrace($e);
            }
            return new JsonResponse($res, $code);
        }
    }

}