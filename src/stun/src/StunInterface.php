<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\STUN;

use Psr\Log\LoggerInterface;

interface StunInterface extends IceConnectionProtocolInterface
{
    public static function create(ReceiverInterface $receiver, string $host, LoggerInterface $logger, ?array $portRange): StunInterface;
}