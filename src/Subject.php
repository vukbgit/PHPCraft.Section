<?php
/**
 * manages a PHPCraft subject
 * @author vuk <http://vuk.bg.it>
 */

namespace PHPCraft\Subject;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

abstract class Subject
{
    /**
    * subject name
    **/
    public $name;
    
    /**
    * HTTP objects
    **/
    protected $httpRequest;
    protected $httpResponse;
    protected $httpStream;
    
    /**
    * called route
    **/
    protected $route;
    
    /**
    * loaded configuration
    **/
    protected $configuration;
    
    /**
    * loaded translations
    **/
    protected $translations;
    
    /**
    * traits injected objects (to check injection), set by 
    * array [trait-name] => [injection-property-name-1, injection-property-name-2, ...]
    **/
    protected $traitsInjections;
    
    /**
    * traits dependencies from other traits (to avoid conflicts caused by overlapping 'use' statements)
    * set by traits setTraitDependenciesTrait-name methods
    * array [trait-name] => [required-other-trait-1, required-other-trait-2, ...]
    **/
    protected $traitsDependencies;
    
    /**
    * Action can be set:
    *   - as route['parameters']['action'] element
    *   - as route['properties']['action'] element
    *   - by calling setAction method
    **/
    protected $action = false;
    
    /**
    * Subject ancestors in current route, each is an array:
    *           [subject=>SUBJECT, primaryKey[FIELD1=>VALUE1,...]]
    **/
    public $ancestors = [];
    
    /**
    * Others subjects injected
    **/
    public $subjects = [];
    
    /**
     * Constructor.
     * @param string $name of the subject
     * @param object $http objects container
     *          ->request Psr\Http\Message\RequestInterface HTTP request handler instance
     *          ->response Psr\Http\Message\ResponseInterface HTTP response handler instance
     *          ->stream Psr\Http\Message\StreamInterface HTTP stream handler instance
     * @param array $configuration global configuration array, with application, areas and subject(s) elements
     * @param array $route route array with static properties ad URL extracted parameters
     **/
    protected function __construct(
        $name,
        &$http,
        &$configuration = array(),
        $route = array()
    ) {
        $this->name = $name;
        $this->httpRequest =& $http->request;
        $this->httpResponse =& $http->response;
        $this->httpStream =& $http->stream;
        $this->checkTraitsDependencies();
        $this->processRoute($route);
        $this->processConfiguration($configuration);        
    }
    
    /**
     * Utilized when reading data from inaccessible properties
     * @param string $propertyName
     * @throws Exception if property is not related to a used trait ('has' prefix) end it's not set
     **/
    public function __get($propertyName)
    {
        //check if property regards a used trait
        if(substr($propertyName, 0, 3) === 'has') {
            return isset($this->$propertyName) && $this->$propertyName === true;
        } else {
            throw new \Exception(sprintf('Undefined property %s', $propertyName));
        }
    }
    
    /**
     * build class name
     * @param string $subjectName of the subject
     * @throws Exception if property is not related to a used trait ('has' prefix) end it's not set
     **/
    protected static function buildClassName($subjectName)
    {
        return sprintf('%s\%s', APPLICATION_NAMESPACE, str_replace('-', '', ucwords($subjectName, '-')));
    }
    
    /**
     * Subject factory
     * @param string $subjectName of the subject
     * @param object $http objects container
     *          ->request Psr\Http\Message\RequestInterface HTTP request handler instance
     *          ->response Psr\Http\Message\ResponseInterface HTTP response handler instance
     *          ->stream Psr\Http\Message\StreamInterface HTTP stream handler instance
     * @param array $configuration global configuration array, with application, areas and subject(s) elements
     * @param array $route route array with static properties ad URL extracted parameters
     **/
    public static function factory($subjectName, &$http, &$configuration = array(), $route = array())
    {
        $subjectNameClass = self::buildClassName($subjectName);
        //load subject configuration
        $configuration['subjects'][$subjectName] = self::getConfiguration($subjectName);
        //instance subject
        return new $subjectNameClass(
            $subjectName,
            $http,
            $configuration,
            $route
        );
    }
    
    /**
     * Injects another subject
     * @param \PHPCraft\Subject\Subject $subject
     **/
    public function injectSubject(\PHPCraft\Subject\Subject $subject)
    {
        $this->subjects[$subject->name] = $subject;
    }
    
    /**
     * Gets a subject configuration
     * @param string $subjectName
     * @return array subject configuration
     **/
    public static function getConfiguration($subjectName)
    {
        return require sprintf('private/%s/configurations/%s.php', APPLICATION, $subjectName);
    }
    
    /**
     * Gets traits used by class
     * @return array of used traits names
     **/
    protected function getUsedTraits()
    {
        $reflection = new \ReflectionClass($this);
        $properties = $reflection->getProperties();
        $traits = [];
        foreach($properties as $property) {
            $name = $property->getName();
            if(substr($name, 0, 3) == 'has') {
                $traits[] = substr($name, 3);
            }
        }
        return $traits;
    }
    
