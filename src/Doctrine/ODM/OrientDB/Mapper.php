<?php

/*
 * This file is part of the Doctrine\OrientDB package.
 *
 * (c) Alessandro Nadalin <alessandro.nadalin@gmail.com>
 * (c) David Funaro <ing.davidino@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * This class is responsible to convert JSON objects to POPOs and viceversa, via
 * Doctrine's annotations library.
 *
 * @package    Doctrine\ODM
 * @subpackage OrientDB
 * @author     Alessandro Nadalin <alessandro.nadalin@gmail.com>
 * @author     David Funaro <ing.davidino@gmail.com>
 */

namespace Doctrine\ODM\OrientDB;

use Doctrine\ODM\OrientDB\Caster\Caster;
use Doctrine\ODM\OrientDB\Caster\CasterInterface;
use Doctrine\ODM\OrientDB\Mapper\Hydration\Result;
use Doctrine\ODM\OrientDB\Mapper\LinkTracker;
use Doctrine\ODM\OrientDB\Mapper\Annotations\Property as PropertyAnnotation;
use Doctrine\ODM\OrientDB\Mapper\Annotations\Reader;
use Doctrine\ODM\OrientDB\Mapper\Annotations\ReaderInterface as AnnotationreaderInterface;
use Doctrine\ODM\OrientDB\Types\Rid;
use Doctrine\OrientDB\Exception;
use Doctrine\OrientDB\Query\Query;
use Doctrine\OrientDB\Filesystem\Iterator\Regex as RegexIterator;
use Doctrine\OrientDB\Util\Inflector\Cached as Inflector;
use Doctrine\Common\Annotations\AnnotationReader;
use Symfony\Component\Finder\Finder;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\ArrayCache;

class Mapper
{
    protected $documentDirectories       = array();
    protected $enableMismatchesTolerance = false;
    protected $annotationReader;
    protected $inflector;
    protected $documentProxiesDirectory;
    protected $classMap                  = array();
    protected $cache;
    protected $caster;
    protected $castedProperties          = array();

    const ANNOTATION_PROPERTY_CLASS = 'Doctrine\ODM\OrientDB\Mapper\Annotations\Property';
    const ANNOTATION_CLASS_CLASS    = 'Doctrine\ODM\OrientDB\Mapper\Annotations\Document';
    const ORIENT_PROPERTY_CLASS     = '@class';

    /**
     * Instantiates a new Mapper, which stores proxies in $documentProxyDirectory
     *
     * @param string                    $documentProxyDirectory
     * @param AnnotationReaderInterface $annotationReader
     * @param Inflector                 $inflector
     */
    public function __construct(
        $documentProxyDirectory,
        AnnotationReaderInterface $annotationReader = null,
        Cache $cache = null,
        Inflector $inflector = null,
        Caster $caster = null
    ) {
        $this->documentProxyDirectory = $documentProxyDirectory;
        $this->annotationReader       = $annotationReader ?: new Reader;
        $this->cache                  = $cache ?: new ArrayCache;
        $this->inflector              = $inflector ?: new Inflector();
        $this->caster                 = $caster ?: new Caster($this);
    }

    /**
     * Enable or disable overflows' tolerance.
     *
     * @see   toleratesMismatches()
     * @param boolean $value
     */
    public function enableMismatchesTolerance($value = true)
    {
        $this->enableMismatchesTolerance = (bool) $value;
    }

    /**
     * Returns the internal object used to parse annotations.
     *
     * @return AnnotationReader
     */
    public function getAnnotationReader()
    {
        return $this->annotationReader;
    }

    /**
     * Returns the annotation of a class.
     *
     * @param  string   $class
     * @return Doctrine\ODM\OrientDB\Mapper\Class
     */
    public function getClassAnnotation($class)
    {
        $reader = $this->getAnnotationReader();
        $reflClass = new \ReflectionClass($class);
        $mappedDocumentClass = self::ANNOTATION_CLASS_CLASS;

        foreach ($reader->getClassAnnotations($reflClass) as $annotation) {
            if ($annotation instanceof $mappedDocumentClass) {
                return $annotation;
            }
        }

        return null;
    }

