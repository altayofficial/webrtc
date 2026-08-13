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

use Webrtc\STUN\Message\MessageInterface;
use Webrtc\Exception\Exception;

/**
 * Base class for STUN transaction errors.
 */
class TransactionException extends Exception implements TransactionExceptionInterface
{
    protected ?MessageInterface $stunMessage = null;

    public function getStunMessage(): ?MessageInterface
    {
        return $this->stunMessage;
    }
}