    /**
     * Sets trait dependencies
     **/
    protected function setTraitDependencies($traitName, $dependencies)
    {
        $this->traitsDependencies[$traitName] = $dependencies;
    }
    
    /**
     * Loads traits dependencies from other traits
     **/
    protected function checkTraitsDependencies()
    {
        $traits = $this->getUsedTraits();
        $reflection = new \ReflectionClass($this);
        foreach($traits as $trait) {
            $methodName = 'setTraitDependencies' . $trait;
            if($reflection->hasMethod($methodName)) {
                $this->$methodName();
                $this->checkTraitDependencies($trait);
            }
        }
    }
    
    /**
     * Checks whether traits required by another trait are used
     * @param string $traitName
     * @param string $injectedProperty
     **/
    protected function checkTraitDependencies($traitName)
    {
        if(isset($this->traitsDependencies[$traitName])) {
            $reflection = new \ReflectionClass($this);
            foreach($this->traitsDependencies[$traitName] as $requiredTrait) {
                $propertyName = 'has' . $requiredTrait;
                if(!$reflection->hasProperty($propertyName) || !$this->$propertyName) {
                    throw new \Exception(sprintf('Class %s uses %s trait but %s required trait is not used', $this->buildClassName($this->name), $traitName, $requiredTrait));
                }
            }
        }
    }
    
    /**
     * Processes route
     * @param array $route
     **/
    protected function processRoute($route)
    {
        //ancestors
        //loop route parameters to get ancestors
        foreach($route['parameters'] as $parameter => $value) {
            preg_match('/ancestor[0-9]+/', $parameter, $matches, PREG_OFFSET_CAPTURE);
            if(!empty($matches)) {
                $this->ancestors[$value] = [];
            }
        }
        //loop ancestors to get primary keys values
        foreach($this->ancestors as $subject => $primaryKey) {
            //require ancestor configuration
            $configuration = self::getConfiguration($subject);
            //load ancestor translations
            //locale
            if(isset($configuration['locale']) && $configuration['locale']) {
                $this->loadApplicationTranslations($subject, $configuration['locale']);
            }
            //loop primary key values
            $primaryKey = $configuration['ORM']['primaryKey'];
            $primaryKey = is_array($primaryKey) ? $primaryKey : [$primaryKey];
            foreach($primaryKey as $field) {
                //check field into route parameters
                if(!isset($route['parameters'][$field])) {
                    throw new \Exception(sprintf('Current route contains subject %s as ancestor but does not contain parameter for primary key field %s', $ancestor['subject'], $field));
                }
                //store field value
                $this->ancestors[$subject][$field] = $route['parameters'][$field];
            }
        }
        //subject
        if(isset($route['properties']['subject'])) {
            $route['parameters']['subject'] = $route['properties']['subject'];
        }
        //extract action
        //static set action first
        if(isset($route['properties']['action'])) {
            $this->action = $route['properties']['action'];
        //action into URL otherwise
        } else if(isset($route['parameters']['action'])) {
            $this->action = $route['parameters']['action'];
        }
        //traits
        $traits = $this->getUsedTraits();
        $reflection = new \ReflectionClass($this);
        foreach($traits as $trait) {
            $methodName = 'processRouteTrait' . $trait;
            if($reflection->hasMethod($methodName)) {
                $this->$methodName($route);
            }
        }
        //store
        $this->route = $route;
    }
    
    /**
     * Processes configuration, checks for mandatory parameters, extracts found parameters
     * @param array $configuration
     **/
    protected function processConfiguration($configuration)
    {
        //locale
        if(isset($configuration['subjects'][$this->name]['locale']) && $configuration['subjects'][$this->name]['locale']) {
            $this->loadApplicationTranslations($this->name, $configuration['subjects'][$this->name]['locale']);
        }
        //traits
        $traits = $this->getUsedTraits();
        $reflection = new \ReflectionClass($this);
        foreach($traits as $trait) {
            $methodName = 'processConfigurationTrait' . $trait;
            if($reflection->hasMethod($methodName)) {
                $this->$methodName($configuration);
            }
        }
        //store
        $this->configuration = $configuration;
    }
    
    /**
     * builds path to area from route
     * @param $language language code to embed into URL when different from currently selected one
     **/
    protected function buildPathToArea($language = false)
    {
        $path = [];
        //language
        if($language) {
            $path[] = $language;
        } elseif(isset($this->route['parameters']['language'])) {
            $path[] = $this->route['parameters']['language'];
        }
        //area
        if(isset($this->route['parameters']['area'])) {
            $path[] = $this->route['parameters']['area'];
        }
        return $path;
    }
    
    /**
     * builds path to subject from route
     * @param $language language code to embed into URL when different from currently selected one
     **/
    protected function buildPathToSubject($language = false)
    {
        //area
        $path = $this->buildPathToArea($language);
        //ancestors
        foreach((array) $this->ancestors as $ancestor => $primaryKeyValues) {
            $path[] = $ancestor;
            $path[] = implode('|', array_values($primaryKeyValues));
        }
        //subject
        if(isset($this->route['parameters']['subject'])) {
            $path[] = $this->route['parameters']['subject'];
        }
        return $path;
    }
    
