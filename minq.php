<?php
/*
 * Minquinity App Framework version 0.1.0
 */
namespace minq;

class DependencyFlags {
    const Singleton = 1;
    const Service = 1;
}

interface IDependencyFactory {
    public function createDependency($type, IDependencyContainer $container, IActivator $activator);
    public function getDependencyFlags($type);
}

interface IActivator {
    public function createInstance($className); // next arguments = contructor parameters
    public function createInstanceArgs($className, array $constructorArgs);
}

interface IDependencyContainer {
    public function resolve($type);
}

interface IDependencyInjector {
    public function injectInto($object, IDependencyContainer $container);
}

/*
    public function registerInstance($type, $instance);
    public function registerFactory(IDependencyFactory $factory);
    public function register($type, $createCallback, $flags);
    public function registerClass($type, $implementingClassName, array $constructorParams = [], $flags);
}*/

class RegistryBasedDependencyFactory implements IDependencyFactory {
    protected $types = [];

    public function register($type, $createCallback, $flags = DependencyFlags::Singleton) {
        $this->types[$type] = ['cb' => $createCallback, 'f' =>$flags];
    }
    public function registerClass($type, $implementingClassName, array $constructorParams = [], $flags = DependencyFlags::Singleton) {
        $this->types[$type] = ['cl' => $implementingClassName, 'cp' => $constructorParams, 'f' =>$flags];
    }
    public function createDependency($type, IDependencyContainer $container, IActivator $activator) {
        if (!isset($this->types[$type])) return null;
        $t = $this->types[$type];
        if (isset($t['cb'])) {
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
        $this->factories[] = new RegistryBasedDependencyFactory();
        $this->injector = $injector;
    }

    /**
     * @return IDependencyFactory
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

class ActionContext {

}

interface IActionProcessor {
    public function processAction($actionId, array $actionParams, ActionContext $actionContext);
}

class Controller implements IActionProcessor {
    public function processAction($actionId, array $actionParams, ActionContext $actionContext) {

    }
}
 
class Application {
}