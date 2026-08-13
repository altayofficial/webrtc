<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTCP;

class RtcpConstants
{
    // Header lengths
    /**
     * @var int
     */
    const RTCP_HEADER_LENGTH = 4;

    // RTCP packet types
    /**
     * @var int
     */
    const RTCP_SR = 200;
    /**
     * @var int
     */
    const RTCP_RR = 201;
    /**
     * @var int
     */
    const RTCP_SDES = 202;
    /**
     * @var int
     */
    const RTCP_BYE = 203;
    /**
     * @var int
     */
    const RTCP_RTPFB = 205;
    /**
     * @var int
     */
    const RTCP_PSFB = 206;

    // RTCP Feedback Message Types
    /**
     * @var int
     */
    const RTCP_RTPFB_NACK = 1;

    // RTCP Payload-Specific Feedback Messages
    /**
     * @var int
     */
    const RTCP_PSFB_PLI = 1;
    /**
     * @var int
     */
    const RTCP_PSFB_SLI = 2;
    /**
     * @var int
     */
    const RTCP_PSFB_RPSI = 3;
    /**
     * @var int
     */
    const RTCP_PSFB_APP = 15;
}
