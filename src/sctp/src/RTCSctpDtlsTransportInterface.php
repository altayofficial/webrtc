<?php

namespace Webrtc\SCTP;

use Webrtc\DataChannel\RTCSctpTransportInterface;
use Webrtc\ICE\RTCIceTransportInterface;
use Webrtc\Stats\enum\TLSState;

interface RTCSctpDtlsTransportInterface
{
    public function getState(): TLSState;

    public function getIceTransport(): RTCIceTransportInterface;

    public function setSctpReceiver(?RTCSctpTransportInterface $sctpReceiver = null): void;

    public function removeSctpReceiver(RTCSctpTransport $param): void;

    public function sendData(string $data): void;

}