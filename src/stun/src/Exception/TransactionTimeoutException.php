<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\STUN\Exception;

/**
 * Exception class for STUN transaction timeouts.
 */
class TransactionTimeoutException extends TransactionException
{
    public function __construct()
    {
        parent::__construct("STUN transaction timed out");
    }
}