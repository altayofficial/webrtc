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

use Webrtc\Codecs\Codec;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\Receiver\RTCRtpReceiver;
use Webrtc\RTP\Sender\RTCRtpSender;
use Webrtc\RTPParameter\RTCRtpCodecParameters;
use Webrtc\SDP\Enum\SDPDirections;
use Webrtc\Srtp\Exception\SrtpException;

/**
 * Represents a bidirectional RTP stream with a sender and receiver for WebRTC communications.
 *
 * The RTCRtpTransceiver interface describes a permanent pairing of an RTCRtpSender
 * and an RTCRtpReceiver, along with some shared state. Each RTCRtpTransceiver
 * represents one bidirectional stream, with an RTP sender and receiver that share
 * the same media identification (mid) value.
 */
class RTCRtpTransceiver
{
    /** @var SDPDirections|null The currently negotiated direction of the transceiver. */
    private ?SDPDirections $currentDirection = null;

    /** @var ?SDPDirections The preferred direction of the transceiver. */
    private ?SDPDirections $direction = SDPDirections::sendrecv;
    private ?SDPDirections $offerDirection = SDPDirections::sendrecv;

    /** @var MediaKind The kind of media (e.g., "audio" or "video"). */
    private MediaKind $kind;

    /** @var string|null The media ID (mid) for this transceiver. */
    private ?string $mid = null;

    /** @var int|null The media line index for this transceiver. */
    private ?int $mlineIndex = null;

    /** @var RTCRtpReceiver The receiver for this transceiver. */
    private RTCRtpReceiver $receiver;

    /** @var RTCRtpSender The sender for this transceiver. */
    private RTCRtpSender $sender;

    /** @var bool Whether the transceiver is stopped. */
    private bool $stopped = false;

    /** @var array List of preferred codecs. */
    private array $preferredCodecs = [];

    /** @var RTCRTPDtlsTransportInterface|null The transport for this transceiver. */
    private ?RTCRTPDtlsTransportInterface $dtlsTransport = null;

    /** @var bool Whether this transceiver is bundled. */
    private bool $bundled = false;

    /** @var array List of codecs. */
    private array $codecs = [];

    /** @var array List of header extensions. */
    private array $headerExtensions = [];

    /**
     * Constructs a new RTCRtpTransceiver instance.
     *
     * @param MediaKind $kind The kind of media (e.g., "audio" or "video").
     * @param RTCRtpReceiver $receiver The receiver for this transceiver.
     * @param RTCRtpSender $sender The sender for this transceiver.
     */
    public function __construct(
        MediaKind         $kind,
        RTCRtpReceiver $receiver,
        RTCRtpSender   $sender,
    )
    {
        $this->kind = $kind;
        $this->receiver = $receiver;
        $this->sender = $sender;
    }

    /**
     * Gets the currently negotiated direction of the transceiver.
     *
     * The current direction represents the direction last negotiated in an offer/answer
     * exchange. This may differ from the preferred direction set by the application.
     *
     * @return SDPDirections|null One of 'sendrecv', 'sendonly', 'recvonly', 'inactive', or null if not negotiated yet.
     */
    public function getCurrentDirection(): ?SDPDirections
    {
        return $this->currentDirection;
    }

    /**
     * Gets the preferred direction of the transceiver.
     *
     * The preferred direction indicates the desired direction set by the application,
     * which will be used in the next offer/answer exchange.
     *
     * @return SDPDirections One of 'sendrecv', 'sendonly', 'recvonly', or 'inactive'.
     */
    public function getDirection(): SDPDirections
    {
        return $this->direction;
    }

    /**
     * Sets the preferred direction of the transceiver.
     *
     * This direction will be used in the next offer/answer exchange. The actual
     * negotiated direction may differ based on the remote peer's answer.
     *
     * @param SDPDirections $direction One of 'sendrecv', 'sendonly', 'recvonly', or 'inactive'.
     * @throws InvalidArgumentException If an invalid direction is provided.
     */
    public function setDirection(SDPDirections $direction): void
    {
        $this->direction = $direction;
    }

    /**
     * Gets the kind of media for this transceiver.
     *
     * @return MediaKind The kind of media (e.g., "audio" or "video").
     */
    public function getKind(): MediaKind
    {
        return $this->kind;
    }

    /**
     * Gets the media ID (mid) for this transceiver.
     *
     * The mid is a unique identifier for this media stream in the SDP.
     *
     * @return string|null The media ID or null if not set.
     */
    public function getMid(): ?string
    {
        return $this->mid;
    }

    /**
     * Gets the receiver for this transceiver.
     *
     * @return RTCRtpReceiver The receiver instance.
     */
    public function getReceiver(): RTCRtpReceiver
    {
        return $this->receiver;
    }

    /**
     * Gets the sender for this transceiver.
     *
     * @return RTCRtpSender The sender instance.
     */
    public function getSender(): RTCRtpSender
    {
        return $this->sender;
    }

