<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\TURN;

use Psr\Log\LoggerInterface;
use Random\RandomException;
use React\Promise\PromiseInterface;
use Webrtc\STUN\IceConnectionProtocolInterface;
use Webrtc\STUN\ReceiverInterface;

interface TurnConnectionInterface extends IceConnectionProtocolInterface
{
    public function connect(): PromiseInterface;

    public function delete(): void;

    public function getRelayedAddress(): ?array;

    public function sendData(string $data, string $addr): PromiseInterface;

}