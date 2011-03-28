<?php
/**
 * Pimcore
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.pimcore.org/license
 *
 * @copyright  Copyright (c) 2009-2010 elements.at New Media Solutions GmbH (http://www.elements.at)
 * @license    http://www.pimcore.org/license     New BSD License
 */


class Pimcore_Tool {

    /**
     * @static
     * @param string $key
     * @return void
     */
    public static function isValidKey($key){
        $key = (string) $key;
        $validChars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890-_.~";
        for($i=0;$i<strlen($key);$i++){
            if(strpos($validChars,$key[$i])===FALSE){
                Logger::error("Invalid character in filename: " . $key[$i] . " - complete filename is: " . $key);
                return false;
            }
        }
        return true;
    }

    public static function isValidPath($path) {
        if (preg_match("/[a-zA-Z0-9_~.\-\/]+/", $path, $matches)) {
            if ($matches[0] == $path) {
                return true;
            }
        }
        return false;
    }

    public static function getIdPathForElement($element) {

        $path = "";

        if ($element instanceof Document) {
            $nid = $element->getParentId();
            $ne = Document::getById($nid);
        }
        else if ($element instanceof Asset) {
            $nid = $element->getParentId();
            $ne = Asset::getById($nid);
        }
        else if ($element instanceof Object_Abstract) {
            $nid = $element->getO_parentId();
            $ne = Object_Abstract::getById($nid);
        }

        if ($ne) {
            $path = self::getIdPathForElement($ne, $path);
        }

        if ($element) {
            $path = $path . "/" . $element->getId();
        }

        return $path;
    }

    public static function isValidLanguage($language) {

        $languages = self::getValidLanguages();

        // if not configured, every language is valid
        if (!$languages) {
            return true;
        }

        if (in_array($language, $languages)) {
            return true;
        }

        return false;
    }

    public static function getValidLanguages() {

        $validLanguages = strval(Zend_Registry::get("pimcore_config_system")->general->validLanguages);

        if (empty($validLanguages)) {
            return array();
        }

        $validLanguages = str_replace(" ", "", $validLanguages);
        $languages = explode(",", $validLanguages);

        if (!is_array($languages)) {
            $languages = array();
        }

        return $languages;
    }

    public static function getRoutingDefaults() {

        try {
            $config = Zend_Registry::get("pimcore_config_system");

            // system default
            $routeingDefaults = array(
                "controller" => "default",
                "action" => "default",
                "module" => PIMCORE_FRONTEND_MODULE
            );

            // get configured settings for defaults
            $systemRoutingDefaults = $config->documents->toArray();

            foreach ($routeingDefaults as $key => $value) {
                if ($systemRoutingDefaults["default_" . $key]) {
                    $routeingDefaults[$key] = $systemRoutingDefaults["default_" . $key];
                }
            }

            return $routeingDefaults;
        }
        catch (Exception $e) {
            return array();
        }
    }


    public static function isFrontend() {
        $excludePatterns = array(
            "/^\/admin.*/",
            "/^\/install.*/",
            "/^\/plugin.*/",
            "/^\/webservice.*/"
        );
        foreach ($excludePatterns as $pattern) {
            if (preg_match($pattern, $_SERVER["REQUEST_URI"])) {
                return false;
            }
        }

        return true;
    }

    public static function useFrontendOutputFilters(Zend_Controller_Request_Abstract $request) {

        // check for module
        if (!self::isFrontend()) {
            return false;
        }


        // check for editmode
        if ($request->getParam("pimcore_editmode")) {
            return false;
        }

        // check for version
        if ($request->getParam("pimcore_version")) {
            return false;
        }

        // check for preview
        if ($request->getParam("pimcore_preview")) {
            return false;
        }

        // check for manually disabled ?pimcore_outputfilters_disabled=true
        if ($request->getParam("pimcore_outputfilters_disabled")) {
            return false;
        }


        return true;
    }

    public static function getHostname() {
        return $_SERVER["HTTP_HOST"];
    }

    public static function getCustomViewConfig() {
        $cvConfigFile = PIMCORE_CONFIGURATION_DIRECTORY . "/customviews.xml";
        $cvData = array();

        if (!is_file($cvConfigFile)) {
            $cvData = false;
        }
        else {
            $config = new Zend_Config_Xml($cvConfigFile);
            $confArray = $config->toArray();

            if (empty($confArray["views"]["view"])) {
                return array();
            }
            else if ($confArray["views"]["view"][0]) {
                $cvData = $confArray["views"]["view"];
            }
            else {
                $cvData[] = $confArray["views"]["view"];
            }

            foreach ($cvData as &$tmp) {
                $tmp["showroot"] = (bool) $tmp["showroot"];
            }
        }
        return $cvData;
    }

