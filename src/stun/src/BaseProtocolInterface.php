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

use Webrtc\ICE\RTCIceCandidate;

interface BaseProtocolInterface
{
    public function send(string $data, ?string $remoteAddress = null): void;

    public function close(): void;

    public function end(): void;

    public function resume(): void;

    public function pause(): void;

    public function getLocalAddress(): string;

    public function getLocalHost(): string;

    public function getLocalPort(): int;

    public function getRemoteAddress(): ?string;

    public function getCandidate(): ?RTCIceCandidate;

    public function setCandidate(RTCIceCandidate $candidate): void;
}
