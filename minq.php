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

interface IDependencyDescriptor {
    public function getDependencyFlags();
    public function createDependency(IDependencyContainer $container, IActivator $activator);
}

interface IDependencyFactory {
    public function createDependency($type, IDependencyContainer $container, IActivator $activator);
    public function getDependencyFlags($type);
}

interface IActivator {
    public function createInstance($className); // next arguments = constructor parameters
    public function createInstanceArgs($className, array $constructorArgs);
    public function injectInto($instance);
}

interface IDependencyContainer {
    public function resolve($type);
}

interface IDependencyInjector {
    public function injectInto($object, IDependencyContainer $container);
}

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
        $this->factories[0] = new RegistryBasedDependencyFactory();
        $this->injector = $injector;
    }

    /**
     * @return RegistryBasedDependencyFactory
     */
    public function registration() {
        return $this->factories[0];
    }

    public function addFactory(IDependencyFactory $factory) {
        $this->factories[] = $factory;
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

    public function injectInto($instance) {
        $this->injector->injectInto($instance, $this);
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

interface IPropertyBag {
    public function __get($name);
    public function __set($name, $value);
    public function __isset($name);
}

class ArrayPropertyBag implements IPropertyBag {
    protected $__data;

    public function __construct(array &$data) {
        $this->__data =& $data;
    }

    public function __get($name) {return array_key_exists($name, $this->__data) ? $this->__data[$name] : null;}
    public function __set($name, $value) {$this->__data[$name] = $value;}
    public function __isset($name) {return array_key_exists($name, $this->__data);}
}

class RequestContext {
    public $session;
    public $get;
    public $post;
    public $files;
    public $cookies;

    /**
     * @return self
     */
    public static function createFromCurrentRequest() {
        $obj = new self();
        $obj->session = new ArrayPropertyBag($_SESSION);
        $obj->get = new ArrayPropertyBag($_GET);
        $obj->post = new ArrayPropertyBag($_POST);
        $obj->files = new ArrayPropertyBag($_FILES);
        $obj->cookies = new ArrayPropertyBag($_COOKIE);
        return $obj;
    }
}

class Route {
    public $controllerId;
    public $actionId;
    public $params = [];
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

class ActionResultView {
    protected $viewFilePath;
    protected $viewId;
    protected $controllerId;
    protected $actionId;
    protected $viewData;

    /**
     * @var Route
     */
    protected $currentRoute;

    public function __construct($viewId, $controllerId, $actionId, Route $currentRoute, $viewData) {
        $this->viewId = $viewId;
        $this->controllerId = $controllerId;
        $this->actionId = $actionId;
        $this->currentRoute = $currentRoute;
        $this->viewData = $viewData;
    }

    /**
     * @return $this
     */
    public function fromFile($filePath) {
        $this->viewFilePath = $filePath;
        return $this;
    }

    public function render(IViewManager $viewManager) {
        if (!empty($this->viewFilePath)) {
            $view = $viewManager->loadViewFromFile($this->viewFilePath);
        } else {
            $view = $viewManager->loadView();
        }
        $view->setData($this->viewData);
        return $view->render();
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

    public function processRoute(Route $route, RequestContext $actionContext) {
        $savedRequest = $this->request;
        $savedRoute = $this->currentRoute;

        $this->request = $actionContext;
        $this->currentRoute = $route;

        $methodName = StrUtils::spinalCaseToCamelCase($route->actionId) . 'Action';
        if (!method_exists($this, $methodName)) {
            throw new \Exception(sprintf('Unable to process action in controller %s (class %s): action method does not exist', $route->actionId, $route->controllerId, get_class($this)));
        }
        $reflMethod = new \ReflectionMethod($this, $methodName);
        $methodParams = $route->params;
        $mpk = -1;
        foreach ($reflMethod->getParameters() as $reflParam) {
            $mpk++;
            $name = $reflParam->getName();
            if (array_key_exists($name, $route->params)) $methodParams[$mpk] = $route->params[$name];
            elseif (isset($actionContext->get->$name)) $methodParams[$mpk] = $actionContext->get->$name;
        }
        $result = call_user_func_array([$this, $methodName], $methodParams);

        $response = new RequestResponse();
        if ($result instanceof ActionResultRedirectToRoute) {
            $url = $this->routingService->getRouteUrl($result->getRoute());
            $response->setHeader("Location", $url);
        } elseif ($result instanceof ActionResultView) {
            $response->responseText = $result->render($this->viewManager);
        } else {
            $response->responseText = (string)$result;
        }
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

    public function view($viewData = [], $viewId = null) {
        if (null === $viewId) {
            $backtrace = debug_backtrace();
            $caller = $backtrace[1]['function'];
            if ('Action' == substr($caller, -6)) {
                $viewId = StrUtils::camelCaseToSpinalCase(substr($caller, 0, -6));
            } else {
                throw new \Exception("Unable to obtain view id for caller $caller - caller is not action");
            }
        }
        $result = new ActionResultView($viewId, $this->currentRoute->controllerId, $this->currentRoute->actionId, $this->currentRoute, $viewData);
        return $result;
    }
}

interface ILayoutController {
    public function render(array $renderedViewSections);
}

interface IViewManager {
    /**
     * @return IView
     */
    public function loadViewFromFile($viewFileName, Route $currentRoute);

    /**
     * @return IView
     */
    public function loadView($controllerId, $actionId, $viewId, Route $currentRoute);
}

interface IView {
    /**
     * @return string Rendered output
     */
    public function render();

    public function setData($viewData);
    public function set($param, $value);
}

class StdViewTemplateOutput {
    protected $contents = [];
    protected $nameStack = [];
    protected $current = null;

    public function switchTo($name) {
        if (!empty($this->current)) {
            $this->contents[$this->current] = ob_get_contents();
            ob_clean();
            if (null === $name) {
                ob_end();
            }
        } elseif (null !== $name) {
            ob_start();
        }
        $this->current = $name;
    }

    public function openBlock($name) {
        $this->nameStack[] = $this->current;
        $this->switchTo($name);
    }

    public function closeBlock() {
        $lastName = array_pop($this->nameStack);
        $this->switchTo($lastName);
    }

    public function getContents() {
        return $this->contents;
    }
}

class StdViewTemplate {
    /**
     * @var Route
     */
    protected $currentRoute;

    public function initialize(Route $currentRoute) {
        $this->currentRoute = $currentRoute;
    }

    protected function renderField($fieldValue, $param1, $param2, $param3) {
        return $fieldValue;
    }
}

class TplSyntaxErrorException extends \Exception {}

class StdViewTemplateFactory {
    protected $loadedCache = [];

    public function loadFromString($content, $defaultClassName = null, $defaultNs = null, $defaultBaseClass = StdViewTemplate::class, $renderMethodName = 'render') {
        if (empty($defaultClassName)) $defaultClassName = 'minqtpl_' . abs(crc32($content));
        $cls = $this->loadClass($content, null, $defaultClassName, $defaultNs, $defaultBaseClass, $renderMethodName);
        $instance = new $cls();
        return $instance;
    }

    public function loadFromFile($fileName, $defaultClassName = null, $defaultNs = null, $defaultBaseClass = StdViewTemplate::class, $renderMethodName = 'render') {
        if (empty($defaultClassName)) $defaultClassName = 'minqtpl_' . strtr($fileName, ['.' => '_', '/' => '_', '\\' => '_', ':' => '_', '-' => '_']);
        $cls = $this->loadClass(file_get_contents($fileName), $fileName, $defaultClassName, $defaultNs, $defaultBaseClass, $renderMethodName);
        $instance = new $cls();
        return $instance;
    }

    protected function loadClass($contents, $fileName, $defaultClassName, $defaultNs, $defaultBaseClass, $renderMethodName) {
        $tplSettings = [];
        $php = '';
        if ('<?tpl' == substr($contents, 0, 5)) {
            $p = strpos($contents, '?>');
            if (false === $p) throw new TplSyntaxErrorException(!empty($fileName) ? "Tpl syntax error in file $fileName - unclosed tag <?tpl": "Tpl syntax error - unclosed tag <?tpl");
            $ic = substr($contents, 6, $p - 6);
            foreach (explode(' ', $ic) as $part) {
                if (empty($part)) continue;
                list ($k, $v) = explode(':', $part, 2);
                $tplSettings[$k] = $v;
            }
            $contents = substr($contents, $p + 2);
        }
        if (empty($tplSettings['class'])) $tplSettings['class'] = $defaultClassName;
        if (empty($tplSettings['ns'])) $tplSettings['ns'] = $defaultNs;
        if (empty($tplSettings['base'])) $tplSettings['base'] = $defaultBaseClass;

        $fullClassName = !empty($tplSettings['ns']) ? $tplSettings['ns'] . '\\' . $tplSettings['class'] : $tplSettings['class'];
        if (isset($this->loadedCache[$fullClassName])) {
            return $fullClassName;
        }

        if (!empty($tplSettings['ns'])) $php .= 'namespace ' . $tplSettings['ns'] . ";";
        $php .= 'class ' . $tplSettings['class'] . ' extends ' . $tplSettings['base'] . ' { ';

        $p = strpos($contents, '<?php');
        if (false !== $p) {
            $pend = strpos($contents, '?>', $p);
            $php .= substr($contents, $p + 5, $pend - $p - 5);
            $contents = substr($contents, 0, $p) . substr($contents, $pend + 2);
        }
        $contents = strtr($contents, ['<?' => '<?php ', '<?=' => '<?php echo ', '{{' => '<?php echo $this->renderField(', '}}' => ');?>']);

        $php .= "public function __render(\\minq\\StdViewTemplateOutput \$output) { ";
        $php .= ' ?' . '>' . $contents . '<' . "?php ";
        $php .= "} ";
        $php .= "public function $renderMethodName() { ";
        $php .= " \$out = new \\minq\\StdViewTemplateOutput();";
        $php .= " \$out->openBlock('default');";
        $php .= " \$this->__render(\$out);";
        $php .= " \$out->closeBlock();";
        $php .= " return \$out->getContents();";
        $php .= "} ";
        $php .= '}';

        $result = eval($php);
        if (false === $result) {
            throw new TplSyntaxErrorException(!empty($fileName) ? "Tpl syntax error in file $fileName": "Tpl syntax error");
        }
        $this->loadedCache[$fullClassName] = true;
        return $fullClassName;
    }
}

class StdView implements IView {
    /**
     * @var StdViewTemplateFactory
     */
    protected $tplFactory;

    /**
     * @var Route
     */
    protected $currentRoute;

    /**
     * @var IActivator
     */
    protected $activator;

    protected $viewFilePath;

    protected $viewData = [];

    /**
     * StdViewTemplateView constructor.
     * @param StdViewTemplateFactory $tplFactory
     * @param Route                  $currentRoute
     * @param IActivator             $activator
     * @param                        $viewFilePath
     */
    public function __construct(StdViewTemplateFactory $tplFactory, Route $currentRoute, IActivator $activator, $viewFilePath) {
        $this->tplFactory = $tplFactory;
        $this->currentRoute = $currentRoute;
        $this->activator = $activator;
        $this->viewFilePath = $viewFilePath;
    }

    public function render() {
        $template = $this->tplFactory->loadFromFile(
            $this->viewFilePath, null, null, StdViewTemplate::class, 'render'
        );
        $template->initialize($this->currentRoute);
        $this->activator->injectInto($template);
        foreach ($this->viewData as $k => $v) {
            $template->$k = $v;
        }
        $result = $template->render();
        // TODO: obsługa layoutu
        if (is_array($result)) $result = $result['default'];
        return $result;
    }

    public function setData($viewData) {
        foreach ($viewData as $k => $v) {
            $this->viewData[$k] = $v;
        }
    }

    public function set($param, $value) {
        $this->viewData[$param] = $value;
    }
}

class StdViewManager implements IViewManager {
    /**
     * @var StdViewTemplateFactory
     */
    protected $tplFactory;

    /**
     * @var IActivator
     * @inject
     */
    protected $activator;

    /**
     * @var IApplicationFileStructure
     * @inject
     */
    protected $appStructure;

    public function __construct() {
        $this->tplFactory = new StdViewTemplateFactory();
    }

    public function loadViewFromFile($viewFileName, Route $currentRoute) {
        $view = new StdView($this->tplFactory, $currentRoute, $this->activator, $viewFileName);
        return $view;
    }

    public function loadView($controllerId, $actionId, $viewId, Route $currentRoute) {
        return $this->loadViewFromFile($this->appStructure->getViewFilePath($controllerId, $viewId), $currentRoute);
    }
}

interface IRoutingService {
    public function getRouteUrl(Route $route);

    /**
     * @return Route
     */
    public function getRouteFromRequest(); // return route from request or default route
}

class StdRoutingService implements IRoutingService {
    /**
     * @var Route
     */
    protected $defaultRoute;

    /**
     * @param Route $defaultRoute
     */
    public function __construct(Route $defaultRoute) {
        $this->defaultRoute = $defaultRoute;
    }

    public function getRouteUrl(Route $route) {
        $url = 'index.php?route=' . $route->controllerId . '/' . $route->actionId;
        // TODO: parametry
        return $url;
    }

    /**
     * @return Route
     */
    public function getRouteFromRequest() {
        if (empty($_GET['route'])) return $this->defaultRoute;
        $rt = explode('/', $_GET['route']);
        $route = new Route();
        $route->controllerId = $rt[0];
        $route->actionId = $rt[1];
        // TODO: parametru
        return $route;
    }
}

interface IApplicationFileStructure {
    public function getControllerFilePath($controllerId);
    public function getViewFilePath($controllerId, $viewId);
    public function getLayoutFilePath($layoutId);
}

class StdApplicationFileStructure implements IApplicationFileStructure {
    public function getControllerFilePath($controllerId) {
        return sprintf('vc/controllers/%s.php', $controllerId);
    }

    public function getViewFilePath($controllerId, $viewId) {
        return sprintf('vc/views/%s/%s.tpl', $controllerId, $viewId);
    }

    public function getLayoutFilePath($layoutId) {
        return sprintf('vc/views/_layouts/%s.tpl', $layoutId);
    }
}
 
abstract class Application {

    /**
     * @var DependencyContainer
     */
    protected $dc;

    /**
     * @var IApplicationFileStructure
     */
    protected $fileStructure;

    public function __construct(IApplicationFileStructure $fileStructure = null) {
        $this->dc = new DependencyContainer(new StdDependencyInjector());
        $this->dc->registration()->registerObject(Application::class, $this);
        $this->dc->registration()->registerObject(IDependencyContainer::class, $this->dc);
        $this->dc->registration()->registerObject(IActivator::class, $this->dc);
        $this->fileStructure = $fileStructure ? $fileStructure : new StdApplicationFileStructure();
        $this->dc->registration()->registerClass(IApplicationFileStructure::class, $this->fileStructure);
        $this->dc->registration()->registerClass(IViewManager::class, StdViewManager::class);
        $this->dc->registration()->registerClass(IRoutingService::class, StdRoutingService::class);
    }

    protected function addDependencyFactory(IDependencyFactory $dependencyFactory) {
        $this->dc->addFactory($dependencyFactory);
    }

    /**
     * @return RegistryBasedDependencyFactory
     */
    protected function dependencyRegistry() {
        return $this->dc->registration();
    }

    protected function createRoutingService($type, IDependencyContainer $container, IActivator $activator) {
     // nie
    }

    protected function createViewManager($type, IDependencyContainer $container, IActivator $activator) {
        // nmie tak
        // użyć service descriptor object
    }

    protected abstract function run();

    public function executeRoute(Route $route, RequestContext $actionContext) {
        $ap = $this->getActionProcessor($route);
        return $ap->processRoute($route, $actionContext);
    }

    protected function executeCurrentRequest() {
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
        $controllerFile = $this->fileStructure->getControllerFilePath($route->controllerId);
        require_once($controllerFile);
        $controllerClass = StrUtils::spinalCaseToCamelCase($route->controllerId, true) . 'Controller';
        $controllerInstance = $this->dc->createInstance($controllerClass);
        return $controllerInstance;
    }
}