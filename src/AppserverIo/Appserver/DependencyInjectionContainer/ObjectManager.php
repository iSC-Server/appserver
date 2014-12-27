<?php

/**
 * AppserverIo\Appserver\DependencyInjectionContainer\ObjectManager
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * PHP version 5
 *
 * @category   Server
 * @package    Appserver
 * @subpackage Application
 * @author     Tim Wagner <tw@appserver.io>
 * @copyright  2014 TechDivision GmbH <info@appserver.io>
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link       https://github.com/appserver-io/appserver
 * @link       http://www.appserver.io
 */

namespace AppserverIo\Appserver\DependencyInjectionContainer;

use AppserverIo\Storage\StorageInterface;
use AppserverIo\Storage\GenericStackable;
use AppserverIo\Psr\Application\ManagerInterface;
use AppserverIo\Psr\Application\ApplicationInterface;
use AppserverIo\Appserver\DependencyInjectionContainer\Interfaces\ObjectManagerInterface;
use AppserverIo\Appserver\DependencyInjectionContainer\Parsers\BeanDescriptor;

/**
 * The object manager is necessary to load and provides information about all
 * objects related with the application itself.
 *
 * @category   Server
 * @package    Appserver
 * @subpackage Application
 * @author     Tim Wagner <tw@appserver.io>
 * @author     Bernhard Wick <bw@appserver.io>
 * @copyright  2014 TechDivision GmbH <info@appserver.io>
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link       https://github.com/appserver-io/appserver
 * @link       http://www.appserver.io
 */
class ObjectManager extends GenericStackable implements ObjectManagerInterface, ManagerInterface
{

    /**
     * Inject the data storage.
     *
     * @param \AppserverIo\Storage\StorageInterface $data The data storage to use
     *
     * @return void
     */
    public function injectData(StorageInterface $data)
    {
        $this->data = $data;
    }

    /**
     * Inject the application instance.
     *
     * @param \AppserverIo\Psr\Application\ApplicationInterface $application The application instance
     *
     * @return void
     */
    public function injectApplication(ApplicationInterface $application)
    {
        $this->application = $application;
    }

    /**
     * Inject the storage for the object descriptors.
     *
     * @param \AppserverIo\Storage\StorageInterface $objectDescriptors The storage for the object descriptors
     *
     * @return void
     */
    public function injectObjectDescriptors(StorageInterface $objectDescriptors)
    {
        $this->objectDescriptors = $objectDescriptors;
    }

    /**
     * Returns the application instance.
     *
     * @return \AppserverIo\Psr\Application\ApplicationInterface The application instance
     */
    public function getApplication()
    {
        return $this->application;
    }

    /**
     * Returns the storage with the object descriptors.
     *
     * @return \AppserverIo\Storage\StorageInterface The storage with the object descriptors
     */
    public function getObjectDescriptors()
    {
        return $this->objectDescriptors;
    }

    /**
     * Returns the absolute path to the web application.
     *
     * @return string The absolute path
     */
    public function getWebappPath()
    {
        return $this->getApplication()->getWebappPath();
    }

    /**
     * Has been automatically invoked by the container after the application
     * instance has been created.
     *
     * @param \AppserverIo\Psr\Application\ApplicationInterface $application The application instance
     *
     * @return void
     * @see \AppserverIo\Psr\Application\ManagerInterface::initialize()
     */
    public function initialize(ApplicationInterface $application)
    {

    }

    /**
     * Parses the passed deployment descriptor file for classes and instances that has
     * to be registered in the object manager.
     *
     * @param string      $deploymentDescriptor The deployment descriptor we want to parse
     * @param string|null $xpath                The XPath expression used to parse the deployment descriptor
     *
     * @return void
     */
    public function parseConfiguration($deploymentDescriptor, $xpath = null)
    {

        // query whether we found epb.xml deployment descriptor file
        if (file_exists($deploymentDescriptor) === false) {
            return;
        }

        // load the application config
        $config = new \SimpleXMLElement(file_get_contents($deploymentDescriptor));

        // intialize the session beans by parsing the nodes
        foreach ($config->xpath($xpath) as $node) {
            $this->processNode($node);
        }
    }

    /**
     * Process a XML deployment descriptor node for class informations.
     *
     * @param \SimpleXMLElement $node The XML deployment descriptor node to parse
     *
     * @return void
     */
    public function processNode(\SimpleXMLElement $node)
    {

        try {

            // load the object descriptor
            $objectDescriptor = DescriptorFactory::fromDeploymentDescriptor($node);

            // query whether we've to merge the configuration found in annotations
            if ($this->getObjectDescriptors()->has($objectDescriptor->getClassName())) { // merge the descriptors

                // load the existing descriptor
                $existingDescriptor = $this->getObjectDescriptors()->get($objectDescriptor->getClassName());

                // merge the descriptor => XML configuration overrides values from annotation
                $existingDescriptor->merge($objectDescriptor);

                // save the merged descriptor
                $this->getObjectDescriptors()->set($existingDescriptor->getClassName(), $existingDescriptor);

            } else {

                // save the descriptor
                $this->getObjectDescriptors()->set($objectDescriptor->getClassName(), $objectDescriptor);
            }

        } catch (\Exception $e) { // if class can not be reflected continue with next class

            // log an error message
            $application->getInitialContext()->getSystemLogger()->error($e->__toString());

            // proceed with the nexet bean
            continue;
        }
    }

