<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SDP;

use Webrtc\Mixin\DataClass;

/**
 * Represents a group description with a semantic label and a list of items.
 */
#[DataClass]
class GroupDescription
{
    /**
     * Initializes a new GroupDescription instance.
     *
     * @param string $semantic The semantic label for the group.
     * @param array<int|string> $items The list of items in the group.
     */
    public function __construct(
        public string $semantic,
        public array  $items
    )
    {
    }

    /**
     * Returns the string representation of the group description.
     *
     * @return string The formatted string containing the semantic label and items.
     */
    public function __toString(): string
    {
        $itemsString = implode(' ', array_map('strval', $this->items));
        return "$this->semantic $itemsString";
    }
}

