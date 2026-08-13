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

use Webrtc\Exception\InvalidArgumentException;
use Webrtc\SDP\Enum\H264Level;
use Webrtc\SDP\Enum\H264Profile;

/**
 * H.264 SDP utility class.
 *
 * Provides functionality for parsing H.264 profile-level-id strings from SDP
 * and mapping them to standardized H.264 profile and level enumerations.
 *
 * Implements parsing according to RFC 6184 (RTP Payload Format for H.264 Video)
 * and handles profile/level identification as defined in the ITU-T H.264 standard.
 */
class H264Sdp
{
    /**
     * Gets the predefined H.264 profile matching patterns.
     *
     * Each pattern consists of:
     * - profile_idc value (integer)
     * - BitPattern object for profile_iop matching
     * - Corresponding H264Profile enum value
     *
     * The patterns follow the H.264 standard's profile/level identification scheme.
     *
     * @return array<array{0: int, 1: BitPattern, 2: H264Profile}> Array of pattern tuples
     */
    public static function getPatterns(): array
    {
        return [
            [0x42, new BitPattern("x1xx0000"), H264Profile::PROFILE_CONSTRAINED_BASELINE],
            [0x4D, new BitPattern("1xxx0000"), H264Profile::PROFILE_CONSTRAINED_BASELINE],
            [0x58, new BitPattern("11xx0000"), H264Profile::PROFILE_CONSTRAINED_BASELINE],
            [0x42, new BitPattern("x0xx0000"), H264Profile::PROFILE_BASELINE],
            [0x58, new BitPattern("10xx0000"), H264Profile::PROFILE_BASELINE],
            [0x4D, new BitPattern("0x0x0000"), H264Profile::PROFILE_MAIN],
            [0x64, new BitPattern("00000000"), H264Profile::PROFILE_HIGH],
            [0x64, new BitPattern("00001100"), H264Profile::PROFILE_CONSTRAINED_HIGH],
            [0xF4, new BitPattern("00000000"), H264Profile::PROFILE_PREDICTIVE_HIGH_444],
        ];
    }

    /**
     * Parses an H.264 profile-level-id string into profile and level.
     *
     * The profile-level-id string format is defined in RFC 6184 Section 8.2.2 as:
     * - 6 hexadecimal digits representing:
     *   - Bytes 1-2: profile_idc (1 byte) + reserved zero bits (1 byte)
     *   - Byte 3: profile_iop (constraint_set flags)
     *   - Byte 4: level_idc
     *
     * @param string|null $profileStr The profile-level-id string (e.g. "42e01f")
     * @return array{0: H264Profile, 1: H264Level} Tuple containing profile and level enums
     * @throws InvalidArgumentException If:
     *         - Input is not a 6-character hex string
     *         - Unrecognized profile_idc/profile_iop combination
     *         - Invalid level_idc value
     */
    public static function parseH264ProfileLevelId(?string $profileStr): array
    {
        if (!$profileStr || !preg_match('/^[0-9a-fA-F]{6}$/', $profileStr)) {
            throw new InvalidArgumentException("Expected a 6-character hexadecimal string");
        }

        // Split into three 2-character hex pairs and convert to decimal
        [$profileIdc, $profileIop, $levelIdc] = array_map('hexdec', str_split($profileStr, 2));

        // Special case for level 1.1 vs. 1.b detection
        $level = match ($levelIdc) {
            H264Level::LEVEL1_1->value => ($profileIop & 0x10) ? H264Level::LEVEL1_B : H264Level::LEVEL1_1,
            default => H264Level::tryFrom($levelIdc) ?? throw new InvalidArgumentException("$levelIdc is not a valid H264Level"),
        };

        // Match against known profile patterns
        foreach (self::getPatterns() as [$idc, $pattern, $profile]) {
            if ($idc === $profileIdc && $pattern->matches($profileIop)) {
                return [$profile, $level];
            }
        }

        throw new InvalidArgumentException("Unrecognized profile_iop = $profileIop, profile_idc = $profileIdc");
    }
}