    /**
     * Parses the passed directory for classes and instances that has to be registered
     * in the object manager.
     *
     * @param string $directory The directory to parse
     *
     * @return void
     */
    public function parseDirectory($directory)
    {

        // check if we've found a valid directory
        if (is_dir($directory) === false) {
            return;
        }

        // check directory for classes we want to register
        $service = $this->getApplication()->newService('AppserverIo\Appserver\Core\Api\DeploymentService');
        $phpFiles = $service->globDir($metaInfDir . DIRECTORY_SEPARATOR . '*.php');

        // iterate all php files
        foreach ($phpFiles as $phpFile) {
            $this->processFile($phpFile);
        }
    }

    /**
     * Parses the passed PHP file for class information necessary to register it
     * in the object manager.
     *
     * @param string $phpFile The path to the PHP file
     *
     * @return void
     */
    public function processFile($phpFile)
    {

        try {

            // cut off the META-INF directory and replace OS specific directory separators
            $relativePathToPhpFile = str_replace(DIRECTORY_SEPARATOR, '\\', str_replace($metaInfDir, '', $phpFile));

            // now cut off the first directory, that'll be '/classes' by default
            $pregResult = preg_replace('%^(\\\\*)[^\\\\]+%', '', $relativePathToPhpFile);
            $className = substr($pregResult, 0, -4);

            // we need a reflection class to read the annotations
            $reflectionClass = $this->getReflectionClass($className);

            // load the object descriptor
            $objectDescriptor = DescriptorFactory::fromReflectionClass($reflectionClass);

            if ($beanDescriptor->getName()) { // if we've a name
                $this->getObjectDescriptors()->set($objectDescriptor->getClassName(), $objectDescriptor);
            }

        } catch (\Exception $e) { // if class can not be reflected continue with next class

            // log an error message
            $application->getInitialContext()->getSystemLogger()->error($e->__toString());

            // proceed with the nexet bean
            continue;
        }
    }

    /**
     * Registers the value with the passed key in the container.
     *
     * @param string $key   The key to register the value with
     * @param object $value The value to register
     *
     * @return void
     */
    public function setAttribute($key, $value)
    {
        $this->data->set($key, $value);
    }

    /**
     * Returns the attribute with the passed key from the container.
     *
     * @param string $key The key the requested value is registered with
     *
     * @return mixed|null The requested value if available
     */
    public function getAttribute($key)
    {
        if ($this->data->has($key)) {
            return $this->data->get($key);
        }
    }

    /**
     * Returns a new reflection class intance for the passed class name.
     *
     * @param string $className The class name to return the reflection class instance for
     *
     * @return \AppserverIo\Lang\Reflection\ReflectionClass The reflection instance
     */
    public function newReflectionClass($className)
    {
        return $this->getApplication()->search('ProviderInterface')->newReflectionClass($className);
    }

    /**
     * Returns a reflection class intance for the passed class name.
     *
     * @param string $className The class name to return the reflection class instance for
     *
     * @return \AppserverIo\Lang\Reflection\ReflectionClass The reflection instance
     * @see \DependencyInjectionContainer\Interfaces\ProviderInterface::getReflectionClass()
     */
    public function getReflectionClass($className)
    {
        return $this->getApplication()->search('ProviderInterface')->getReflectionClass($className);
    }

    /**
     * Returns a reflection class intance for the passed class name.
     *
     * @param object $instance The instance to return the reflection class instance for
     *
     * @return \AppserverIo\Lang\Reflection\ReflectionClass The reflection instance
     * @see \DependencyInjectionContainer\Interfaces\ProviderInterface::newReflectionClass()
     * @see \DependencyInjectionContainer\Interfaces\ProviderInterface::getReflectionClass()
     */
    public function getReflectionClassForObject($instance)
    {
        return $this->getApplication()->search('ProviderInterface')->getReflectionClassForObject($instance);
    }

    /**
     * Returns a new instance of the passed class name.
     *
     * @param string      $className The fully qualified class name to return the instance for
     * @param string|null $sessionId The session-ID, necessary to inject stateful session beans (SFBs)
     * @param array       $args      Arguments to pass to the constructor of the instance
     *
     * @return object The instance itself
     */
    public function newInstance($className, $sessionId = null, array $args = array())
    {
        return $this->getApplication()->search('ProviderInterface')->newInstance($className, $sessionId, $args);
    }

    /**
     * Initializes the manager instance.
     *
     * @return void
     * @see \AppserverIo\Psr\Application\ManagerInterface::initialize()
     */
    public function getIdentifier()
    {
        return ObjectManagerInterface::IDENTIFIER;
    }
}
