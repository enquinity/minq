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

class ObjectDependency implements IDependencyDescriptor {
    protected $object;
    protected $flags;

    public function __construct($object, $flags = DependencyFlags::Singleton) {
        $this->object = $object;
        $this->flags = $flags;
    }

    public function getDependencyFlags() {
        return $this->flags;
    }

    public function createDependency(IDependencyContainer $container, IActivator $activator) {
        return $this->object;
    }
}

class ClassNameDependency implements IDependencyDescriptor {
    protected $className;
    protected $constructorParams;
    protected $flags;

    public function __construct($className, $constructorParams = [], $flags = DependencyFlags::Singleton) {
        $this->className = $className;
        $this->constructorParams = $constructorParams;
        $this->flags = $flags;
    }

    public function getDependencyFlags() {
        return $this->flags;
    }

    public function createDependency(IDependencyContainer $container, IActivator $activator) {
        return $activator->createInstanceArgs($this->className, $this->constructorParams);
    }
}

class CallbackDependency implements IDependencyDescriptor {
    protected $callback;
    protected $flags;

    public function __construct($callback, $flags = DependencyFlags::Singleton) {
        $this->callback = $callback;
        $this->flags = $flags;
    }

    public function getDependencyFlags() {
        return $this->flags;
    }

    public function createDependency(IDependencyContainer $container, IActivator $activator) {
        return call_user_func($this->callback, $container, $activator);
    }
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

