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

use React\Promise\PromiseInterface;
use Webrtc\STUN\Message\Message;
use Webrtc\STUN\Message\MessageInterface;

interface TransactionInterface
{
    public function responseReceived(MessageInterface $message, ?string $address): void;

    public function execute(): PromiseInterface;
}