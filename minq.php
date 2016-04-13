<?php
/*
 * Minquinity App Framework version 0.1.0
 */
namespace minq;

class StrUtils {
    public static function camelCaseToSpinalCase($ccStr) {
        $spinal = '';
        for ($i = 0, $len = strlen($ccStr); $i < $len; $i++) {
            if ($ccStr[$i] >= 'A' && $ccStr[$i] <= 'Z' && $i > 0) {
                $spinal .= '-';
            }
            $spinal .= $ccStr[$i];
        }
        return strtolower($spinal);
    }

    public static function spinalCaseToCamelCase($scStr, $toPascalCase = false) {
        $str = implode('', array_map('ucfirst', explode('-', $scStr)));
        if (!$toPascalCase) $str = lcfirst($str);
        return $str;
    }

    public static function getAnnotationValue($docComment, $annotationName) {
        $dcl = strlen($docComment);
        for ($p = -1; true;) {
            $p = strpos($docComment, '@' . $annotationName, $p + 1);
            if ($p === false) return null;
            $nextCharPos = $p + 1 + strlen($annotationName);
            if ($dcl == $nextCharPos) return true;
            if (!ctype_space($docComment[$nextCharPos])) continue; // its not this annotation
            $valEndPos = strpos($docComment, "\n", $nextCharPos);
            $value = $valEndPos === false ? substr($docComment, $nextCharPos) : substr($docComment, $nextCharPos, $valEndPos - $nextCharPos + 1);
            $value = trim($value);
            return empty($value) ? true : $value;
        }
    }
}

class DependencyFlags {
    const Singleton = 1;
}

interface IDependencyFactory {
    public function createDependency($type, IDependencyContainer $container, IActivator $activator);
    public function getDependencyFlags($type);
}

interface IActivator {
    public function createInstance($className); // next arguments = constructor parameters
    public function createInstanceArgs($className, array $constructorArgs);
}

interface IDependencyContainer {
    public function resolve($type);
}

interface IDependencyInjector {
    public function injectInto($object, IDependencyContainer $container);
}

/*interface IClassDependencyInfo {
    /**
     * @return array array(array(property name, dependency type, flags))
     * /
    public function getPropertyInjections($className);
}*/

class RegistryBasedDependencyFactory implements IDependencyFactory {
    protected $types = [];

    public function register($type, $createCallback, $flags = DependencyFlags::Singleton) {
        $this->types[$type] = ['cb' => $createCallback, 'f' =>$flags];
    }
    public function registerClass($type, $implementingClassName, array $constructorParams = [], $flags = DependencyFlags::Singleton) {
        $this->types[$type] = ['cl' => $implementingClassName, 'cp' => $constructorParams, 'f' =>$flags];
    }
    public function registerObject($type, $object, $flags = DependencyFlags::Singleton) {
        $this->types[$type] = ['obj' => $object, 'f' => $flags];
    }
    public function createDependency($type, IDependencyContainer $container, IActivator $activator) {
        if (!isset($this->types[$type])) return null;
        $t = $this->types[$type];
        if (isset($t['obj'])) {
            return $t['obj'];
        } elseif (isset($t['cb'])) {
            return call_user_func($t['cb'], $type, $container, $activator);
        } elseif (!empty($t['cl'])) {
            return $activator->createInstanceArgs($t['cl'], $t['cp']);
        }
        throw new \Exception('Unknown registration type for ' . $type);
    }
    public function getDependencyFlags($type) {
        if (!isset($this->types[$type])) return null;
        $this->types[$type]['f'];
    }
}

/*class StdClassDependencyInfo implements IClassDependencyInfo {
    public function getPropertyInjections($className) {
        $info = [];
        $reflClass = new \ReflectionClass($className);
        $nsName = $reflClass->getNamespaceName();
        foreach ($reflClass->getProperties() as $reflProperty) {
            $dc = $reflProperty->getDocComment();
            $injectAnnotation = StrUtils::getAnnotationValue($dc, 'inject');
            if ($injectAnnotation !== null) {
                $type = StrUtils::getAnnotationValue($dc, 'var');
                if ($type !== null) {
                    $pdc = $reflProperty->getDeclaringClass();
                    $ns = $pdc == $reflClass ? $nsName : $pdc->getNamespaceName();
                    if ('\\' !== $type[0] && !empty($ns)) $type = $ns . '\\' . $type;
                    if ('\\' == $type[0]) $type = substr($type, 1);
                    $info[] = [$reflProperty->getName(), $type, $injectAnnotation];
                }
            }
        }
        return $info;
    }
}*/

