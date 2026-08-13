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

use Throwable;
use Webrtc\STUN\Message\MessageInterface;

interface ReceiverInterface
{
    public function onDataReceived(string $data, int $componentId): void;
    public function onRequestReceived(MessageInterface $message, string $address, IceConnectionProtocolInterface $protocol, string $data): void;
    public function onClose(): void;
    public function onError(Throwable $e): void;
}