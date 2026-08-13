<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Srtp\Exception;

class SrtpValidateException extends SrtpException
{
    /**
     * @var mixed[]
     */
    private const ERRORS = [
        "nothing to report",
        "unspecified failure",
        "unsupported parameter",
        "couldn't allocate memory",
        "couldn't deallocate properly",
        "couldn't initialize",
        "can't process as much data as requested",
        "authentication failure",
        "cipher failure",
        "replay check failed (bad index)",
        "replay check failed (index too old)",
        "algorithm failed test routine",
        "unsupported operation",
        "no appropriate context found",
        "unable to perform desired validation",
        "can't use key any more",
        "error in use of socket",
        "error in use POSIX signals",
        "nonce check failed",
        "couldn't read data",
        "couldn't write data",
        "error parsing data",
        "error encoding data",
        "error while using semaphores",
        "error while using pfkey",
        "error MKI present in packet is invalid",
        "packet index is too old to consider",
        "packet index advanced, reset needed",
    ];

    public function __construct(int $code)
    {
        parent::__construct(self::ERRORS[$code] ?? "Unknown Error!");
    }
}