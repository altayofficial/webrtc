<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\ICE;

use Webrtc\Exception\InvalidArgumentException;
use Webrtc\ICE\Enum\CandidateType;
use Webrtc\ICE\Enum\TransportType;

/**
 * RTCIceCandidate - ICE Candidate Representation
 *
 * Represents a candidate for establishing network connections in WebRTC ICE protocol.
 * Handles both local and remote candidates with all necessary attributes for ICE negotiation.
 *
 * Key Features:
 * - Represents ICE candidates with all SDP attributes
 * - Supports both UDP and TCP transport protocols
 * - Handles host, server reflexive, peer reflexive, and relayed candidate types
 * - Provides SDP parsing and generation
 * - Implements candidate pairing logic
 * - Follows RFC 8445 (ICE) specifications
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8445 ICE RFC 8445
 */
class RTCIceCandidate
{
    private string $protocolId;
    private string $host;
    private int $port;
    private CandidateType $type;
    private TransportType $transport = TransportType::udp;
    private ?string $relatedAddress = null;
    private ?int $relatedPort = null;
    private ?string $tcpType = null;
    private ?int $generation = null;
    private ?int $sdpMid = null;
    private ?int $sdpMLineIndex = null;
    private ?int $priority;
    private ?string $foundation;

    /**
     * Constructor - Creates a new ICE candidate
     *
     * @param int $componentId The component ID (1 for RTP, 2 for RTCP)
     */
    public function __construct(private readonly int $componentId)
    {
    }

    /**
     * Get the component ID (1 for RTP, 2 for RTCP)
     *
     * @return int
     */
    public function getComponentId(): int
    {
        return $this->componentId;
    }

    /**
     * Get the candidate's IP address
     *
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Set the candidate's IP address
     *
     * @param string $host
     */
    public function setHost(string $host): void
    {
        $this->host = $host;
    }

    /**
     * Get the candidate type (host, srflx, prflx, relay)
     *
     * @return CandidateType
     */
    public function getType(): CandidateType
    {
        return $this->type;
    }

    /**
     * Set the candidate type
     *
     * @param CandidateType $type
     */
    public function setType(CandidateType $type): void
    {
        $this->type = $type;
    }

    /**
     * Get the candidate's port number
     *
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Set the candidate's port number
     *
     * @param int $port
     */
    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    /**
     * Get the transport protocol (udp/tcp)
     *
     * @return TransportType
     */
    public function getTransport(): TransportType
    {
        return $this->transport;
    }

