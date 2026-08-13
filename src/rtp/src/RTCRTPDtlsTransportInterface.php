<?php

namespace Webrtc\RTP;

use Webrtc\RTP\Receiver\RtpReceiverInterface;
use Webrtc\RTP\Sender\RtpSenderInterface;
use Webrtc\RTPParameter\RTCRtpReceiveParameters;
use Webrtc\RTPParameter\RTCRtpSendParameters;
use Webrtc\Stats\enum\TLSState;
use Webrtc\Stats\RTCTransportStats;

interface RTCRTPDtlsTransportInterface
{
    public function getState(): TLSState;
    public function getReportTransport(): RTCTransportStats;
    public function setRtpReceiver(RtpReceiverInterface $receiver, RTCRtpReceiveParameters $parameters): void;
    public function removeRtpReceiver(RtpReceiverInterface $receiver): void;
    public function sendRtcp(string $data): void;
    public function setRtpSender(RtpSenderInterface $sender, RTCRtpSendParameters $parameters): void;
    public function removeRtpSender(RtpSenderInterface $sender): void;
    public function sendRtp(string $data): void;
}