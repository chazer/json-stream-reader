<?php
/**
 * StreamReader.php
 *
 * @author: chazer
 * @created: 18.12.15 17:24
 */

namespace JsonStreamReader;

use JsonStreamingParser_Listener;

class StreamReader implements JsonStreamingParser_Listener
{
    private $_result;
    private $_stack;
    private $_keys;
    private $_level;

    private $listeners = [];
    private $listenersByKey = [];
    private $activeListeners = [];

    private $listenersCount;

    private $cbOnComplete;

    private $noStoreLevel;

    const NO_STORE = 0b0001;
    const SKIP_KEY = 0b0010;
    const ALL_KEYS = 0b0100;
    const SKIP_DOC = 0b0110;

    public function start_document()
    {
        $this->_stack = array();
        $this->_keys = array();
        $this->_level = 0;

        $this->initListeners();
    }

    public function end_document()
    {
    }

    public function whitespace($whitespace)
    {
    }

    public function start_object()
    {
        $this->startComplexValue('object');
    }

    public function end_object()
    {
        $this->endComplexValue();
    }

    public function start_array()
    {
        $this->pushKey('*');
        $this->startComplexValue('array');
    }

    public function end_array()
    {
        $this->popKey();
        $this->endComplexValue();
    }

    public function key($key)
    {
        $this->pushKey($key);
    }

    public function value($value)
    {
        $store = $this->onValue($value);
        $this->insertValue($value, $store);
    }

    private function startComplexValue($type)
    {
        $current_item = ['type' => $type, 'value' => []];
        array_push($this->_stack, $current_item);
    }

    private function endComplexValue()
    {
        $obj = array_pop($this->_stack);
        if (empty($this->_stack)) {
            $this->_result = $obj['value'];
        } else {
            $store = $this->onValue($obj['value']);
            $this->insertValue($obj['value'], $store);
        }
    }

    /**
     * Inserts the given value into the top value on the stack
     *
     * @param $value
     */
    private function insertValue($value, $store = true)
    {
        if (!$store) {
            end($this->_stack);
            if (current($this->_stack)['type'] === 'object') {
                $this->popKey();
            }
            return;
        }

        $current_item = array_pop($this->_stack);

        // Examine the current item, and then:
        //   - if it's an object, associate the newly-parsed value with the most recent key
        //   - if it's an array, push the newly-parsed value to the array
        if ($current_item['type'] === 'object') {
            $current_item['value'][$this->popKey()] = $value;
        } else {
            array_push($current_item['value'], $value);
        }

        // Replace the current item on the stack.
        array_push($this->_stack, $current_item);
    }

    protected function pushKey($key)
    {
        array_push($this->_keys, $key);
        $this->_level++;
        $this->onOpenKey($this->_level - 1, $key);
    }

    protected function popKey()
    {
        $return = array_pop($this->_keys);
        $this->_level--;
        $this->onCloseKey($this->_level, $return);
        return $return;
    }

    protected function dispatchValue($value)
    {
        if (isset($this->activeListeners[$this->_level])) {
            foreach ($this->activeListeners[$this->_level] as &$l) {
                $f = call_user_func($l['callback'], $this->_keys, $value);
                if ($f & self::NO_STORE) {
                    $this->noStoreLevel = $this->_level;
                }
                if ($f & self::SKIP_KEY) {
                    if ($f & self::ALL_KEYS) {
                        $this->stopListener($l);
                    } else {
                        $this->disableListener($l['index']);
                    }
                }
            }
        }
    }

    protected function parseKeys($path)
    {
        return explode('.', $path);
    }

    public function addListener($path, $callback)
    {
        $keys = $this->parseKeys($path);
        $this->internalAddListener([
            'path' => $path,
            'callback' => $callback,
            'keys' => $keys,
        ]);
    }

