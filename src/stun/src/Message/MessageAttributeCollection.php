<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\STUN\Message;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use Webrtc\STUN\Enum\MessageAttribute;

/**
 * Class representing a collection of message attributes.
 */
class MessageAttributeCollection implements Countable, IteratorAggregate
{
    /**
     * Array of attributes.
     *
     * @var array
     */
    private array $attributes = [];

    /**
     * MessageAttribute constructor.
     *
     * @param ?array $attributes Optional initial attributes.
     */
    public function __construct(?array $attributes = [])
    {
        if ($attributes) {
            foreach ($attributes as $key => $value) {
                $this->attributes[$key] = $value;
            }
        }
    }

    /**
     * Get the first attribute value.
     *
     * @return null|string First attribute value or null if empty.
     */
    public function first(): ?string
    {
        return reset($this->attributes) ?: null;
    }

    /**
     * Get the last attribute value.
     *
     * @return null|string Last attribute value or null if empty.
     */
    public function end(): ?string
    {
        return end($this->attributes) ?: null;
    }

    /**
     * Add an attribute.
     *
     * @param MessageAttribute $attribute Attribute key.
     * @param string|null $value
     * @return self
     */
    public function add(MessageAttribute $attribute, ?string $value = null): self
    {
//        $messageAttrExist = (bool)array_filter(MessageAttribute::cases(), fn($attribute) => $attribute->name === $key);
//
//        if (!$messageAttrExist) {
//            throw new InvalidArgumentException("Invalid attribute name.");
//        }

        $this->attributes[$attribute->name] = $value;
        return $this;
    }

    /**
     * Remove an attribute.
     *
     * @param MessageAttribute $attribute Attribute key to remove.
     * @return self
     */
    public function remove(MessageAttribute $attribute): self
    {
        unset($this->attributes[$attribute->name]);
        return $this;
    }

    /**
     * Get all attributes.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->attributes;
    }

    /**
     * Get all attribute keys.
     *
     * @return array
     */
    public function keys(): array
    {
        return array_keys($this->attributes);
    }

    /**
     * Get all attribute values.
     *
     * @return array
     */
    public function values(): array
    {
        return array_values($this->attributes);
    }

    /**
     * Merge attributes into the current attributes.
     *
     * @param array $attributes Attributes to merge.
     * @return void
     */
    public function merge(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }

    /**
     * Get an attribute by key.
     *
     * @param MessageAttribute|string $attribute Attribute.
     * @param mixed $default Default value if attribute is not found.
     * @return string|array|null
     */
    public function get(MessageAttribute|string $attribute, mixed $default = null): null|string|array
    {
        return $this->attributes[is_string($attribute) ? $attribute : $attribute->name] ?? $default;
    }

    /**
     * Apply a callback to all attributes.
     *
     * @param callable $func Callback function.
     * @return void
     */
    public function map(callable $func): void
    {
        $this->attributes = array_map($func, $this->attributes);
    }

    /**
     * if the attribute exists
     *
     * @param MessageAttribute|string $attribute Attribute.
     * @return bool
     */
    public function has(MessageAttribute|string $attribute): bool
    {
        return array_key_exists(is_string($attribute) ? $attribute : $attribute->name, $this->attributes);
    }

    /**
     * Get the count of attributes.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->attributes);
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->attributes);
    }
}