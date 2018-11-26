<?php

namespace Beginner\Container;

use Closure;
use DomainException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionParameter;
use ReflectionException;
use Exception;

class Container implements ContainerInterface
{
    protected static $instance;

    protected $binds = [];

    protected $instances = [];

    protected $with = [];

    protected $buildStack = [];

    protected $contextual = [];

    protected $abstractAliases = [];

    public function get($id)
    {
        // TODO: Implement get() method.
    }

    public function has($id)
    {
        // TODO: Implement has() method.
    }

    /**
     * @param $abstract
     * @param \Closure|string|null $concrete
     */
    public function bind(string $abstract, $concrete = null)
    {
        $this->dropStaleInstances($abstract);

        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        if ($concrete instanceof Closure) {
            $this->binds[$abstract] = $concrete;
        } else {
            $this->instances[$abstract] = $concrete;
        }
    }

    /**
     * 构建闭包，将载入的模块构建为一个闭包，起到延迟加载的作用
     * @param string $abstract
     * @param string $concrete
     * @return Closure
     */
    protected function getClosure(string $abstract, string $concrete)
    {
        /**
         * @param Container $container
         * @param array $parameters
         * @return mixed
         */
        return function (Container $container, $parameters = []) use ($abstract, $concrete) {
            if ($abstract === $concrete) {
                return $container->build($concrete);
            }

            return $container->make($concrete, $parameters);
        };
    }

    /**
     * @param $concrete
     * @return mixed|object
     * @throws ReflectionException
     * @throws Exception
     */
    public function build($concrete)
    {
        /**
         * 如果是闭包直接执行
         */
        if ($concrete instanceof Closure) {
            return $concrete($this, $this->getLastParameterOverride());
        }

        $reflector = new ReflectionClass($concrete);

        /**
         * 如果对象无法被实例化，返回异常
         */
        if (!$reflector->isInstantiable()) {
            throw new DomainException($concrete . '传递对象无法被实例化');
        }

        $this->buildStack[] = $concrete;

        $constructor = $reflector->getConstructor();

        /**
         * 利用反射类获取即将实例化对象的构造函数，如果没有依赖则可直接实例化
         */
        if (is_null($constructor)) {
            array_pop($this->buildStack);

            return new $concrete;
        }

        $dependencies = $constructor->getParameters();

        /**
         * 如果构造函数中存在依赖，则使用反射创建所依赖的每个实例，向其注入
         */
        $instances = $this->resolveDependencies(
            $dependencies
        );

        array_pop($this->buildStack);

        /**
         * 创建实例
         */
        return $reflector->newInstanceArgs($instances);
    }

    /**
     * @param array $dependencies
     * @return array
     * @throws Exception
     */
    protected function resolveDependencies(array $dependencies)
    {
        $results = [];

        foreach ($dependencies as $dependency) {
            // If this dependency has a override for this particular build we will use
            // that instead as the value. Otherwise, we will continue with this run
            // of resolutions and let reflection attempt to determine the result.
            if ($this->hasParameterOverride($dependency)) {
                $results[] = $this->getParameterOverride($dependency);

                continue;
            }

            // If the class is null, it means the dependency is a string or some other
            // primitive type which we can not resolve since it is not a class and
            // we will just bomb out with an error since we have no-where to go.
            $results[] = is_null($dependency->getClass())
                ? $this->resolvePrimitive($dependency)
                : $this->resolveClass($dependency);
        }

        return $results;
    }

    /**
     * Resolve a class based dependency from the container.
     *
     * @param  \ReflectionParameter  $parameter
     * @return mixed
     *
     * @throws Exception
     */
    protected function resolveClass(ReflectionParameter $parameter)
    {
        try {
            return $this->make($parameter->getClass()->name);
        }

            // If we can not resolve the class instance, we will check to see if the value
            // is optional, and if it is we will return the optional parameter value as
            // the value of the dependency, similarly to how we do this with scalars.
        catch (Exception $e) {
            if ($parameter->isOptional()) {
                return $parameter->getDefaultValue();
            }

            throw $e;
        }
    }

    /**
     * Resolve a non-class hinted primitive dependency.
     * @param ReflectionParameter $parameter
     * @return mixed
     */
    protected function resolvePrimitive(ReflectionParameter $parameter)
    {
        if (! is_null($concrete = $this->getContextualConcrete('$'.$parameter->name))) {
            return $concrete instanceof Closure ? $concrete($this) : $concrete;
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        $this->unresolvablePrimitive($parameter);

        return null;
    }

    /**
     * Get the contextual concrete binding for the given abstract.
     *
     * @param  string  $abstract
     * @return string|null
     */
    protected function getContextualConcrete($abstract)
    {
        if (! is_null($binding = $this->findInContextualBindings($abstract))) {
            return $binding;
        }

        // Next we need to see if a contextual binding might be bound under an alias of the
        // given abstract type. So, we will need to check if any aliases exist with this
        // type and then spin through them and check for contextual bindings on these.
        if (empty($this->abstractAliases[$abstract])) {
            return null;
        }

        foreach ($this->abstractAliases[$abstract] as $alias) {
            if (! is_null($binding = $this->findInContextualBindings($alias))) {
                return $binding;
            }
        }
        return null;
    }

    protected function unresolvablePrimitive(ReflectionParameter $parameter)
    {
        $message = "Unresolvable dependency resolving [$parameter] in class {$parameter->getDeclaringClass()->getName()}";

        throw new DomainException($message);
    }

    protected function dropStaleInstances(string $abstract)
    {
        unset($this->instances[$abstract]);
    }

    public function make(string $abstract, array $parameters = [])
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        array_unshift($parameters, $this);
        return call_user_func_array($this->binds[$abstract], $parameters);
    }

    /**
     * 确定给定的依赖项是否具有参数重写
     *
     * @param  \ReflectionParameter  $dependency
     * @return bool
     */
    protected function hasParameterOverride($dependency)
    {
        return array_key_exists(
            $dependency->name, $this->getLastParameterOverride()
        );
    }

    /**
     * Get the last parameter override.
     *
     * @return array
     */
    protected function getLastParameterOverride()
    {
        return count($this->with) ? end($this->with) : [];
    }

    /**
     * Get a parameter override for a dependency.
     *
     * @param  \ReflectionParameter  $dependency
     * @return mixed
     */
    protected function getParameterOverride($dependency)
    {
        return $this->getLastParameterOverride()[$dependency->name];
    }

    /**
     * Find the concrete binding for the given abstract in the contextual binding array.
     *
     * @param  string  $abstract
     * @return string|null
     */
    protected function findInContextualBindings($abstract)
    {
        if (isset($this->contextual[end($this->buildStack)][$abstract])) {
            return $this->contextual[end($this->buildStack)][$abstract];
        }
        return null;
    }
}