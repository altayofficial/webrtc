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

use Webrtc\Mixin\DataClass;

#[DataClass]
class HeaderExtensions
{
    private mixed $mid = null;
    private mixed $repairedRtpStreamId = null;
    private mixed $rtpStreamId = null;
    private ?int $absSendTime = null;
    private ?int $transmissionOffset = null;
    private mixed $audioLevel = null;
    private ?int $transportSequenceNumber = null;

    public function getMid(): mixed
    {
        return $this->mid;
    }

    public function setMid(mixed $mid): void
    {
        $this->mid = $mid;
    }

    public function getRepairedRtpStreamId(): mixed
    {
        return $this->repairedRtpStreamId;
    }

    public function setRepairedRtpStreamId(mixed $repairedRtpStreamId): void
    {
        $this->repairedRtpStreamId = $repairedRtpStreamId;
    }

    public function getRtpStreamId(): mixed
    {
        return $this->rtpStreamId;
    }

    public function setRtpStreamId(mixed $rtpStreamId): void
    {
        $this->rtpStreamId = $rtpStreamId;
    }

    public function getAbsSendTime(): ?int
    {
        return $this->absSendTime;
    }

    public function setAbsSendTime(?int $absSendTime): void
    {
        $this->absSendTime = $absSendTime;
    }

    public function getTransmissionOffset(): ?int
    {
        return $this->transmissionOffset;
    }

    public function setTransmissionOffset(?int $transmissionOffset): void
    {
        $this->transmissionOffset = $transmissionOffset;
    }

    public function getAudioLevel(): mixed
    {
        return $this->audioLevel;
    }

    public function setAudioLevel(mixed $audioLevel): void
    {
        $this->audioLevel = $audioLevel;
    }

    public function getTransportSequenceNumber(): ?int
    {
        return $this->transportSequenceNumber;
    }

    public function setTransportSequenceNumber(?int $transportSequenceNumber): void
    {
        $this->transportSequenceNumber = $transportSequenceNumber;
    }

}

