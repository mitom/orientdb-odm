<?php

/*
 * This file is part of the Congow\Orient package.
 *
 * (c) Alessandro Nadalin <alessandro.nadalin@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Class used in order to decouple Congow\Orient from the Doctrine dependency.
 * If you want to use a custom annotation reader library you should make your
 * reader extend this class.
 *
 * @package     Congow\Orient
 * @subpackage  ODM
 * @author      Alessandro Nadalin <alessandro.nadalin@gmail.com>
 */

namespace Congow\Orient\ODM\Mapper\Annotations;

use Congow\Orient\Contract\ODM\Mapper\Annotations\Reader as ReaderInterface;
use Doctrine\Common\Annotations\AnnotationReader;
use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Annotations\Parser;

use Doctrine\Common\Annotations\AnnotationRegistry;

class Reader implements ReaderInterface
{
    protected $reader = null;
    
    public function __construct(AnnotationReader $reader = null)
    {
        $this->reader = $reader ?: new AnnotationReader;
        
        AnnotationRegistry::registerAutoloadNamespace("Congow\Orient");
        AnnotationRegistry::registerFile( __DIR__ . '/Document.php');
        AnnotationRegistry::registerFile( __DIR__ . '/Property.php');
    }

    /**
     * Gets the annotations applied to a class.
     * 
     * @param ReflectionClass $class The ReflectionClass of the class from which
     * the class annotations should be read.
     * @return array An array of Annotations.
     */
    public function getClassAnnotations(\ReflectionClass $class)
    {
        return $this->reader->getClassAnnotations($class);
    }

    /**
     * Gets a class annotation.
     * 
     * @param ReflectionClass $class The ReflectionClass of the class from which
     * the class annotations should be read.
     * @param string $annotation The name of the annotation.
     * @return The Annotation or null, if the requested annotation does not exist.
     */
    public function getClassAnnotation(\ReflectionClass $class, $annotation)
    {
        return $this->reader->getClassAnnotation($class, $annotation);
    }
    
    /**
     * Gets the annotations applied to a property.
     * 
     * @param string|ReflectionProperty $property The name or ReflectionProperty of the property
     * from which the annotations should be read.
     * @return array An array of Annotations.
     */
    public function getPropertyAnnotations(\ReflectionProperty $property)
    {
        return $this->reader->getPropertyAnnotations($property);
    }
    
    /**
     * Gets a property annotation.
     * 
     * @param ReflectionProperty $property
     * @param string $annotation The name of the annotation.
     * @return The Annotation or null, if the requested annotation does not exist.
     */
    public function getPropertyAnnotation(\ReflectionProperty $property, $annotation)
    {
        return $this->reader->getPropertyAnnotation($property, $annotation);
    }
    
    /**
     * Gets the annotations applied to a method.
     * 
     * @param ReflectionMethod $property The name or ReflectionMethod of the method from which
     * the annotations should be read.
     * @return array An array of Annotations.
     */
    public function getMethodAnnotations(\ReflectionMethod $method)
    {
        return $this->reader->getMethodAnnotations($method);
    }
    
    /**
     * Gets a method annotation.
     * 
     * @param ReflectionMethod $method
     * @param string $annotation The name of the annotation.
     * @return The Annotation or null, if the requested annotation does not exist.
     */
    public function getMethodAnnotation(\ReflectionMethod $method, $annotation)
    {
        return $this->reader->getMethodAnnotation($method, $annotation);
    }
}