    /**
     * Returns the directories in which the mapper is going to look for
     * classes mapped for the Doctrine\OrientDB ODM.
     *
     * @return array
     */
    public function getDocumentDirectories()
    {
        return $this->documentDirectories;
    }

    /**
     * Returns the annotation of a property.
     *
     * @param ReflectionProperty $property
     * @return Doctrine\ODM\OrientDB\Mapper\Property
     */
    public function getPropertyAnnotation(\ReflectionProperty $property)
    {
        return $this->annotationReader->getPropertyAnnotation(
            $property, self::ANNOTATION_PROPERTY_CLASS
        );
    }

    /**
     * Takes an Doctrine\OrientDB JSON object and finds the class responsible to map that
     * object.
     * If the class is found, a new POPO is instantiated and the properties inside the
     * JSON object are filled accordingly.
     *
     * @param  stdClass $orientObject
     * @return Result
     * @throws DocumentNotFoundException
     */
    public function hydrate(\stdClass $orientObject)
    {
        $classProperty = self::ORIENT_PROPERTY_CLASS;

        if (property_exists($orientObject, $classProperty)) {
            $orientClass = $orientObject->$classProperty;

            if ($orientClass) {
                $linkTracker = new LinkTracker();
                $class       = $this->findClassMappingInDirectories($orientClass);
                $document    = $this->createDocument($class, $orientObject, $linkTracker);

                return new Result($document, $linkTracker);
            }

            throw new DocumentNotFoundException(self::ORIENT_PROPERTY_CLASS.' property empty.');
        }

        throw new DocumentNotFoundException(self::ORIENT_PROPERTY_CLASS.' property not found.');
    }

    /**
     * Hydrates an array of documents.
     *
     * @param  Array $json
     * @return Array
     */
    public function hydrateCollection(array $collection)
    {
        $records = array();

        foreach ($collection as $key => $record) {
            $records[$key] = $this->hydrate($record);
        }

        return $records;
    }

    /**
     * Sets the directories in which the mapper is going to look for
     * classes mapped for the Doctrine\OrientDB ODM.
     *
     * @param array $directories
     */
    public function setDocumentDirectories(array $directories)
    {
        $this->documentDirectories = array_merge(
            $this->documentDirectories,
            $directories
        );
    }

    /**
     * Creates a new Proxy $class object, filling it with the properties of
     * $orientObject.
     * The proxy class extends from $class and is used to implement
     * lazy-loading.
     *
     * @param  string      $class
     * @param  \stdClass   $orientObject
     * @param  LinkTracker $linkTracker
     * @return object of type $class
     */
    protected function createDocument($class, \stdClass $orientObject, LinkTracker $linkTracker)
    {
        $proxyClass = $this->getProxyClass($class);
        $document = new $proxyClass();

        $this->fill($document, $orientObject, $linkTracker);

        return $document;
    }

    /**
     * Casts a value according to how it was annotated.
     *
     * @param  Doctrine\ODM\OrientDB\Mapper\Annotations\Property  $annotation
     * @param  mixed                                              $propertyValue
     * @return mixed
     */
    protected function castProperty($annotation, $propertyValue)
    {
        $propertyId = $this->getCastedPropertyCacheKey($annotation->type, $propertyValue);

        if (!isset($this->castedProperties[$propertyId])) {
            $method = 'cast' . $this->inflector->camelize($annotation->type);

            $this->getCaster()->setValue($propertyValue);
            $this->getCaster()->setProperty('annotation', $annotation);
            $this->verifyCastingSupport($this->getCaster(), $method, $annotation->type);

            $this->castedProperties[$propertyId] = $this->getCaster()->$method();
        }

        return $this->castedProperties[$propertyId];
    }

