<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP;

use Webrtc\Exception\InvalidArgumentException;
use Webrtc\RTCP\RtcpByePacket;
use Webrtc\RTCP\RtcpConstants;
use Webrtc\RTCP\RtcpPacketInterface;
use Webrtc\RTCP\RtcpPsfbPacket;
use Webrtc\RTCP\RtcpRrPacket;
use Webrtc\RTCP\RtcpRtpfbPacket;
use Webrtc\RTCP\RtcpSrPacket;
use Webrtc\RTP\Exception\RtpExceptionInterface;
use Webrtc\RTP\Receiver\RtpReceiverInterface;
use Webrtc\RTP\Sender\RtpSenderInterface;

/**
 * Routes RTP and RTCP packets to the appropriate senders and receivers.
 *
 * The RtpRouter maintains mappings between SSRCs, payload types, and media streams
 * to correctly route incoming packets to their associated RtpSender or RtpReceiver
 * instances. It supports both RTP and RTCP packet routing.
 */
class RtpRouter
{
    /** @var array List of registered receivers. */
    private array $receivers = [];

    /** @var array Map of SSRC to sender instances. */
    private array $senders = [];

    /** @var array Map of MID values to receiver instances. */
    private array $midTable = [];

    /** @var array Map of SSRC to receiver instances. */
    private array $ssrcTable = [];

    /** @var array Map of payload types to receiver instances. */
    private array $payloadTypeTable = [];

    /**
     * Registers a receiver with associated SSRCs, payload types, and optional MID.
     *
     * @param RtpReceiverInterface $receiver The receiver instance to register.
     * @param array $ssrcs List of SSRCs associated with this receiver.
     * @param array $payloadTypes List of payload types associated with this receiver.
     * @param string|null $mid Optional media identification (MID) value.
     */
    public function setReceiver(RtpReceiverInterface $receiver, array $ssrcs, array $payloadTypes, ?string $mid = null): void
    {
        $this->receivers[] = $receiver;
        if ($mid !== null) {
            $this->midTable[$mid] = $receiver;
        }
        foreach ($ssrcs as $ssrc) {
            $this->ssrcTable[$ssrc] = $receiver;
        }
        foreach ($payloadTypes as $payloadType) {
            $this->payloadTypeTable[$payloadType][] = $receiver;
        }
    }

    /**
     * Registers a sender with a specific SSRC.
     *
     * @param RtpSenderInterface $sender The sender instance to register.
     * @param int $ssrc The SSRC associated with this sender.
     */
    public function setSender(RtpSenderInterface $sender, int $ssrc): void
    {
        $this->senders[$ssrc] = $sender;
    }

    /**
     * Routes an RTCP packet to the appropriate recipients.
     *
     * @param RtcpPacketInterface $packet The RTCP packet to route.
     * @return array Array of RTCRtpSender and/or RTCRtpReceiver instances that should receive this packet.
     */
    public function routeRtcp(RtcpPacketInterface $packet): array
    {
        $recipients = [];

        $addRecipient = function ($recipient) use (&$recipients) {
            if ($recipient !== null) {
                $recipients[] = $recipient;
            }
        };

        if ($packet instanceof RtcpSrPacket) {
            $addRecipient($this->ssrcTable[$packet->getSsrc()] ?? null);
        } elseif ($packet instanceof RtcpByePacket) {
            foreach ($packet->getSources() as $source) {
                $addRecipient($this->ssrcTable[$source] ?? null);
            }
        }

        if ($packet instanceof RtcpRrPacket || $packet instanceof RtcpSrPacket) {
            foreach ($packet->getReports() as $report) {
                $addRecipient($this->senders[$report->getSsrc()] ?? null);
            }
        } elseif ($packet instanceof RtcpPsfbPacket || $packet instanceof RtcpRtpfbPacket) {
            $addRecipient($this->senders[$packet->getMediaSsrc()] ?? null);

            if ($packet instanceof RtcpPsfbPacket && $packet->getFmt() === RtcpConstants::RTCP_PSFB_APP) {
                try {
                    [, $ssrcs] = RtpUtility::unpackRembFci($packet->getFci());
                    foreach ($ssrcs as $ssrc) {
                        $addRecipient($this->senders[$ssrc] ?? null);
                    }
                } catch (RtpExceptionInterface) {
                }
            }
        }
        return $recipients;
    }

    /**
     * Routes an RTP packet to the appropriate receiver.
     *
     * @param RtpPacket $packet The RTP packet to route.
     * @return RtpReceiverInterface|null The receiver for this packet, or null if no match found.
     */
    public function routeRtp(RtpPacket $packet): ?RtpReceiverInterface
    {
        $ssrcReceiver = $this->ssrcTable[$packet->getSsrc()] ?? null;
        $ptReceivers = $this->payloadTypeTable[$packet->getPayloadType()] ?? [];

        if ($ssrcReceiver !== null && in_array($ssrcReceiver, $ptReceivers, true)) {
            return $ssrcReceiver;
        }

        if ($ssrcReceiver === null && count($ptReceivers) === 1) {
            $ptReceiver = reset($ptReceivers);
            $this->ssrcTable[$packet->getSsrc()] = $ptReceiver;
            return $ptReceiver;
        }

        return null;
    }

    /**
     * Unregisters a receiver, removing all its associations.
     *
     * @param RtpReceiverInterface $receiver The receiver to unregister.
     */
    public function removeReceiver(RtpReceiverInterface $receiver): void
    {
        $this->receivers = array_filter($this->receivers, fn($r) => $r !== $receiver);
        $this->discard($this->midTable, $receiver);
        $this->discard($this->ssrcTable, $receiver);
        foreach ($this->payloadTypeTable as &$receivers) {
            $receivers = array_filter($receivers, fn($r) => $r !== $receiver);
        }
    }

    /**
     * Unregisters a sender, removing all its associations.
     *
     * @param RtpSenderInterface $sender The sender to unregister.
     */
    public function removeSender(RtpSenderInterface $sender): void
    {
        $this->discard($this->senders, $sender);
    }

    /**
     * Removes all occurrences of a value from an associative array.
     *
     * @param array $array The array to modify.
     * @param mixed $value The value to remove.
     */
    private function discard(array &$array, mixed $value): void
    {
        foreach ($array as $key => $val) {
            if ($val === $value) {
                unset($array[$key]);
            }
        }
    }

    /**
     * Gets the current SSRC to receiver mapping.
     *
     * @return array The SSRC table.
     */
    public function getSsrcTable(): array
    {
        return $this->ssrcTable;
    }
}
