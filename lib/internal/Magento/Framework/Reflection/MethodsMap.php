<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Reflection;

use Zend\Code\Reflection\ClassReflection;
use Zend\Code\Reflection\MethodReflection;

/**
 * Gathers method metadata information.
 */
class MethodsMap
{
    const SERVICE_INTERFACE_METHODS_CACHE_PREFIX = 'serviceInterfaceMethodsMap';
    const BASE_MODEL_CLASS = 'Magento\Framework\Model\AbstractExtensibleModel';

    /**
     * @var \Magento\Framework\Cache\FrontendInterface
     */
    private $cache;

    /**
     * @var TypeProcessor
     */
    private $typeProcessor;

    /**
     * @var array
     */
    private $serviceInterfaceMethodsMap = [];

    /**
     * @var FieldNamer
     */
    private $fieldNamer;

    /**
     * @param \Magento\Framework\Cache\FrontendInterface $cache
     * @param TypeProcessor $typeProcessor
     * @param \Magento\Framework\Api\AttributeTypeResolverInterface $typeResolver
     * @param FieldNamer $fieldNamer
     */
    public function __construct(
        \Magento\Framework\Cache\FrontendInterface $cache,
        TypeProcessor $typeProcessor,
        \Magento\Framework\Api\AttributeTypeResolverInterface $typeResolver,
        FieldNamer $fieldNamer
    ) {
        $this->cache = $cache;
        $this->typeProcessor = $typeProcessor;
        $this->attributeTypeResolver = $typeResolver;
        $this->fieldNamer = $fieldNamer;
    }

    /**
     * Get return type by type name and method name.
     *
     * @param string $typeName
     * @param string $methodName
     * @return string
     */
    public function getMethodReturnType($typeName, $methodName)
    {
        return $this->getMethodsMap($typeName)[$methodName]['type'];
    }

    /**
     * Return service interface or Data interface methods loaded from cache
     *
     * @param string $interfaceName
     * @return array
     * <pre>
     * Service methods' reflection data stored in cache as 'methodName' => 'returnType'
     * ex.
     * [
     *  'create' => '\Magento\Customer\Api\Data\Customer',
     *  'validatePassword' => 'boolean'
     * ]
     * </pre>
     */
    public function getMethodsMap($interfaceName)
    {
        $key = self::SERVICE_INTERFACE_METHODS_CACHE_PREFIX . "-" . md5($interfaceName);
        if (!isset($this->serviceInterfaceMethodsMap[$key])) {
            $methodMap = $this->cache->load($key);
            if ($methodMap) {
                $this->serviceInterfaceMethodsMap[$key] = unserialize($methodMap);
            } else {
                $methodMap = $this->getMethodMapViaReflection($interfaceName);
                $this->serviceInterfaceMethodsMap[$key] = $methodMap;
                $this->cache->save(serialize($this->serviceInterfaceMethodsMap[$key]), $key);
            }
        }
        return $this->serviceInterfaceMethodsMap[$key];
    }

    /**
     * Use reflection to load the method information
     *
     * @param string $interfaceName
     * @return array
     */
    private function getMethodMapViaReflection($interfaceName)
    {
        $methodMap = [];
        $class = new ClassReflection($interfaceName);
        $baseClassMethods = false;
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Include all the methods of classes inheriting from AbstractExtensibleObject.
            // Ignore all the methods of AbstractExtensibleModel's parent classes
            if ($method->class === self::BASE_MODEL_CLASS) {
                $baseClassMethods = true;
            } elseif ($baseClassMethods) {
                // ReflectionClass::getMethods() sorts the methods by class (lowest in inheritance tree first)
                // then by the order they are defined in the class definition
                break;
            }

            if ($this->isSuitableMethod($method)) {
                $methodMap[$method->getName()] = $this->typeProcessor->getGetterReturnType($method);
            }
        }
        return $methodMap;
    }

    /**
     * Determines if the method is suitable to be used by the processor.
     *
     * @param \ReflectionMethod $method
     * @return bool
     */
    private function isSuitableMethod($method)
    {
        $isSuitableMethodType = !($method->isConstructor() || $method->isFinal()
            || $method->isStatic() || $method->isDestructor());

        $isExcludedMagicMethod = strpos($method->getName(), '__') === 0;
        return $isSuitableMethodType && !$isExcludedMagicMethod;
    }

    /**
     * Determines if the given method's on the given type is suitable for an output data array.
     *
     * @param string $type
     * @param string $methodName
     * @return bool
     */
    public function isMethodValidForDataField($type, $methodName)
    {
        $methods = $this->getMethodsMap($type);
        if (isset($methods[$methodName])) {
            $methodMetadata = $methods[$methodName];
            // any method with parameter(s) gets ignored because we do not know the type and value of
            // the parameter(s), so we are not able to process
            if ($methodMetadata['parameterCount'] > 0) {
                return false;
            }

            return $this->fieldNamer->getFieldNameForMethodName($methodName) !== null;
        }

        return false;
    }

    /**
     * If the method has only non-null return types
     *
     * @param string $type
     * @param string $methodName
     * @return bool
     */
    public function isMethodReturnValueRequired($type, $methodName)
    {
        $methods = $this->getMethodsMap($type);
        return $methods[$methodName]['isRequired'];
    }
}