    protected function getCastedPropertyCacheKey($type, $value)
    {
        return get_class() . "_casted_property_" . $type . "_" . serialize($value);
    }

    /**
     * Returns the caching layer of the mapper.
     *
     * @return Doctrine\Common\Cache\Cache
     */
    protected function getCache()
    {
        return $this->cache;
    }

    /**
     * Given an object and an Orient-object, it fills the former with the
     * latter.
     *
     * @param  object      $document
     * @param  \stdClass   $object
     * @param  LinkTracker $linkTracker
     * @return object
     */
    protected function fill($document, \stdClass $object, LinkTracker $linkTracker)
    {
        $propertyAnnotations = $this->getObjectPropertyAnnotations($document);

        foreach ($propertyAnnotations as $property => $annotation) {
            $documentProperty = $property;

            if ($annotation->name) {
                $property = $annotation->name;
            }

            if (property_exists($object, $property)) {
                $this->mapProperty(
                    $document,
                    $documentProperty,
                    $object->$property,
                    $annotation,
                    $linkTracker
                );
            }
        }

        return $document;
    }

    /**
     * Tries to find the PHP class mapping Doctrine\OrientDB's $OClass in each of the
     * directories where the documents are stored.
     *
     * @param  string $OClass
     * @return string
     * @throws Doctrine\ODM\OrientDB\OClassNotFoundException
     */
    protected function findClassMappingInDirectories($OClass)
    {
        foreach ($this->getDocumentDirectories() as $dir => $namespace) {
            if ($class = $this->findClassMappingInDirectory($OClass, $dir, $namespace)) {
                return $class;
            }
        }

        throw new OClassNotFoundException($OClass);
    }

    /**
     * Searches a PHP class mapping Doctrine\OrientDB's $OClass in $directory,
     * which uses the given $namespace.
     *
     * @param  string $OClass
     * @param  string $directory
     * @param  string $namespace
     * @return string|null
     */
    protected function findClassMappingInDirectory($OClass, $directory, $namespace)
    {
        $finder = new Finder();

        if (isset($this->classMap[$OClass])) {
            return $this->classMap[$OClass];
        }

        foreach ($finder->files()->name('*.php')->in($directory) as $file) {
            $class = $this->getClassByPath($file, $namespace);

            if (class_exists($class)) {
                $annotation = $this->getClassAnnotation($class);

                if ($annotation && $annotation->hasMatchingClass($OClass)) {
                    $this->classMap[$OClass] = $class;
                    return $class;
                }
            }
        }

        return null;
    }

    /**
     * Returns the fully qualified name of a class by its path
     *
     * @param  string $file
     * @param  string $namespace
     * @return string
     */
    public function getClassByPath($file, $namespace)
    {
        $absPath    = realpath($file);
        $namespaces = explode('/', $absPath);
        $start      = false;
        $i          = 0;
        $chunk      = explode('\\', $namespace);
        $namespace  = array_shift($chunk);

        while ($namespaces[$i] != $namespace) {
            unset($namespaces[$i]);

            if (!array_key_exists(++$i, $namespaces)) {
                break;
            }
        }

        $className = str_replace('.php', null, array_pop($namespaces));

        return '\\'. implode('\\', $namespaces) . '\\' . $className;
    }