class DependencyContainer implements IDependencyContainer, IActivator {
    protected $singletons = [];

    /**
     * @var IDependencyInjector
     */
    protected $injector;
    
    /**
     * @var IDependencyFactory[]
     */
    protected $factories = [];

    public function __construct(IDependencyInjector $injector) {
        $this->factories[] = new RegistryBasedDependencyFactory();
        $this->injector = $injector;
    }

    /**
     * @return RegistryBasedDependencyFactory
     */
    public function registration() {
        return $this->factories[0];
    }
    
    public function resolve($type) {
        if (isset($this->singletons[$type])) return $this->singletons[$type];
        foreach ($this->factories as $fact) {
            $dependency = $fact->createDependency($type, $this, $this);
            if ($dependency !== null) {
                $flags = $fact->getDependencyFlags($type);
                if ($flags & DependencyFlags::Singleton) {
                    $this->singletons[$type] = $dependency;
                }
                return $dependency;
            }
        }
        // TODO: zależnie od opcji?
        if (class_exists($type)) {
            return $this->createInstance($type);
        }
        throw new \Exception("Unable to resolve dependency $type");
        return null;
    }

    public function createInstance($className) {
        if (func_num_args() > 1) {
            $args = func_get_args();
            array_shift($args);
            return $this->createInstanceArgs($className, $args);
        } else {
            return $this->createInstanceArgs($className, []);
        }
    }

    public function createInstanceArgs($className, array $constructorArgs) {
        $reflClass = new \ReflectionClass($className);
        $obj = $reflClass->newInstanceWithoutConstructor();
        $this->injector->injectInto($obj, $this);
        if (method_exists($obj, '__construct')) {
            call_user_func_array([$obj, '__construct'], $constructorArgs);
        }
        return $obj;
    }
}

class StdDependencyInjector implements IDependencyInjector {
    public function injectInto($object, IDependencyContainer $container) {
        $reflClass = new \ReflectionClass($object);
        $nsName = $reflClass->getNamespaceName();
        foreach ($reflClass->getProperties() as $reflProperty) {
            $dc = $reflProperty->getDocComment();
            $injectAnnotation = StrUtils::getAnnotationValue($dc, 'inject');
            if ($injectAnnotation !== null) {
                $type = StrUtils::getAnnotationValue($dc, 'var');
                if ($type !== null) {
                    $pdc = $reflProperty->getDeclaringClass();
                    $ns = $pdc == $reflClass ? $nsName : $pdc->getNamespaceName();
                    if ('\\' !== $type[0] && !empty($ns)) $type = $ns . '\\' . $type;
                    if ('\\' == $type[0]) $type = substr($type, 1);

                    $reflProperty->setAccessible(true);
                    $reflProperty->setValue($object, $container->resolve($type));
                }
            }
        }
    }
}

class RequestResponse {
    protected $headers = [];
    public $responseText;

    public function setHeader($name, $value) {
        $this->headers[$name] = $value;
    }

    public function getHeaders() {return $this->headers;}

    public function flush() {
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->responseText;
    }
}

class RequestContext {
    protected $session;
    protected $get;
    protected $post;
    protected $files;
    protected $cookies;

    public static function createFromCurrentRequest() {

    }
}

class Route {
    public $controllerId;
    public $actionId;
    public $params = [];
}

interface IViewManager {
    public function loadViewFromFile($viewFileName);
    public function loadView($controllerId, $actionId, $viewId);
}

interface IActionProcessor {
    /**
     * @param Route          $route
     * @param RequestContext $actionContext
     * @return RequestResponse
     */
    public function processRoute(Route $route, RequestContext $actionContext);
}