    /**
     * Checks if the transceiver is stopped.
     *
     * A stopped transceiver can no longer send or receive media.
     *
     * @return bool True if stopped, false otherwise.
     */
    public function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * Overrides the default codec preferences for this transceiver.
     *
     * This allows applications to specify which codecs should be preferred
     * in offer/answer negotiations, and in what order of preference.
     *
     * @param array $codecs A list of RTCRtpCodecCapability objects in decreasing order of preference.
     * @throws InvalidArgumentException If a codec is not in the capabilities.
     */
    public function setCodecPreferences(array $codecs): void
    {
        if (empty($codecs)) {
            $this->preferredCodecs = [];
            return;
        }

        $capabilities = (new Codec())->getCapabilities($this->kind->value)->codecs;
        $unique = [];
        foreach (array_reverse($codecs) as $codec) {
            if (!in_array($codec, $capabilities)) {
                throw new InvalidArgumentException("Codec is not in capabilities");
            }
            if (!in_array($codec, $unique)) {
                array_unshift($unique, $codec);
            }
        }
        $this->preferredCodecs = $unique;
    }

    /**
     * Permanently stops the transceiver.
     *
     * This stops both the sender and receiver components and marks the transceiver
     * as stopped. A stopped transceiver cannot be restarted.
     *
     * @throws SrtpException
     */
    public function stop(): void
    {
        $this->receiver->stop();
        $this->sender->stop();
        $this->stopped = true;
    }

    /**
     * Gets the list of preferred codecs for this transceiver.
     *
     * @return array Array of preferred codecs in order of preference.
     */
    public function getPreferredCodecs(): array
    {
        return $this->preferredCodecs;
    }

    /**
     * Gets the DTLS transport associated with this transceiver.
     *
     * @return RTCRTPDtlsTransportInterface|null The DTLS transport or null if not set.
     */
    public function getDtlsTransport(): ?RTCRTPDtlsTransportInterface
    {
        return $this->dtlsTransport;
    }

    /**
     * Checks if this transceiver is bundled.
     *
     * Bundled transceivers share the same transport channel.
     *
     * @return bool True if bundled, false otherwise.
     */
    public function isBundled(): bool
    {
        return $this->bundled;
    }

    /**
     * Gets the list of negotiated codecs for this transceiver.
     *
     * @return array<RTCRtpCodecParameters> List of negotiated codec parameters.
     */
    public function getCodecs(): array
    {
        return $this->codecs;
    }

    /**
     * Gets the list of negotiated header extensions for this transceiver.
     *
     * @return array List of header extension parameters.
     */
    public function getHeaderExtensions(): array
    {
        return $this->headerExtensions;
    }

    /**
     * Sets the DTLS transport for this transceiver.
     *
     * @param RTCRTPDtlsTransportInterface|null $dtlsTransport The DTLS transport to associate.
     */
    public function setDtlsTransport(?RTCRTPDtlsTransportInterface $dtlsTransport): void
    {
        $this->dtlsTransport = $dtlsTransport;
    }

    /**
     * Sets the current direction of the transceiver.
     *
     * This is typically called during offer/answer negotiation to reflect the
     * agreed-upon direction with the remote peer.
     *
     * @param SDPDirections $direction One of 'sendrecv', 'sendonly', 'recvonly', or 'inactive'.
     */
    public function setCurrentDirection(SDPDirections $direction): void
    {
        $this->currentDirection = $direction;

        switch ($direction) {
            case SDPDirections::sendrecv:
                $this->sender->setEnabled(true);
                $this->receiver->setEnabled(true);
                break;
            case SDPDirections::sendonly:
                $this->sender->setEnabled(true);
                $this->receiver->setEnabled(false);
                break;
            case SDPDirections::recvonly:
                $this->sender->setEnabled(false);
                $this->receiver->setEnabled(true);
                break;
            case SDPDirections::inactive:
                $this->sender->setEnabled(false);
                $this->receiver->setEnabled(false);
                break;
            case SDPDirections::unknown:
                throw new InvalidArgumentException('Invalid direction');
        }
    }

    /**
     * Sets the media ID (mid) for this transceiver.
     *
     * @param string $mid The media ID to set.
     */
    public function setMid(string $mid): void
    {
        $this->mid = $mid;
    }

    /**
     * Gets the offer direction for this transceiver.
     *
     * The offer direction is the direction that will be used when generating an offer.
     *
     * @return SDPDirections|null The offer direction or null if not set.
     */
    public function getOfferDirection(): ?SDPDirections
    {
        return $this->offerDirection;
    }

    /**
     * Sets the offer direction for this transceiver.
     *
     * @param SDPDirections|null $offerDirection The direction to use in offers.
     */
    public function setOfferDirection(?SDPDirections $offerDirection): void
    {
        $this->offerDirection = $offerDirection;
    }

    /**
     * Sets the negotiated codecs for this transceiver.
     *
     * @param array $codecs List of negotiated codec parameters.
     */
    public function setCodecs(array $codecs): void
    {
        $this->codecs = $codecs;
    }

    /**
     * Sets the negotiated header extensions for this transceiver.
     *
     * @param array $headerExtensions List of header extension parameters.
     */
    public function setHeaderExtensions(array $headerExtensions): void
    {
        $this->headerExtensions = $headerExtensions;
    }

    /**
     * Sets whether this transceiver is bundled.
     *
     * @param bool $bundled True if bundled, false otherwise.
     */
    public function setBundled(bool $bundled): void
    {
        $this->bundled = $bundled;
    }

    /**
     * Gets the media line index for this transceiver.
     *
     * @return int|null The media line index or null if not set.
     */
    public function getMlineIndex(): ?int
    {
        return $this->mlineIndex;
    }

    /**
     * Sets the media line index for this transceiver.
     *
     * @param int $idx The media line index to set.
     */
    public function setMlineIndex(int $idx): void
    {
        $this->mlineIndex = $idx;
    }
}