    /**
     * Generate a proxy class for the given $class, writing it in the
     * filesystem.
     * A proxy class is a simple class extending $class, copying all its public
     * methods with some rules in order to implement lazy-loading
     *
     * @param type $class
     * @param type $proxyClassName
     * @param type $dir
     * @see   http://congoworient.readthedocs.org/en/latest/implementation-of-lazy-loading.html
     */
    protected function generateProxyClass($class, $proxyClassName, $dir)
    {
        $refClass           = new \ReflectionClass($class);
        $methods            = "";
        $namespace          = substr($class, 0, strlen($class) - strlen($proxyClassName) - 1);
        $importedNamespaces = "use Doctrine\ODM\OrientDB\Proxy\AbstractProxy;\n";
        $namespaceCollision = 1;
        $namespaceClasses   = array();

        foreach ($refClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $refMethod) {
            if (!$refMethod->isStatic()) {
                $parameters       = array();
                $parentParameters = array();

                //loop through each parameter
                foreach ($refMethod->getParameters() as $parameter) {
                    $parameterClass   = $parameter->getClass();
                    $parameterName    = $parameter->getName();
                    $parameterDefault = '';

                    //by checking isDefaultValueAvailable, we can allow for default values in the entities
                    if ($parameter->isDefaultValueAvailable()) {
                        $defaultValue = $parameter->getDefaultValue();
                        if (is_string($defaultValue)) {
                            $parameterDefault = " = '$defaultValue'";
                        } elseif ($defaultValue === null) {
                            $parameterDefault = ' = null';
                        } else {
                            $parameterDefault = ' = '.$defaultValue;
                        }
                    }

                    //if we have a parameterClass then lets implement typehinting
                    if ($parameterClass) {
                        //find the name of the class without its namespace
                        $parameterClassName = $parameterClass->getShortName();

                        //if the parameter class name is somehow the same as the class itself, we a have a collision
                        //right now we'll just suffix the class name with a number.
                        if ($parameterClassName == $refClass->getShortName()) {
                            $parameterClassName .= $namespaceCollision++;
                            $importedNamespaces .= "use {$parameterClass->getName()} as $parameterClassName;\n";
                        } elseif (strlen($parameterClass->getNamespaceName()) > 0) {
                            //if we have a parameterClassName that is not in the array of namespaces,
                            //then add it to the list
                            if (!in_array($parameterClassName, $namespaceClasses)) {
                                $namespaceClasses[] = $parameterClassName;
                                $importedNamespaces .= "use {$parameterClass->getName()};\n";
                            }
                        } else {
                            //here we've found a standard php class that needs no namespace
                            $parameterClassName = '\\'.$parameterClassName;
                        }

                        $parameters[] = $parameterClassName . ' $' . $parameterName . $parameterDefault;
                    } else {
                        $parameters[] = ($parameter->isArray() ? 'array ' : '' ) . '$' . $parameterName . $parameterDefault;
                    }

                    $parentParameters[] = '$' . $parameterName;
                }

                $parametersAsString       = implode(', ', $parameters);
                $parentParametersAsString = implode(', ', $parentParameters);

                $methods .= <<<EOT
    public function {$refMethod->getName()}($parametersAsString)
    {
        \$parent = parent::{$refMethod->getName()}($parentParametersAsString);

        if (!is_null(\$parent)) {
            if (\$parent instanceof AbstractProxy) {
                return \$parent();
            }

            return \$parent;
        }
    }

EOT;
            }
        }

        $proxy = <<<EOT
<?php

namespace Doctrine\OrientDB\Proxy$namespace;

$importedNamespaces
class $proxyClassName extends $class
{
$methods
}
EOT;

        file_put_contents("$dir/$proxyClassName.php", $proxy);
    }

    /**
     * Returns the caster instance.
     *
     * @return Doctrine\ODM\OrientDB\Caster\Caster
     */
    protected function getCaster()
    {
        return $this->caster;
    }

    /**
     * Returns the directory in which all the documents' proxy classes are
     * stored.
     *
     * @return string
     */
    protected function getDocumentProxyDirectory()
    {
        return $this->documentProxyDirectory;
    }