    protected function internalAddListener($l)
    {
        end($this->listeners);
        $index = key($this->listeners) + 1;
        $this->listeners[$index] = & $l;
        $l['index'] = $index;

        $keys = $l['keys'];
        $flags = array_keys($keys);
        $l['flags'] = $flags;

        if (false === ($stopKey = array_search('*', $keys))) {
            $stopKey = count($keys);
        }
        $l['stop_key'] = $stopKey - 1;

        foreach ($flags as $level) {
            $key = $l['keys'][$level];
            $this->listenersByKey[$level][$key][] = & $l;
        }
    }

    /**
     * Restart all listeners
     */
    protected function initListeners()
    {
        $this->listenersCount = 0;;
        foreach ($this->listeners as &$l) {
            $l['counter'] = count($l['flags']);
            $l['keys_count'] = count($l['keys']);
            $this->startListener($l);
        }
    }

    protected function onOpenKey($level, $key)
    {
        $keys = array_unique(['*', $key]);
        foreach ($keys as $key) {
            if (!isset($this->listenersByKey[$level][$key])) {
                continue;
            }
            foreach ($this->listenersByKey[$level][$key] as &$l) {
                if ($l['done']) {
                    continue;
                }
                $l['counter']--;
                if ($l['counter'] === 0) {
                    $this->enableListener($l['index']);
                }
            }
        }
    }

    protected function onCloseKey($level, $key)
    {
        if(isset($this->noStoreLevel) && $this->noStoreLevel > $level) {
            $this->noStoreLevel = null;
        }

        $keys = array_unique(['*', $key]);
        foreach ($keys as $key) {
            if (!isset($this->listenersByKey[$level][$key])) {
                continue;
            }
            foreach ($this->listenersByKey[$level][$key] as &$l) {
                if ($l['done']) {
                    continue;
                }
                $l['counter']++;
                if ($l['stop_key'] === $level) {
                    $this->stopListener($l);
                } else {
                    $this->disableListener($l['index']);
                }
            }
        }
    }

    protected function onValue($value)
    {
        $store = false;
        if ($this->listenersCount > 0) {
            $store = true;
            $this->dispatchValue($value);
        }
        if (isset($this->noStoreLevel) && $this->noStoreLevel === $this->_level) {
            $store = false;
        }
        return $store;
    }

    /**
     * Run listener
     *
     * @param $index
     */
    protected function enableListener($index)
    {
        $l = & $this->listeners[$index];
        $size = $l['keys_count'];
        $this->activeListeners[$size][$index] = & $l;
    }

    /**
     * Pause listener
     *
     * @param $index
     */
    protected function disableListener($index)
    {
        $l = $this->listeners[$index];
        $size = $l['keys_count'];
        unset($this->activeListeners[$size][$index]);
    }

    /**
     * Mark listener as started
     *
     * @param $listener
     */
    protected function startListener(&$listener)
    {
        $listener['done'] = false;
        $this->listenersCount++;
    }

    /**
     * Mark listener as completed
     *
     * @param $listener
     */
    protected function stopListener(&$listener)
    {
        $this->disableListener($listener['index']);
        $listener['done'] = true;
        $this->listenersCount--;
        if ($this->listenersCount === 0) {
            $this->onComplete();
        }
    }

    protected function setCompleteCallback($callback)
    {
        $this->cbOnComplete = $callback;
    }

    protected function onComplete()
    {
        if (is_callable($this->cbOnComplete)) {
            call_user_func($this->cbOnComplete);
        }
    }

    /**
     * @param resource $resource
     * @param array $options options:
     * <br> - 'close' — bool, close stream if no more active listeners
     * @throws \Exception
     */
    public function parse($resource, $options = [])
    {
        $close = isset($options['close']) ? $options['close'] : false;

        if ($close) {
            $this->setCompleteCallback(function () use ($resource) {
                fclose($resource);
            });
        }

        try {
            $parser = new \JsonStreamingParser_Parser($resource, $this);
            $parser->parse();
        } catch (\Exception $e) {
            if ($close && is_resource($resource)) {
                fclose($resource);
            }
            throw $e;
        }
    }
}
