<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Codecs;

use Webrtc\RTPParameter\RTCRtpCodecCapability;
use Webrtc\RTPParameter\RTCRtpCodecParameters;

/**
 * Codec Utility Class
 *
 * Provides helper methods and utilities for codec-related operations.
 * Contains platform-agnostic functionality needed by various codec implementations.
 *
 * @package Webrtc\Codecs
 */
class CodecUtility
{
    /**
     * Gets the number of available CPU cores
     *
     * Cross-platform method to determine available processing cores.
     * Uses different system commands based on the operating system.
     *
     * @return int Number of CPU cores
     */
    public static function getNumberOfCPUCores(): int
    {
        return (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? shell_exec('echo %NUMBER_OF_PROCESSORS%') : shell_exec('nproc'));
    }

    /**
     * Checks if a codec is an RTX (Retransmission) codec
     *
     * @param RTCRtpCodecParameters|RTCRtpCodecCapability $codec Codec to check
     * @return bool True if the codec is RTX, false otherwise
     */
    public static function isRtx(RTCRtpCodecParameters|RTCRtpCodecCapability $codec): bool
    {
        return strtolower(explode("/", $codec->mimeType)[1]) === 'rtx';
    }
}