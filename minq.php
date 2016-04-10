<?php
/**
 * Minquinity App Framework version 1.0.0
 */
namespace minq;

interface IDependencyFactory {
    public function createDependency($type, IDependencyContainer $container);
    public function getDependencyFlags($type);
}
 
interface IDependencyContainer {
    public function resolve($type);
    public function injectInto($object);
    public function registerInstance($type, $instance);
    public function registerFactory(IDependencyFactory $factory);
    public function register($type, $createCallback, $flags);
    public function registerClass($type, $implementingClassName, array $constructorParams = [], $flags);
}
 
class Controller {
}
 
class Application {
}