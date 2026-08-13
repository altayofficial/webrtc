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

/**
 * Represents a pattern a bit and provides functionality to match integers against it.
 */
class BitPattern
{
    /** @var int The bitmask used to filter out 'x' bits. */
    private int $mask;

    /** @var int The value to compare against after applying the mask. */
    private int $maskedValue;

    /**
     * Initializes a new BitPattern instance.
     *
     * @param string $pattern A string representing the bit pattern (e.g., "1x0x1x0x").
     *                        '1' and '0' represent fixed bits, and 'x' represents wildcard bits.
     */
    public function __construct(string $pattern)
    {
        $this->mask = ~$this->createByteMask('x', $pattern);
        $this->maskedValue = $this->createByteMask('1', $pattern);
    }

    /**
     * Checks if the given integer matches the bit pattern.
     *
     * @param int $value The integer value to check against the bit pattern.
     * @return bool True if the value matches the pattern, false otherwise.
     */
    public function matches(int $value): bool
    {
        return ($value & $this->mask) === $this->maskedValue;
    }

    /**
     * Creates a bitmask based on a specific character in the pattern string.
     *
     * @param string $character The character to match in the pattern (e.g., '1' or 'x').
     * @param string $pattern The bit pattern string (e.g., "1x0x1x0x").
     * @return int A bitmask representing the positions of the specified character.
     */
    private function createByteMask(string $character, string $pattern): int
    {
        return (
            (($pattern[0] === $character) << 7) |
            (($pattern[1] === $character) << 6) |
            (($pattern[2] === $character) << 5) |
            (($pattern[3] === $character) << 4) |
            (($pattern[4] === $character) << 3) |
            (($pattern[5] === $character) << 2) |
            (($pattern[6] === $character) << 1) |
            (($pattern[7] === $character) << 0)
        );
    }
}
