<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\HeaderExtension;

use Webrtc\RTP\RtpUtility;
use Webrtc\RTPParameter\RTCRtpParameters;

/**
 * Maps RTP header extension URIs to their corresponding IDs and values.
 *
 * This class helps configure and extract RTP header extensions based on SDP
 * negotiation or other signaling information, and then allows parsing and packing
 * of the actual header extension values during RTP processing.
 */
class HeaderExtensionsMap
{
    private HeaderExtensions $ids;

    /**
     * Constructs a new HeaderExtensionsMap with an empty extension ID registry.
     */
    public function __construct()
    {
        $this->ids = new HeaderExtensions();
    }

    /**
     * Configures the extension ID mappings from RTCRtpParameters.
     *
     * @param RTCRtpParameters $parameters The RTP parameters with negotiated extensions.
     */
    public function configure(RTCRtpParameters $parameters): void
    {
        foreach ($parameters->headerExtensions as $ext) {
            switch ($ext->uri) {
                case "urn:ietf:params:rtp-hdrext:sdes:mid":
                    $this->ids->setMid($ext->id);
                    break;
                case "urn:ietf:params:rtp-hdrext:sdes:repaired-rtp-stream-id":
                    $this->ids->setRepairedRtpStreamId($ext->id);
                    break;
                case "urn:ietf:params:rtp-hdrext:sdes:rtp-stream-id":
                    $this->ids->setRtpStreamId($ext->id);
                    break;
                case "http://www.webrtc.org/experiments/rtp-hdrext/abs-send-time":
                    $this->ids->setAbsSendTime($ext->id);
                    break;
                case "urn:ietf:params:rtp-hdrext:toffset":
                    $this->ids->setTransmissionOffset($ext->id);
                    break;
                case "urn:ietf:params:rtp-hdrext:ssrc-audio-level":
                    $this->ids->setAudioLevel($ext->id);
                    break;
                case "http://www.ietf.org/id/draft-holmer-rmcat-transport-wide-cc-extensions-01":
                    $this->ids->setTransportSequenceNumber($ext->id);
                    break;
            }
        }
    }

    /**
     * Parses incoming RTP header extensions from binary data.
     *
     * @param int $extensionProfile The RTP extension profile ID.
     * @param string $extensionValue The raw RTP header extension bytes.
     * @return HeaderExtensions        The parsed extensions with mapped values.
     */
    public function get(int $extensionProfile, string $extensionValue): HeaderExtensions
    {
        $headerExtensions = new HeaderExtensions();
        $extensions = RtpUtility::unpackHeaderExtensions($extensionProfile, $extensionValue);

        foreach ($extensions as [$xId, $xValue]) {
            switch ($xId) {
                case $this->ids->getMid():
                    $headerExtensions->setMid($xValue);
                    break;
                case $this->ids->getRepairedRtpStreamId():
                    $headerExtensions->setRepairedRtpStreamId($xValue);
                    break;
                case $this->ids->getRtpStreamId():
                    $headerExtensions->setRtpStreamId($xValue);
                    break;
                case $this->ids->getAbsSendTime():
                    $headerExtensions->setAbsSendTime(unpack("N", "\x00" . $xValue)[1]);
                    break;
                case $this->ids->getTransmissionOffset():
                    $headerExtensions->setTransmissionOffset((unpack("N", $xValue . "\x00")[1]) >> 8);
                    break;
                case $this->ids->getAudioLevel():
                    $vadLevel = unpack("C", $xValue)[1];
                    $headerExtensions->setAudioLevel([(bool)($vadLevel & 0x80), $vadLevel & 0x7F]);
                    break;
                case $this->ids->getTransportSequenceNumber():
                    $headerExtensions->setTransportSequenceNumber(unpack("n", $xValue)[1]);
                    break;
            }
        }

        return $headerExtensions;
    }

    /**
     * Packs RTP header extension values into binary format for outgoing packets.
     *
     * @param HeaderExtensions $values The extension values to encode.
     * @return array                   Encoded RTP header extensions as (id, value) pairs.
     */
    public function set(HeaderExtensions $values): array
    {
        $extensions = [];

        if (!is_null($values->getMid()) && $this->ids->getMid()) {
            $extensions[] = [$this->ids->getMid(), $values->getMid()];
        }
        if (!is_null($values->getRepairedRtpStreamId()) && $this->ids->getRepairedRtpStreamId()) {
            $extensions[] = [$this->ids->getRepairedRtpStreamId(), $values->getRepairedRtpStreamId()];
        }
        if (!is_null($values->getRtpStreamId()) && $this->ids->getRtpStreamId()) {
            $extensions[] = [$this->ids->getRtpStreamId(), $values->getRtpStreamId()];
        }
        if (!is_null($values->getAbsSendTime()) && $this->ids->getAbsSendTime()) {
            $extensions[] = [$this->ids->getAbsSendTime(), substr(pack("N", $values->getAbsSendTime()), 1)];
        }
        if (!is_null($values->getTransmissionOffset()) && $this->ids->getTransmissionOffset()) {
            $extensions[] = [$this->ids->getTransmissionOffset(), substr(pack("l", $values->getTransmissionOffset() << 8), 0, 2)];
        }
        if (!is_null($values->getAudioLevel()) && $this->ids->getAudioLevel()) {
            $extensions[] = [$this->ids->getAudioLevel(), pack("C", ($values->getAudioLevel()[0] ? 0x80 : 0) | ($values->getAudioLevel()[1] & 0x7F))];
        }
        if (!is_null($values->getTransportSequenceNumber()) && $this->ids->getTransportSequenceNumber()) {
            $extensions[] = [$this->ids->getTransportSequenceNumber(), pack("n", $values->getTransportSequenceNumber())];
        }

        return RtpUtility::packHeaderExtensions($extensions);
    }
}