    public function register($type, IDependencyDescriptor $descriptor) {
        $this->types[$type] = ['dd' => $descriptor, 'f' => $descriptor->getDependencyFlags()];
    }
    public function registerCallback($type, $createCallback, $flags = DependencyFlags::Singleton) {
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
        if (isset($t['dd'])) {
            return $t['dd']->createDependency($container, $activator);
        } elseif (isset($t['obj'])) {
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

trait InjectOnDemand {
    private $onDemandInjections;

    /**
     * @var \minq\IDependencyContainer
     * @inject
     */
    private $onDemandDependencyContainer;

    public function __get($propertyName) {
        if (isset($this->onDemandInjections[$propertyName])) {
            return $this->$propertyName = $this->onDemandDependencyContainer->resolve($this->onDemandInjections[$propertyName]);
        }
        throw new \Exception("Property $propertyName is undefined in class " . get_class($this));
    }

    public function initOnDemandInjections(array $onDemandInjections) {
        foreach ($onDemandInjections as $k => $v) unset($this->$k);
        $this->onDemandInjections = $onDemandInjections;
    }
}

class StdDependencyInjector implements IDependencyInjector {
    public function injectInto($object, IDependencyContainer $container) {
        $reflClass = new \ReflectionClass($object);
        $nsName = $reflClass->getNamespaceName();
        $onDemandInjections = [];
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

                    if (true === $injectAnnotation) {
                        $reflProperty->setAccessible(true);
                        $reflProperty->setValue($object, $container->resolve($type));
                    } elseif ('on-demand' === $injectAnnotation || 'onDemand' === $injectAnnotation) {
                        $onDemandInjections[$reflProperty->getName()] = $type;
                    }
                }
            }
        }
        if (count($onDemandInjections) > 0) {
            $object->initOnDemandInjections($onDemandInjections);
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

    public function __construct($controllerId = null, $actionId = null, array $params = []) {
        $this->controllerId = $controllerId;
        $this->actionId = $actionId;
        $this->params = $params;
    }
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
            $view = $viewManager->loadViewFromFile($this->viewFilePath, $this->currentRoute);
        } else {
            $view = $viewManager->loadView($this->controllerId, $this->actionId, $this->viewId, $this->currentRoute);
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
                ob_end_clean();
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

abstract class StdViewTemplate implements IView {
    /**
     * @var Route
     */
    protected $currentRoute;

    /**
     * @var StdViewTemplate
     */
    protected $layout;

    /**
     * @var IDependencyContainer
     * @inject
     */
    protected $_dc;

    public static $_metaData;

    public function initialize(Route $currentRoute) {
        $this->currentRoute = $currentRoute;
        $metaData = self::$_metaData;
        if (is_array($metaData) && !empty($metaData['layout'])) {
            $this->layout($metaData['layout']);
        }
    }

    protected function renderField($fieldValue, $param1 = null, $param2 = null, $param3 = null) {
        return $fieldValue;
    }

    protected function getLayout() {return $this->layout;}

    protected function layoutFromFile($layoutTplFile) {
        /* @var $vm IViewManager */
        $vm = $this->_dc->resolve(IViewManager::class);
        $this->layout = $vm->loadViewFromFile($layoutTplFile, $this->currentRoute);
        return $this->layout;
    }

    protected function layout($layoutId) {
        /* @var $as IApplicationStructure */
        $as = $this->_dc->resolve(IApplicationStructure::class);
        return $this->layoutFromFile($as->getLayoutFilePath($layoutId));
    }

    protected abstract function renderContents();

    public function render() {
        $contents = $this->renderContents();

        if ($this->layout !== null) {
            if (!is_array($contents)) $contents = ['default' => $contents];
            $this->layout->set('VIEW_PARTS', $contents);
            $contents = $this->layout->render();
        }
        if (is_array($contents)) $contents = $contents['default'];
        return $contents;
    }

    public function setData($viewData) {
        foreach ($viewData as $k => $v) {
            $this->$k = $v;
        }
    }

    public function set($param, $value) {
        $this->$param = $value;
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
        $tplMetaData = [];
        $php = '';
        if ('<?tpl' == substr($contents, 0, 5)) {
            $p = strpos($contents, '?>');
            if (false === $p) throw new TplSyntaxErrorException(!empty($fileName) ? "Tpl syntax error in file $fileName - unclosed tag <?tpl": "Tpl syntax error - unclosed tag <?tpl");
            $ic = substr($contents, 6, $p - 6);
            foreach (explode(' ', $ic) as $part) {
                if (empty($part)) continue;
                list ($k, $v) = explode(':', $part, 2);
                $tplMetaData[$k] = $v;
            }
            if ($contents[$p + 2] == "\r") $p++;  // skip new line char after closing tag
            if ($contents[$p + 2] == "\n") $p++;
            $contents = substr($contents, $p + 2);
        }
        if (empty($tplMetaData['class'])) $tplMetaData['class'] = $defaultClassName;
        if (empty($tplMetaData['ns'])) $tplMetaData['ns'] = $defaultNs;
        if (empty($tplMetaData['base'])) $tplMetaData['base'] = $defaultBaseClass;

        $fullClassName = !empty($tplMetaData['ns']) ? $tplMetaData['ns'] . '\\' . $tplMetaData['class'] : $tplMetaData['class'];
        if (isset($this->loadedCache[$fullClassName])) {
            return $fullClassName;
        }

        if (!empty($tplMetaData['ns'])) $php .= 'namespace ' . $tplMetaData['ns'] . ";";
        $php .= 'class ' . $tplMetaData['class'] . ' extends \\' . $tplMetaData['base'] . ' { ';

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
        $fullClassName::$_metaData = $tplMetaData;
        $this->loadedCache[$fullClassName] = true;
        return $fullClassName;
    }
}

class DefaultViewManager implements IViewManager {
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
     * @var IApplicationStructure
     * @inject
     */
    protected $appStructure;

    public function __construct() {
        $this->tplFactory = new StdViewTemplateFactory();
    }

    public function loadViewFromFile($viewFileName, Route $currentRoute) {
        //$view = new StdView($this->tplFactory, $currentRoute, $this->activator, $viewFileName);
        $view = $this->tplFactory->loadFromFile($viewFileName, null, null, StdViewTemplate::class, 'renderContents');
        $this->activator->injectInto($view);
        $view->initialize($currentRoute);
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

class DefaultRoutingService implements IRoutingService {
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
        $route = new Route($rt[0], $rt[1]);
        // TODO: parametru
        return $route;
    }
}

interface IApplicationStructure {
    public function getControllerFilePath($controllerId);
    public function getControllerClassName($controllerId);
    public function getViewFilePath($controllerId, $viewId);
    public function getLayoutFilePath($layoutId);
    public function getRoutingServiceDescriptor();
    public function getViewManagerDescriptor();
}

class DefaultApplicationStructure implements IApplicationStructure {
    public function getControllerFilePath($controllerId) {
        return sprintf('vc/controllers/%s.php', $controllerId);
    }

    protected function getControllerClassNamespace($controllerId) {
        return '';
    }

    public function getControllerClassName($controllerId) {
        $cls = StrUtils::spinalCaseToCamelCase($controllerId) . 'Controller';
        $ns = $this->getControllerClassNamespace($controllerId);
        if (!empty($ns)) $cls = $ns . '\\' . $cls;
        return $cls;
    }

    public function getViewFilePath($controllerId, $viewId) {
        return sprintf('vc/views/%s/%s.tpl', $controllerId, $viewId);
    }

    public function getLayoutFilePath($layoutId) {
        return sprintf('vc/views/_layouts/%s.tpl', $layoutId);
    }

    public function getRoutingServiceDescriptor() {
        return new ClassNameDependency(DefaultRoutingService::class, [new Route('home', 'index')]);
    }

    public function getViewManagerDescriptor() {
        return new ClassNameDependency(DefaultViewManager::class);
    }
}
 
abstract class Application {

    /**
     * @var DependencyContainer
     */
    protected $dc;

    /**
     * @var IApplicationStructure
     */
    protected $applicationStructure;

    public function __construct(IApplicationStructure $appStructure = null) {
        $this->dc = new DependencyContainer(new StdDependencyInjector());
        $this->dc->registration()->registerObject(Application::class, $this);
        $this->dc->registration()->registerObject(IDependencyContainer::class, $this->dc);
        $this->dc->registration()->registerObject(IActivator::class, $this->dc);
        $this->applicationStructure = $appStructure ? $appStructure : new DefaultApplicationStructure();
        $this->dc->registration()->registerClass(IApplicationStructure::class, $this->applicationStructure);
        $this->dc->registration()->register(IViewManager::class, $appStructure->getViewManagerDescriptor());
        $this->dc->registration()->register(IRoutingService::class, $appStructure->getRoutingServiceDescriptor());
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

    public abstract function run();

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
        $controllerFile = $this->applicationStructure->getControllerFilePath($route->controllerId);
        require_once($controllerFile);
        $controllerClass = $this->applicationStructure->getControllerClassName($route->controllerId);
        $controllerInstance = $this->dc->createInstance($controllerClass);
        return $controllerInstance;
    }
}