    /**
     * Retrieves the proxy class for the given $class.
     * If the proxy does not exists, it will be generated here at run-time.
     *
     * @param  string $class
     * @return string
     */
    protected function getProxyClass($class)
    {
        $namespaces = explode('\\', $class);
        $proxyClassFQN = "Doctrine\OrientDB\Proxy" . $class;
        $proxyClassName = array_pop($namespaces);

        if (!class_exists($proxyClassFQN)) {
            $dir = $this->getDocumentProxyDirectory() . '/Doctrine/OrientDB/Proxy';

            foreach ($namespaces as $namespace) {
                $dir = $dir . '/' . $namespace;

                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
            }

            $this->generateProxyClass($class, $proxyClassName, $dir);
        }

        return $proxyClassFQN;
    }

    /**
     * Returns all the annotations in the $document's properties.
     *
     * @param  mixed $document
     * @return array
     */
    protected function getObjectPropertyAnnotations($document)
    {
        $cacheKey = "object_property_annotations_" . get_class($document);

        if (!$this->getCache()->contains($cacheKey)) {
            $refObject   = new \ReflectionObject($document);
            $annotations = array();

            foreach ($refObject->getProperties() as $property) {
                $annotation = $this->getPropertyAnnotation($property);

                if ($annotation) {
                    $annotations[$property->getName()] = $annotation;
                }
            }

            $this->getCache()->save($cacheKey, $annotations);
        }

        return $this->getCache()->fetch($cacheKey);
    }

    /**
     * Given a $property and its $value, sets that property on the $given object
     * using a public setter.
     * The $linkTracker is used to verify if the property has to be retrieved
     * with an extra query, which is a domain the Mapper should not know about,
     * so it is used only to keep track of properties that the mapper simply
     * can't handle (a typical example is a @rid, which requires an extra query
     * to retrieve the linked entity).
     *
     * Generally the LinkTracker is used by a Manager after he call the
     * ->hydrate() method of its mapper, to verify that the object is ready to
     * be used in the userland application.
     *
     * @param mixed              $document
     * @param string             $property
     * @param string             $value
     * @param PropertyAnnotation $annotation
     * @param LinkTracker        $linkTracker
     */
    protected function mapProperty($document, $property, $value, PropertyAnnotation $annotation, LinkTracker $linkTracker)
    {
        if ($annotation->type) {
            try {
                $value = $this->castProperty($annotation, $value);
            } catch (Exception $e) {
                if ($annotation->isNullable()) {
                    $value = null;
                } else {
                    throw $e;
                }
            }

            if ($value instanceof Rid || $value instanceof Rid\Collection || is_array($value)) {
                $linkTracker->add($property, $value);
            }
        }

        $setter = 'set' . $this->inflector->camelize($property);

        if (method_exists($document, $setter)) {
            $document->$setter($value);
        }
        else {
            $refClass       = new \ReflectionObject($document);
            $refProperty    = $refClass->getProperty($property);

            if ($refProperty->isPublic()) {
                $document->$property = $value;
            } else {
                throw new Exception(
                    sprintf("%s has not method %s: you have to added the setter in order to correctly let Doctrine\OrientDB hydrate your object ?",
                    get_class($document),
                    $setter)
                );
            }
        }
    }


    /**
     * Checks whether the Mapper throws exceptions or not when encountering an
     * mismatch error during hydration.
     *
     * @return bool
     */
    public function toleratesMismatches()
    {
        return (bool) $this->enableMismatchesTolerance;
    }

    /**
     * Verifies if the given $caster supports casting with $method.
     * If not, an exception is raised.
     *
     * @param  Caster $caster
     * @param  string $method
     * @param  string $annotationType
     * @throws Doctrine\OrientDB\Exception
     */
    protected function verifyCastingSupport(Caster $caster, $method, $annotationType)
    {
        if (!method_exists($caster, $method)) {
            $message  = sprintf(
                'You are trying to map a property wich seems not to have a standard type (%s). Do you have a typo in your annotation?'.
                    'If you think everything\'s ok, go check on %s class which property types are supported.',
                $annotationType,
                get_class($caster)
            );

            throw new Exception($message);
        }
    }
}
