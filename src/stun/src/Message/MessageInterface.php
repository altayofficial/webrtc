<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\STUN\Message;

use Webrtc\Exception\InvalidArgumentException;
use Webrtc\STUN\Enum\MessageClass;
use Webrtc\STUN\Enum\MessageMethod;

interface MessageInterface
{
    public function attributes(): MessageAttributeCollection;

    public function getTransactionId(): string;

    public function setTransactionId(string $transactionId): void;

    public function getMessageClass(): MessageClass;

    public function getMessageMethod(): MessageMethod;

    public function addMessageIntegrity(string $key): void;

    public function encode(): string;

    public static function decode(string $data, ?string $integrityKey): static;

    public function humanReadable(): string;

    public static function new(MessageClass $messageClass, MessageMethod $messageMethod, array $attributes, ?string $integrityKey): MessageInterface;
}