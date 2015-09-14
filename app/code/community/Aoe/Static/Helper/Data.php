<?php

/**
 * Data helper
 *
 */
class Aoe_Static_Helper_Data extends Mage_Core_Helper_Abstract
{
    const MODE_PURGEVARNISHURL = 'purgeVarnishUrl';
    const MODE_PURGEVARNISHTAG = 'purgeVarnishTag';

    /** @var null|Aoe_Static_Model_Config */
    protected $_config = null;

    /** @var array */
    protected $_adapterInstances;

    /**
     * Flag for verbose logging
     */
    protected $verboseLogging = null;

    /**
     * @return Aoe_Static_Model_Config
     */
    public function getConfig()
    {
        if (is_null($this->_config)) {
            $this->_config = Mage::getModel('aoestatic/config');
        }
        return $this->_config;
    }

    /**
     * Checks if logging is enabled.
     *
     * @return bool
     */
    public function isVerboseLoggingEnabled() {
        if (is_bool($this->verboseLogging)) {
            return $this->verboseLogging;
        }
        //do not try to call getStoreConfigFlag too early in the bootstrap (e.g. when store is not yet initialized)
        if (Mage::registry('controller')) {
            $this->verboseLogging = Mage::getStoreConfigFlag('dev/aoestatic/verboseLogging');
            return $this->verboseLogging;
        } else {
            return false;
        }
    }

    /**
     * instantiates and caches active adapters
     *
     * @return array
     */
    protected function _getAdapterInstances()
    {
        if (is_null($this->_adapterInstances)) {

            $this->_adapterInstances = array();

            $selectedAdapterKeys = Mage::getStoreConfig('dev/aoestatic/purgeadapter');

            foreach ($this->trimExplode(',', $selectedAdapterKeys) as $key) {
                $adapters = $this->getConfig()->getAdapters();
                if (!isset($adapters[$key])) {
                    Mage::throwException('Could not find adapter configuration for adapter "'.$key.'"');
                }

                $adapter = $adapters[$key];
                $adapterInstance = Mage::getSingleton($adapter['model']);
                if (!$adapterInstance instanceof Aoe_Static_Model_Cache_Adapter_Interface) {
                    Mage::throwException('Adapter "'.$key.'" does not implement Aoe_Static_Model_Cache_Adapter_Interface');
                }
                $adapterInstance->setConfig($adapter['config']);

                $this->_adapterInstances[$key] = $adapterInstance;
            }
        }

        return $this->_adapterInstances;
    }

    /**
     * calls purgeAll on all adapter instances
     *
     * @return array
     */
    public function purgeAll()
    {
        // if Varnish is not enabled on admin don't do anything
        if (!Mage::app()->useCache('aoestatic')) {
            return array();
        }

        $result = array();
        foreach ($this->_getAdapterInstances() as $adapter) {
            /** @var Aoe_Static_Model_Cache_Adapter_Interface $adapter */
            $result = array_merge($result, $adapter->purgeAll());
        }
        return $result;
    }

    /**
     * calls purge on every adapter with given URLs
     *
     * @todo names/consts
     * @param array $urls
     * @param bool $queue
     * @return array
     */
    public function purge(array $urls, $queue = true)
    {
        // if Varnish is not enabled on admin don't do anything
        if (!Mage::app()->useCache('aoestatic')) {
            return array();
        }

        $urls = array_filter($urls);

        $result = array();
        // queue if async cache is enabled in config and not forced to purge directly
        if ($this->getConfig()->useAsyncCache() && $queue) {
            foreach ($urls as $url) {
                /** @var $asyncCache Aoe_AsyncCache_Model_Asynccache */
                $asyncCache = Mage::getModel('aoeasynccache/asynccache');
                $asyncCache->setTstamp(time())
                    ->setMode(Aoe_Static_Helper_Data::MODE_PURGEVARNISHURL)
                    ->setTags($url)
                    ->setStatus(Aoe_AsyncCache_Model_Asynccache::STATUS_PENDING)
                    ->save();
            }
        } else {
            foreach ($this->_getAdapterInstances() as $adapter) {
                /** @var Aoe_Static_Model_Cache_Adapter_Interface $adapter */
                $result = array_merge($result, $adapter->purge($urls));
            }
        }

        return $result;
    }

    /**
     * purge given tag(s)
     *
     * @param string|array $tags
     * @param bool         $withStore
     * @param bool         $queue
     *
     * @return array
     */
    public function purgeTags($tags, $withStore = false, $queue = true)
    {
        // if Varnish is not enabled on admin don't do anything
        if (!Mage::app()->useCache('aoestatic')) {
            return array();
        }

        if (!is_array($tags)) {
            $tags = array($tags);
        }

        /** @var Aoe_Static_Model_Cache_Control $cacheControl */
        $cacheControl = Mage::getSingleton('aoestatic/cache_control');
        foreach ($tags as $k => $v) {
            $tags[$k] = $cacheControl->normalizeTag($v, $withStore);
        }

        $result = array();
        // queue if async cache is enabled in config and not forced to purge directly
        if ($this->getConfig()->useAsyncCache() && $queue) {
            foreach ($tags as $tag) {
                /** @var $asyncCache Aoe_AsyncCache_Model_Asynccache */
                $asyncCache = Mage::getModel('aoeasynccache/asynccache');
                $asyncCache->setTstamp(time())
                    ->setMode(Aoe_Static_Helper_Data::MODE_PURGEVARNISHTAG)
                    ->setTags($tag)
                    ->setStatus(Aoe_AsyncCache_Model_Asynccache::STATUS_PENDING)
                    ->save();
            }
        } else {
            foreach ($this->_getAdapterInstances() as $adapter) {
                /** @var Aoe_Static_Model_Cache_Adapter_Interface $adapter */
                $result = array_merge($result, $adapter->purgeTags($tags));
            }
        }

        return $result;
    }

    /**
     * trim explode
     *
     * @param $delim
     * @param $string
     * @param bool $removeEmptyValues
     * @return array
     */
    public function trimExplode($delim, $string, $removeEmptyValues = false)
    {
        $explodedValues = explode($delim, $string);
        $result = array_map('trim', $explodedValues);
        if ($removeEmptyValues) {
            $temp = array();
            foreach ($result as $value) {
                if ($value !== '') {
                    $temp[] = $value;
                }
            }
            $result = $temp;
        }
        return $result;
    }

    /**
     *
     * @param string $message
     * @param integer $level
     * @param string $file
     * @param bool $forceLog
     */
    public function log($message, $level = null, $file = '', $forceLog = false) {
        if ($this->isVerboseLoggingEnabled()) {
            Mage::log($message, $level, $file, $forceLog);
        }
    }
}