    /**
     * Set the transport protocol
     *
     * @param TransportType $transport
     */
    public function setTransport(TransportType $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Get the related address (for reflexive candidates)
     *
     * @return string|null
     */
    public function getRelatedAddress(): ?string
    {
        return $this->relatedAddress;
    }

    /**
     * Set the related address
     *
     * @param string|null $relatedAddress
     */
    public function setRelatedAddress(?string $relatedAddress): void
    {
        $this->relatedAddress = $relatedAddress;
    }

    /**
     * Get the related port (for reflexive candidates)
     *
     * @return int|null
     */
    public function getRelatedPort(): ?int
    {
        return $this->relatedPort;
    }

    /**
     * Set the related port
     *
     * @param int|null $relatedPort
     */
    public function setRelatedPort(?int $relatedPort): void
    {
        $this->relatedPort = $relatedPort;
    }

    /**
     * Get the TCP type (active/passive/so) for TCP candidates
     *
     * @return string|null
     */
    public function getTcpType(): ?string
    {
        return $this->tcpType;
    }

    /**
     * Set the TCP type
     *
     * @param string|null $tcpType
     */
    public function setTcpType(?string $tcpType): void
    {
        $this->tcpType = $tcpType;
    }

    /**
     * Get the generation identifier for ICE restarts
     *
     * @return int|null
     */
    public function getGeneration(): ?int
    {
        return $this->generation;
    }

    /**
     * Set the generation identifier
     *
     * @param int|null $generation
     */
    public function setGeneration(?int $generation): void
    {
        $this->generation = $generation;
    }

    /**
     * Set the candidate priority
     *
     * @param int|null $priority
     */
    public function setPriority(?int $priority): void
    {
        $this->priority = $priority;
    }

    /**
     * Set the foundation string
     *
     * @param string|null $foundation
     */
    public function setFoundation(?string $foundation): void
    {
        $this->foundation = $foundation;
    }

    /**
     * Get the foundation string (unique identifier for candidate pairs)
     *
     * If not explicitly set, generates one based on candidate properties
     *
     * @return string
     * @see https://datatracker.ietf.org/doc/html/rfc8445#section-5.1.1.3
     */
    public function getFoundation(): string
    {
        return $this->foundation ?? \md5(implode("|", [$this->type->name, $this->transport->name, $this->host]));
    }

    /**
     * Calculate the candidate priority according to RFC 8445
     *
     * @param int|null $type Optional type preference override
     * @param int $localPreference Local preference value (default: 65535)
     * @return int Calculated priority value
     * @see https://datatracker.ietf.org/doc/html/rfc8445#section-5.1.2.1
     */
    public function getPriority(?int $type = null, int $localPreference = 0xffff): int
    {
        return $this->priority ?? (1 << 24) * ($type ?? $this->type->value) + (1 << 8) * $localPreference + (256 - $this->componentId);
    }

    /**
     * Parse an SDP candidate string into an RTCIceCandidate object
     *
     * Example SDP format:
     * "foundation componentId transport priority host port typ type [extensions]"
     *
     * @param string $sdp The SDP candidate string
     * @return self
     * @throws InvalidArgumentException If SDP format is invalid
     *
     * @example
     * $candidate = RTCIceCandidate::parseSDP(
     *     "ac1986cbcd1877717d1c1b5528d50921 1 udp 2130706431 192.168.1.1 32652 typ host"
     * );
     */
    public static function parseSDP(string $sdp): self
    {
        $sdpParts = explode(" ", $sdp);

        if (count($sdpParts) < 8) {
            throw new InvalidArgumentException("SDP does not have enough properties");
        }

        $candidate = new static((int)$sdpParts[1]);
        $candidate->setFoundation($sdpParts[0] ?? null);
        $candidate->setPriority($sdpParts[3] ?? null);
        $candidate->setTransport(constant(TransportType::class . '::' . strtolower($sdpParts[2])));
        $candidate->setHost($sdpParts[4]);
        $candidate->setPort((int)$sdpParts[5]);
        $candidate->setType(constant(CandidateType::class . '::' . $sdpParts[7]));

        // Parse extensions (raddr, rport, tcptype, generation)
        for ($i = 8; $i < count($sdpParts); $i += 2) {
            switch ($sdpParts[$i]) {
                case "raddr":
                    $candidate->setRelatedAddress($sdpParts[$i + 1]);
                    break;
                case "rport":
                    $candidate->setRelatedPort((int)$sdpParts[$i + 1]);
                    break;
                case "tcptype":
                    $candidate->setTcpType($sdpParts[$i + 1]);
                    break;
                case "generation":
                    $candidate->setGeneration((int)$sdpParts[$i + 1]);
                    break;
            }
        }

        return $candidate;
    }

    /**
     * Convert candidate to SDP string format
     *
     * @return string SDP-formatted candidate string
     */
    public function convert2SDP(): string
    {
        $sdp = sprintf(
            "%s %d %s %d %s %d typ %s",
            $this->getFoundation(),
            $this->componentId,
            $this->transport->name,
            $this->getPriority(),
            $this->host,
            $this->port,
            $this->type->name
        );

        // Add optional parameters if present
        $optionalParams = [
            'raddr' => $this->relatedAddress,
            'rport' => $this->relatedPort,
            'tcptype' => $this->tcpType,
            'generation' => $this->generation
        ];

        foreach ($optionalParams as $key => $value) {
            if ($value !== null) {
                $sdp .= sprintf(" %s %s", $key, $value);
            }
        }

        return $sdp;
    }

    /**
     * Check if this candidate can be paired with another candidate
     *
     * Candidates are pairable if they have:
     * - Same IP version
     * - Same transport protocol
     * - Same component ID
     *
     * @param RTCIceCandidate $candidate The candidate to check against
     * @return bool True if pairable, false otherwise
     */
    public function isPairableWith(RTCIceCandidate $candidate): bool
    {
        return Utils::IPVersion($this->host) === Utils::IPVersion($candidate->getHost())
            && $this->transport === $candidate->getTransport()
            && $this->componentId === $candidate->getComponentId();
    }

    /**
     * Get the protocol ID
     *
     * @return string
     */
    public function getProtocolId(): string
    {
        return $this->protocolId;
    }

    /**
     * Set the protocol ID
     *
     * @param string $protocolId
     */
    public function setProtocolId(string $protocolId): void
    {
        $this->protocolId = $protocolId;
    }

    /**
     * String representation of the candidate (SDP format)
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->convert2SDP();
    }

    /**
     * Create a new candidate with all properties
     *
     * @param string|null $protocolId Protocol identifier
     * @param int $componentId Component ID (1 for RTP, 2 for RTCP)
     * @param string $host IP address
     * @param int $port Port number
     * @param CandidateType $type Candidate type
     * @param TransportType $transportType Transport protocol
     * @param string|null $relatedAddress Related address for reflexive candidates
     * @param int|null $relatedPort Related port for reflexive candidates
     * @param string|null $tcpType TCP type for TCP candidates
     * @param string|null $generation Generation identifier
     * @return self
     */
    public static function create(
        ?string $protocolId,
        int $componentId,
        string $host,
        int $port,
        CandidateType $type,
        TransportType $transportType = TransportType::udp,
        ?string $relatedAddress = null,
        ?int $relatedPort = null,
        ?string $tcpType = null,
        ?string $generation = null
    ): self {
        $candidate = new static($componentId);
        $candidate->setProtocolId($protocolId);
        $candidate->setHost($host);
        $candidate->setPort($port);
        $candidate->setType($type);
        $candidate->setTransport($transportType);
        $candidate->setRelatedAddress($relatedAddress);
        $candidate->setRelatedPort($relatedPort);
        $candidate->setTcpType($tcpType);
        $candidate->setGeneration($generation);

        return $candidate;
    }

    /**
     * Get the SDP media stream identification tag
     *
     * @return int|null
     */
    public function getSdpMid(): ?int
    {
        return $this->sdpMid;
    }

    /**
     * Set the SDP media stream identification tag
     *
     * @param int|null $sdpMid
     */
    public function setSdpMid(?int $sdpMid): void
    {
        $this->sdpMid = $sdpMid;
    }

    /**
     * Get the SDP media description line index
     *
     * @return int|null
     */
    public function getSdpMLineIndex(): ?int
    {
        return $this->sdpMLineIndex;
    }

    /**
     * Set the SDP media description line index
     *
     * @param int|null $sdpMLineIndex
     */
    public function setSdpMLineIndex(?int $sdpMLineIndex): void
    {
        $this->sdpMLineIndex = $sdpMLineIndex;
    }
}