    /**
     * builds path to action from configurated action URL (if any)
     * @param string $action;
     * @param string $configurationUrl;
     **/
    protected function buildPathToAction($action, $configurationUrl = false, $primaryKeyValue = false)
    {
        //no configurated url, default
        if(!$configurationUrl) {
            $url = sprintf('/%s/%s', implode('/', $this->buildPathToSubject()), $action);
        } else {
            //asterisk in front means to use path to subject + url
            if(substr($configurationUrl,0,1) == '*') {
                $url = sprintf('%s/%s', implode('/', $this->buildPathToSubject()), substr($configurationUrl,1));
            } else {
            //NO asterisk in front means to use path to area + url
                $url = sprintf('%s/%s', implode('/', $this->buildPathToArea()), $configurationUrl);
            }
        }
        //insert primaryKey(s) value(s)
        $url = sprintf($url, $primaryKeyValue);
        return $url;
    }
    
    /**
     * builds path to an ancestor
     **/
    protected function buildPathToAncestor($lastAncestor)
    {
        $path = $this->buildPathToArea();
        foreach((array) $this->ancestors as $ancestor => $primaryKeyValues) {
            $path[] = $ancestor;
            if($ancestor != $lastAncestor) {
                $path[] = implode('|', array_values($primaryKeyValues));
            } else {
                $path[] = 'list';
                break;
            }
        }
        return $path;
    }
    
    /**
     * Sets trait injection dependency
     **/
    protected function setTraitInjections($traitName, $injectedProperties)
    {
        $this->traitsInjections[$traitName] = $injectedProperties;
    }
    
    /**
     * Checks that injections needed by traits have been performed
     **/
    protected function checkTraitsInjections()
    {
        $traits = $this->getUsedTraits();
        $reflection = new \ReflectionClass($this);
        foreach($traits as $trait) {
            $methodName = 'setTraitInjections' . $trait;
            if($reflection->hasMethod($methodName)) {
                $this->$methodName();
                $this->checkTraitInjections($trait);
            }
        }
    }
    
    /**
     * Checks whether required objects for a trais have been injected
     * @param string $traitName
     **/
    protected function checkTraitInjections($traitName)
    {
        if(isset($this->traitsInjections[$traitName])) {
            $reflection = new \ReflectionClass($this);
            foreach($this->traitsInjections[$traitName] as $propertyName) {
                if(!$reflection->hasProperty($propertyName) || !$this->$propertyName) {
                    throw new \Exception(sprintf('Class %s uses %s trait but %s property has not been injected', $this->buildClassName($this->name), $traitName, $propertyName));
                }
            }
        }
    }
    
    /**
     * Performs initialization tasks needed by traits calling the optional initTraitTrait-name method
     **/
    protected function traitsInit()
    {
        $traits = $this->getUsedTraits();
        $reflection = new \ReflectionClass($this);
        foreach($traits as $trait) {
            $methodName = 'initTrait' . $trait;
            if($reflection->hasMethod($methodName)) {
                $this->$methodName();
            }
        }
    }
    
    /**
     * adds a translations ini file content to subject translations
     * @param string $key key of translations array to store file content into
     * @param string $pathToIniFile file path from application root
     * @throws InvalidArgumentException if file is not found
     **/
    public function loadTranslations($key, $pathToIniFile)
    {
        $path = $pathToIniFile;
        if(!is_file($path)) {
            throw new \InvalidArgumentException(sprintf("Translation file not found into path %s", $path));
        } else {
            $this->translations[$key] = parse_ini_file($path,true);
        }
    }
    
    /**
     * adds an application level translations with the assumption that is stored into private/application-name/current-language
     * @param string $key key of translations array to store file content into
     * @param string $pathToIniFile file path inside private/application-name/curent-language/
     * @throws InvalidArgumentException if file is not found
     **/
    public function loadApplicationTranslations($key, $pathToIniFile)
    {
        $path = sprintf('private/%s/locales/%s/%s', APPLICATION, LANGUAGE, $pathToIniFile);
        $this->loadTranslations($key, $path);
    }
    
    /**
     * sets action
     * @param string $action
     **/
    public function setAction($action){
        $this->action = $action;
    }
    
    /**
     * turns action from slug-like form (with -) to method name (camelcase)
     * @param string $action
     **/
    public function sanitizeAction($action){
        return ucfirst(preg_replace_callback(
            '/[-_](.)/',
            function($matches) {
                return strtoupper($matches[1]);
            },
            $action
        ));
    }
    
    /**
     * tries to exec current action
     * @throws Exception if there is no action or method defined
     **/
    public function execAction()
    {
        //no action defined
        if(!$this->action) {
            throw new \Exception(sprintf('no action defined for subject %s', $this->name));
        }
        //exec method
        try {
            $this->checkTraitsInjections();
            $this->traitsInit();
            $this->{'exec'.$this->sanitizeAction($this->action)}();
        } catch(Exception $exception) {
        //no method defined
            throw new Exception(sprintf('no method for handling %s %s %s', AREA, $this->name, $this->action));
        }
    }
}