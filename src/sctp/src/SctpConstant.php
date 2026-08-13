<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP;

class SctpConstant {
    // Local constants
    /**
     * @var int
     */
    const COOKIE_LENGTH = 24;
    /**
     * @var int
     */
    const COOKIE_LIFETIME = 60;
    /**
     * @var int
     */
    const MAX_STREAMS = 65535;
    /**
     * @var int
     */
    const USERDATA_MAX_LENGTH = 1200;

    // Protocol constants
    /**
     * @var int
     */
    const SCTP_CAUSE_INVALID_STREAM = 0x0001;
    /**
     * @var int
     */
    const SCTP_CAUSE_STALE_COOKIE = 0x0003;

    /**
     * @var int
     */
    const SCTP_DATA_LAST_FRAG = 0x01;
    /**
     * @var int
     */
    const SCTP_DATA_FIRST_FRAG = 0x02;
    /**
     * @var int
     */
    const SCTP_DATA_UNORDERED = 0x04;

    /**
     * @var int
     */
    const SCTP_MAX_ASSOCIATION_RETRANS = 10;
    /**
     * @var int
     */
    const SCTP_MAX_BURST = 4;
    /**
     * @var int
     */
    const SCTP_MAX_INIT_RETRANS = 8;
    /**
     * @var int|float
     */
    const SCTP_RTO_ALPHA = 1 / 8;
    /**
     * @var int|float
     */
    const SCTP_RTO_BETA = 1 / 4;
    /**
     * @var float
     */
    const SCTP_RTO_INITIAL = 3.0;
    /**
     * @var int
     */
    const SCTP_RTO_MIN = 1;
    /**
     * @var int
     */
    const SCTP_RTO_MAX = 60;
    /**
     * @var int
     */
    const SCTP_TSN_MODULO = 2 ** 32;

    /**
     * @var int
     */
    const RECONFIG_MAX_STREAMS = 135;

    // Parameters
    /**
     * @var int
     */
    const SCTP_STATE_COOKIE = 0x0007;
    /**
     * @var int
     */
    const SCTP_STR_RESET_OUT_REQUEST = 0x000D;
    /**
     * @var int
     */
    const SCTP_STR_RESET_RESPONSE = 0x0010;
    /**
     * @var int
     */
    const SCTP_STR_RESET_ADD_OUT_STREAMS = 0x0011;
    /**
     * @var int
     */
    const SCTP_SUPPORTED_CHUNK_EXT = 0x8008;
    /**
     * @var int
     */
    const SCTP_PRSCTP_SUPPORTED = 0xC000;

    // Data channel constants
    /**
     * @var int
     */
    const DATA_CHANNEL_ACK = 2;
    /**
     * @var int
     */
    const DATA_CHANNEL_OPEN = 3;

    /**
     * @var int
     */
    const DATA_CHANNEL_RELIABLE = 0x00;
    /**
     * @var int
     */
    const DATA_CHANNEL_PARTIAL_RELIABLE_REXMIT = 0x01;
    /**
     * @var int
     */
    const DATA_CHANNEL_PARTIAL_RELIABLE_TIMED = 0x02;
    /**
     * @var int
     */
    const DATA_CHANNEL_RELIABLE_UNORDERED = 0x80;
    /**
     * @var int
     */
    const DATA_CHANNEL_PARTIAL_RELIABLE_REXMIT_UNORDERED = 0x81;
    /**
     * @var int
     */
    const DATA_CHANNEL_PARTIAL_RELIABLE_TIMED_UNORDERED = 0x82;

    /**
     * @var int
     */
    const WEBRTC_DCEP = 50;
    /**
     * @var int
     */
    const WEBRTC_STRING = 51;
    /**
     * @var int
     */
    const WEBRTC_BINARY = 53;
    /**
     * @var int
     */
    const WEBRTC_STRING_EMPTY = 56;
    /**
     * @var int
     */
    const WEBRTC_BINARY_EMPTY = 57;
}