class ActionResultRedirectToRoute {
    /**
     * @var Route
     */
    protected $route;

    public function __construct($controllerId = null, $actionId = null) {
        $this->route = new Route();
        $this->route->controllerId = $controllerId;
        $this->route->actionId = $actionId;
    }

    /**
     * @param $controllerId
     * @return $this
     */
    public function ctrl($controllerId) {
        $this->route->controllerId = $controllerId;
        return $this;
    }

    /**
     * @param $actionId
     * @return $this
     */
    public function action($actionId) {
        $this->route->actionId = $actionId;
        return $this;
    }

    /**
     * @return $this
     */
    public function params() {
        $this->route->params = func_get_args();
        return $this;
    }

    /**
     * @return Route
     */
    public function getRoute() {
        return $this->route;
    }
}

class Controller implements IActionProcessor {
    /**
     * @var RequestContext
     */
    protected $request;

    /**
     * @var Route
     */
    protected $currentRoute;

    /**
     * @var Application
     * @inject
     */
    protected $application;

    /**
     * @var IRoutingService
     * @inject
     */
    protected $routingService;

    /**
     * @var IViewManager
     * @inject
     */
    protected $viewManager;

    protected $viewData = [];

    public function processRoute(Route $route, RequestContext $actionContext) {
        $savedRequest = $this->request;
        $savedRoute = $this->currentRoute;

        $this->request = $actionContext;
        $this->currentRoute = $route;

        $methodName = StrUtils::spinalCaseToCamelCase($route->actionId);
        if (!method_exists($this, $methodName)) {
            $methodName .= 'Action';
        }
        if (!method_exists($this, $methodName)) {
            throw new \Exception(sprintf('Unable to process action in controller %s (class %s): action method does not exist', $route->actionId, $route->controllerId, get_class($this)));
        }
        $result = call_user_func_array([$this, $methodName], $route->params);

        $response = new RequestResponse();
        if ($result instanceof ActionResultRedirectToRoute) {
            $url = $this->routingService->getRouteUrl($result->getRoute());
            $response->setHeader("Location", $url);
        } else {
            //...
        }
        // tu przetwarzamy wynik wywolania [if result inst of view => render view]
        $this->request = $savedRequest;
        $this->currentRoute = $savedRoute;
        return $response;
    }

    /**
     * @param string $actionId
     * @return ActionResultRedirectToRoute
     */
    public function redirect($actionId = null) {
        $urlBuilder = new ActionResultRedirectToRoute($this->currentRoute->controllerId, $actionId);
        return $urlBuilder;
    }

    public function view($viewId = null) {
        // zwraca obiekt IView, renderowanie jest w process action
        $actionId = '';
        $viewId = '';
        $view = $this->viewManager->loadView($this->currentRoute->controllerId, $actionId, $viewId);
        // przekazanie danych viewData do widoku
        return $view;
    }
}


interface IRoutingService {
    public function getRouteUrl(Route $route);

    /**
     * @return Route
     */
    public function getRouteFromRequest(); // return route from request or default route
}
 
class Application {

    /**
     * @var IDependencyContainer
     */
    protected $dc;

    public function __construct() {
        $this->dc = new DependencyContainer(new StdDependencyInjector());
        $this->dc->registration()->registerObject(Application::class, $this);
        $this->dc->registration()->registerObject(IDependencyContainer::class, $this->dc);
        $this->dc->registration()->registerObject(IActivator::class, $this->dc);
    }

    public function executeRoute(Route $route, RequestContext $actionContext) {
        $ap = $this->getActionProcessor($route);
        return $ap->processRoute($route, $actionContext);
    }

    public function executeCurrentRequest() {
        /* @var $routingSvc IRoutingService */
        $routingSvc = $this->dc->resolve(IRoutingService::class);
        $route = $routingSvc->getRouteFromRequest();
        $response = $this->executeRoute($route, RequestContext::createFromCurrentRequest());
        $response->flush();
    }

    /**
     * @param Route $route
     * @return IActionProcessor
     */
    protected function getActionProcessor(Route $route) {

    }
}