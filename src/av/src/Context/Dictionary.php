<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Context;

use ArrayIterator;
use FFI;
use FFI\CData;
use Traversable;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * AVCodec Dictionary Class
 *
 * This class provides a PHP interface to AVDictionary using FFI.
 * It allows manipulation of key-value pairs used for codec options and metadata.
 *
 * Implements Countable, IteratorAggregate for collection-like behavior.
 *
 * @package Webrtc\AVCodec\Context
 */
class Dictionary implements SharedLibraryInterface, \Countable, \IteratorAggregate
{

    /**
     * @var int
     */
    private const IGNORE_SUFFIX = 2;
    private FFI $libAVCodec;

    /**
     * @var CData $dictionary FFI CData representing AVDictionary
     */
    private CData $dictionary;

    /**
     * Constructor - initializes the dictionary
     */
    public function __construct()
    {
        $this->initiateSharedLibrary();
        $this->dictionary = $this->libAVCodec->new("AVDictionary*");
    }

    /**
     * Magic setter - allows property-style assignment
     *
     * @param string $name Key name
     * @param mixed $value Value to set
     * @throws InvalidArgumentException If setting fails
     */
    public function __set(string $name, $value): void
    {
        $this->set($name, $value);
    }

    /**
     * Magic getter - allows property-style access
     *
     * @param string $name Key name
     * @return string The value
     * @throws InvalidArgumentException If key doesn't exist
     */
    public function __get(string $name)
    {
        $element = $this->libAVCodec->av_dict_get($this->dictionary, $name, null, 0);
        return $element != null ? FFI::string($element->value) : throw new \InvalidArgumentException("there is no value with the key " . $name);
    }

    /**
     * Invoke as function - returns the raw CData dictionary
     *
     * @return CData|null The FFI CData AVDictionary
     */
    public function __invoke(): ?CData
    {
        return $this->dictionary;
    }

    /**
     * Magic isset - checks if key exists
     *
     * @param string $name Key name
     * @return bool True if key exists
     */
    public function __isset(string $name): bool
    {
        $element = $this->libAVCodec->av_dict_get($this->dictionary, $name, null, 0);
        return $element !== null;
    }

    /**
     * Magic unset - removes a key-value pair
     *
     * @param string $name Key name
     * @throws InvalidArgumentException If key doesn't exist
     */
    public function __unset(string $name): void
    {

        $element = $this->libAVCodec->av_dict_get($this->dictionary, $name, null, 0);
        if ($element !== null) {
            $this->libAVCodec->av_dict_set(FFI::addr($this->dictionary), $name, null, 0);
        }
        else{
            throw new InvalidArgumentException("Couldn't set the option");
        }
    }

    /**
     * Update dictionary with multiple key-value pairs
     *
     * @param array $options Associative array of options
     */
    public function update(array $options): void
    {
        foreach ($options as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * Destructor - free dictionary memory
     */
    public function __destruct()
    {
        if ($this->dictionary != null) {
            $this->libAVCodec->av_dict_free(FFI::addr($this->dictionary));
        }
    }

    /**
     * Initialize shared library instance
     */
    public function initiateSharedLibrary(): void
    {
        global $libAVCodec;

        if ($libAVCodec instanceof FFI) {
            $this->libAVCodec = $libAVCodec;
        }
    }

    /**
     * Count elements - the number of returns of dictionary entries
     *
     * @return int Number of entries
     */
    public function count(): int
    {
        return $this->libAVCodec->av_dict_count($this->dictionary);
    }

    /**
     * Get iterator for foreach support
     *
     * @return Traversable Iterator for dictionary entries
     */
    public function getIterator(): Traversable
    {
        $elements = [];
        while (true) {
            $element = $this->libAVCodec->av_dict_get($this->dictionary, "", null, self::IGNORE_SUFFIX);

            if ($element !== null) {
                $elements [FFI::string($element->key)] = FFI::string($element->value);
                break;
            } else {
                break;
            }
        }

        return new ArrayIterator($elements);
    }

    /**
     * Set a key-value pair
     *
     * @param string $name Key name
     * @param mixed $value Value to set
     * @throws InvalidArgumentException If setting fails
     */
    private function set(string $name, mixed $value): void
    {
        $res = $this->libAVCodec->av_dict_set(FFI::addr($this->dictionary), $name, (string)$value, 0);

        if ($res < 0) {
            throw new \InvalidArgumentException("Couldn't set the option" . $name . ": " . $value);
        }
    }

    /**
     * Convert dictionary to a PHP array
     *
     * @return array Associative array of all key-value pairs
     */
    public function toArray(): array
    {
        return iterator_to_array($this->getIterator());
    }
}