<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Webrtc;

use React\Promise\PromiseInterface;
use Webrtc\DataChannel\RTCDataChannel;
use Webrtc\DataChannel\RTCDataChannelParameters;
use Webrtc\ICE\Enum\IceGatheringState;
use Webrtc\ICE\RTCIceCandidate;
use Webrtc\SDP\RTCSessionDescription;
use Webrtc\Stats\RTCStatsReport;
use Webrtc\Webrtc\Enum\ConnectionState;
use Webrtc\Webrtc\Enum\IceConnectionState;
use Webrtc\Webrtc\Enum\SignalingState;

interface RTCPeerConnectionInterface
{
    public function getConnectionState(): ConnectionState;
    public function getIceConnectionState(): IceConnectionState;
    public function getIceGatheringState(): IceGatheringState;
    public function getSignalingState(): SignalingState;
    public function getLocalDescription(): ?RtcSessionDescription;
    public function getRemoteDescription(): ?RtcSessionDescription;
    public function addIceCandidate(RTCIceCandidate $candidate): void;
    public function close(): void;
    public function createAnswer(): PromiseInterface;
    public function createOffer(): PromiseInterface;
    public function createDataChannel(RTCDataChannelParameters $parameters): RTCDataChannel;
    public function getStats(): RTCStatsReport;
    public function setLocalDescription(RTCSessionDescription $rtcSessionDescription): PromiseInterface;
    public function setRemoteDescription(RTCSessionDescription $sessionDescription): PromiseInterface;
}