    /**
     * @static
     * @param  $sender
     * @param  $recipients
     * @param  $subject
     * @return Zend_Mail
     */
    public static function getMail($recipients,$subject) {

        $values = Zend_Registry::get("pimcore_config_system");
        $valueArray = $values->toArray();
        $emailSettings = $valueArray["email"];

        $mail = new Zend_Mail("UTF-8");
        $mail->setFrom($emailSettings['sender']['email'],$emailSettings['sender']['name']);
        $mail->setReplyTo($emailSettings['return']['email'],$emailSettings['return']['name']);
        if($emailSettings['method']=="smtp"){

            $config = array();
            if(!empty($emailSettings['smtp']['name'])){
                $config['name'] =  $emailSettings['smtp']['name'];
            }
            if(!empty($emailSettings['smtp']['ssl'])){
                $config['ssl'] =  $emailSettings['smtp']['ssl'];
            }
            if(!empty($emailSettings['smtp']['port'])){
                $config['port'] =  $emailSettings['smtp']['port'];
            }
            if(!empty($emailSettings['smtp']['auth']['method'])){
                $config['auth'] =  $emailSettings['smtp']['auth']['method'];
                $config['username'] = $emailSettings['smtp']['auth']['username'];
                $config['password'] = $emailSettings['smtp']['auth']['password'];
            }

            $transport = new Zend_Mail_Transport_Smtp($emailSettings['smtp']['host'], $config);
            //logger::log($transport);
            //logger::log($config);
            $mail->setDefaultTransport($transport);
        }
        if(is_array($recipients)){
            foreach($recipients as $recipient){
                $mail->addTo($recipient);
            }
        }
        $mail->setSubject($subject);
        return $mail;
    }
    
    public static function isHtmlResponse (Zend_Controller_Response_Abstract $response) {
        // check if response is html
        $headers = $response->getHeaders();
        foreach ($headers as $header) {
            if($header["name"] == "Content-Type") {
                if(strpos($header["value"],"html") === false) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    
    public static function getHttpClient ($type = "Zend_Http_Client") {

        $clientConfig = Zend_Registry::get("pimcore_config_system")->httpclient->toArray();
        $clientConfig["maxredirects"] = 0;
        $clientConfig["timeout"] = 3600;

        if(class_exists($type)) {
            $client = new $type(null, $clientConfig);
        } else {
            throw new Exception("Pimcore_Tool::getHttpClient: Unable to create an instance of $type");
        }

        return $client;
    }
    
    public static function getHttpData($url) {
        
        $client = self::getHttpClient();
        $client->setUri($url);

        $response = $client->request();

        if ($response->isSuccessful()) {
            return $response->getBody();
        }

        return false;
    }


    /*
     * Class Mapping Tools
     * They are used to map all instances of Element_Interface to an defined class (type)
     */

    /**
     * @static
     * @param  $sourceClassName
     * @return string
     */
    public static function getModelClassMapping($sourceClassName) {

        $targetClassName = $sourceClassName;

        if($map = self::getModelClassMappingConfig()) {
            $tmpClassName = $map->{$sourceClassName};
            if($tmpClassName) {
                if(class_exists($tmpClassName)) {
                    if(is_subclass_of($tmpClassName, $sourceClassName)) {
                        $targetClassName = $tmpClassName;
                    }
                }
            }
        }

        return $targetClassName;
    }

    /**
     * @static
     * @return Zend_Config_Xml
     */
    public static function getModelClassMappingConfig () {
        $mappingFile = PIMCORE_CONFIGURATION_DIRECTORY . "/classmap.xml";

        if(is_file($mappingFile) && is_readable($mappingFile)) {
            try {
                $map = new Zend_Config_Xml($mappingFile);
                return $map;
            } catch (Exception $e) {
                Logger::error("classmap.xml exists but it is not a valid Zend_Config_Xml configuration. Maybe there is a syntaxerror in the XML.");
            }
        }
        return;
    }

    /**
     * @static
     * @return void
     */
    public static function registerClassModelMappingNamespaces () {

        $autoloader = Zend_Loader_Autoloader::getInstance();
        if($map = self::getModelClassMappingConfig()) {
            $map = $map->toArray();

            foreach ($map as $targetClass) {
                $classParts = explode("_", $targetClass);
                $autoloader->registerNamespace($classParts[0]);
            }
        }
    }
}