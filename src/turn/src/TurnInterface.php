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
use React\Promise\PromiseInterface;
use Webrtc\STUN\IceConnectionProtocolInterface;
use Webrtc\STUN\ReceiverInterface;

interface TurnInterface extends IceConnectionProtocolInterface
{
    public function connect(): PromiseInterface;

    public function delete(): void;

    function getRelayedAddress(): ?array;

    function getRelayedHost(): string;

    function getRelayedPort(): string;

    public static function create(TurnConfigurationInterface $configuration, ReceiverInterface $receiver, LoggerInterface $logger): Turn;
}