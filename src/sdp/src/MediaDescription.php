<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SDP;

use Webrtc\ICE\RTCIceCandidate;
use Webrtc\ICE\RTCIceParameters;
use Webrtc\RTPParameter\RTCRtpParameters;
use Webrtc\SDP\DtlsParameter\RTCDtlsParameters;
use Webrtc\SDP\Enum\SDPDirections;
use Webrtc\SDP\SctpParameter\RTCSctpCapabilities;

/**
 * Represents a media description for SDP (Session Description Protocol).
 */
class MediaDescription
{
    // RTP properties
    private string $kind;
    private int $port;
    private ?string $host = null;
    private string $profile;
    private ?SDPDirections $direction = null;
    private ?string $msid = null;

    // RTCP properties
    private ?int $rtcpPort = null;
    private ?string $rtcpHost = null;
    private bool $rtcpMux = false;

    // SSRC properties
    /** @var SsrcDescription[] */
    private array $ssrc = [];

    /** @var GroupDescription[] */
    private array $ssrcGroup = [];

    // Formats
    /** @var array */
    private array $fmt;

    // RTP parameters
    private RTCRtpParameters $rtp;

    // SCTP properties
    private ?RTCSctpCapabilities $sctpCapabilities = null;
    /** @var array<int, string> */
    private array $sctpmap = [];
    private ?int $sctpPort = null;

    // DTLS properties
    private ?RTCDtlsParameters $dtls;

    // ICE properties
    private ?RTCIceParameters $ice;
    /** @var RTCIceCandidate[] */
    private array $iceCandidates = [];
    private bool $iceCandidatesComplete = false;

    private ?string $iceOptions = null;

    // DTLS role to SDP setup mapping
    /**
     * @var mixed[]
     */
    private const DTLS_ROLE_SETUP = [
        'auto' => 'actpass',
        'client' => 'active',
        'server' => 'passive',
    ];

    /**
     * Initializes a new MediaDescription instance.
     *
     * @param string $kind The media kind (e.g., "audio", "video").
     * @param int $port The media port.
     * @param string $profile The media profile (e.g., "RTP/AVP").
     * @param array $fmt The media formats.
     */
    public function __construct(
        string $kind, int $port, string $profile, array $fmt)
    {
        $this->kind = $kind;
        $this->port = $port;
        $this->profile = $profile;
        $this->fmt = $fmt;
        $this->rtp = new RTCRtpParameters();
        $this->ice = new RTCIceParameters();
        $this->dtls = new RTCDtlsParameters();
    }

    /**
     * Returns the string representation of the media description in SDP format.
     *
     * @return string The SDP-formatted media description.
     */
    public function __toString(): string
    {
        $lines = [];
        $lines[] = $this->generateMediaLine();
        $lines[] = $this->generateConnectionLine();
        $lines[] = $this->generateDirectionLine();
        $lines = array_merge($lines, $this->generateHeaderExtensions());
        $lines[] = $this->generateMidLine();
        $lines[] = $this->generateMsidLine();
        $lines = array_merge($lines, $this->generateRtcpLines());
        $lines = array_merge($lines, $this->generateSsrcGroupLines());
        $lines = array_merge($lines, $this->generateSsrcLines());
        $lines = array_merge($lines, $this->generateCodecLines());
        $lines = array_merge($lines, $this->generateSctpLines());
        $lines = array_merge($lines, $this->generateIceLines());
        $lines = array_merge($lines, $this->generateDtlsLines());

        return implode("\r\n", array_filter($lines)) . "\r\n";
    }

    /**
     * Generates the "m=" media line.
     *
     * @return string The media line.
     */
    private function generateMediaLine(): string
    {
        return sprintf(
            "m=%s %d %s %s",
            $this->kind,
            $this->port,
            $this->profile,
            implode(' ', array_map('strval', $this->fmt))
        );
    }

    /**
     * Generates the "c=" connection line.
     *
     * @return string|null The connection line, or null if no host is set.
     */
    private function generateConnectionLine(): ?string
    {
        if ($this->host === null) {
            return null;
        }
        return sprintf("c=%s", SDPUtility::ipAddressToSdp($this->host));
    }

    /**
     * Generates the "a=" direction line.
     *
     * @return string|null The direction line, or null if no direction is set.
     */
    private function generateDirectionLine(): ?string
    {
        if ($this->direction === null) {
            return null;
        }
        return sprintf("a=%s", $this->direction->name);
    }

    /**
     * Generates the "a=extmap:" header extension lines.
     *
     * @return array<string> The header extension lines.
     */
    private function generateHeaderExtensions(): array
    {
        $lines = [];
        foreach ($this->rtp->headerExtensions as $header) {
            $lines[] = sprintf("a=extmap:%d %s", $header->id, $header->uri);
        }
        return $lines;
    }

    /**
     * Generates the "a=mid:" line.
     *
     * @return string|null The mid-line, or null if no muxId is set.
     */
    private function generateMidLine(): ?string
    {
        if ($this->rtp->muxId === null || $this->rtp->muxId === "") {
            return null;
        }
        return sprintf("a=mid:%s", $this->rtp->muxId);
    }

    /**
     * Generates the "a=msid:" line.
     *
     * @return string|null The msid line, or null if no msid is set.
     */
    private function generateMsidLine(): ?string
    {
        if ($this->msid === null) {
            return null;
        }
        return sprintf("a=msid:%s", $this->msid);
    }

    /**
     * Generates the RTCP-related lines.
     *
     * @return array<string> The RTCP lines.
     */
    private function generateRtcpLines(): array
    {
        $lines = [];
        if ($this->rtcpPort !== null && $this->rtcpHost !== null) {
            $lines[] = sprintf(
                "a=rtcp:%d %s",
                $this->rtcpPort,
                SDPUtility::ipAddressToSdp($this->rtcpHost)
            );
            if ($this->rtcpMux) {
                $lines[] = "a=rtcp-mux";
            }
        }
        return $lines;
    }

    /**
     * Generates the "a=ssrc-group:" lines.
     *
     * @return array<string> The SSRC group lines.
     */
    private function generateSsrcGroupLines(): array
    {
        $lines = [];
        foreach ($this->ssrcGroup as $group) {
            $lines[] = sprintf("a=ssrc-group:%s", $group);
        }
        return $lines;
    }

    /**
     * Generates the "a=ssrc:" lines.
     *
     * @return array<string> The SSRC lines.
     */
    private function generateSsrcLines(): array
    {
        $lines = [];
        foreach ($this->ssrc as $ssrcInfo) {
            foreach (['cname', 'msid', 'mslabel', 'label'] as $attr) {
                $value = $ssrcInfo->$attr;
                if ($value !== null) {
                    $lines[] = sprintf("a=ssrc:%d %s:%s", $ssrcInfo->ssrc, $attr, $value);
                }
            }
        }
        return $lines;
    }

    /**
     * Generates the codec-related lines.
     *
     * @return array<string> The codec lines.
     */
    private function generateCodecLines(): array
    {
        $lines = [];
        foreach ($this->rtp->codecs as $codec) {
            $lines[] = sprintf("a=rtpmap:%d %s", $codec->payloadType, $codec);

            // RTCP feedback
            foreach ($codec->rtcpFeedback as $feedback) {
                $value = $feedback->type;
                if ($feedback->parameter !== null) {
                    $value .= ' ' . $feedback->parameter;
                }
                $lines[] = sprintf("a=rtcp-fb:%d %s", $codec->payloadType, $value);
            }

            // Parameters
            $params = SDPUtility::parametersToSdp($codec->parameters);
            if ($params !== '') {
                $lines[] = sprintf("a=fmtp:%d %s", $codec->payloadType, $params);
            }
        }
        return $lines;
    }

    /**
     * Generates the SCTP-related lines.
     *
     * @return array<string> The SCTP lines.
     */
    private function generateSctpLines(): array
    {
        $lines = [];
        foreach ($this->sctpmap as $key => $value) {
            $lines[] = sprintf("a=sctpmap:%d %s", $key, $value);
        }
        if ($this->sctpPort !== null) {
            $lines[] = sprintf("a=sctp-port:%d", $this->sctpPort);
        }
        if ($this->sctpCapabilities !== null) {
            $lines[] = sprintf("a=max-message-size:%d", $this->sctpCapabilities->maxMessageSize);
        }
        return $lines;
    }

    /**
     * Generates the ICE-related lines.
     *
     * @return array<string> The ICE lines.
     */
    private function generateIceLines(): array
    {
        $lines = [];
        foreach ($this->iceCandidates as $candidate) {
            $lines[] = sprintf("a=candidate:%s", $candidate->convert2SDP());
        }
        if ($this->iceCandidatesComplete) {
            $lines[] = "a=end-of-candidates";
        }
        if ($this->ice !== null && $this->ice->usernameFragment !== null) {
            $lines[] = sprintf("a=ice-ufrag:%s", $this->ice->usernameFragment);
        }
        if ($this->ice !== null && $this->ice->password !== null) {
            $lines[] = sprintf("a=ice-pwd:%s", $this->ice->password);
        }
        if ($this->iceOptions !== null) {
            $lines[] = sprintf("a=ice-options:%s", $this->iceOptions);
        }
        return $lines;
    }

    /**
     * Generates the DTLS-related lines.
     *
     * @return array<string> The DTLS lines.
     */
    private function generateDtlsLines(): array
    {
        $lines = [];
        if (!empty($this->dtls->fingerprints)) {
            foreach ($this->dtls->fingerprints as $fingerprint) {
                $lines[] = sprintf(
                    "a=fingerprint:%s %s",
                    $fingerprint->algorithm,
                    $fingerprint->value
                );
            }
            $lines[] = sprintf("a=setup:%s", self::DTLS_ROLE_SETUP[$this->dtls->role->value] ?? 'actpass');
        }
        return $lines;
    }

    /**
     * @return RTCDtlsParameters|null
     */
    public function getDtls(): ?RTCDtlsParameters
    {
        return $this->dtls;
    }

    /**
     * @param RTCDtlsParameters|null $dtls
     * @return void
     */
    public function setDtls(?RTCDtlsParameters $dtls): void
    {
        $this->dtls = $dtls;
    }

    /**
     * @return RTCIceParameters|null
     */
    public function getIce(): ?RTCIceParameters
    {
        return $this->ice;
    }

    /**
     * @param RTCIceParameters|null $ice
     * @return void
     */
    public function setIce(?RTCIceParameters $ice): void
    {
        $this->ice = $ice;
    }

    /**
     * @return string|null
     */
    public function getIceOptions(): ?string
    {
        return $this->iceOptions;
    }

    /**
     * @param string|null $iceOptions
     * @return void
     */
    public function setIceOptions(?string $iceOptions): void
    {
        $this->iceOptions = $iceOptions;
    }

    /**
     * @return string|null
     */
    public function getHost(): ?string
    {
        return $this->host;
    }

    /**
     * @param string|null $host
     * @return void
     */
    public function setHost(?string $host): void
    {
        $this->host = $host;
    }

    /**
     * @return RTCIceCandidate[]
     */
    public function getIceCandidates(): array
    {
        return $this->iceCandidates;
    }

    /**
     * @param array $iceCandidates
     * @return void
     */
    public function setIceCandidates(array $iceCandidates): void
    {
        $this->iceCandidates = $iceCandidates;
    }

    /**
     * @param RTCIceCandidate $iceCandidate
     * @return void
     */
    public function addIceCandidates(RTCIceCandidate $iceCandidate): void
    {
        $this->iceCandidates [] = $iceCandidate;
    }

    /**
     * @return bool
     */
    public function isIceCandidatesComplete(): bool
    {
        return $this->iceCandidatesComplete;
    }

    /**
     * @param bool $iceCandidatesComplete
     * @return void
     */
    public function setIceCandidatesComplete(bool $iceCandidatesComplete): void
    {
        $this->iceCandidatesComplete = $iceCandidatesComplete;
    }

    /**
     * @return RTCSctpCapabilities|null
     */
    public function getSctpCapabilities(): ?RTCSctpCapabilities
    {
        return $this->sctpCapabilities;
    }

    /**
     * @param RTCSctpCapabilities|null $sctpCapabilities
     * @return void
     */
    public function setSctpCapabilities(?RTCSctpCapabilities $sctpCapabilities): void
    {
        $this->sctpCapabilities = $sctpCapabilities;
    }

    /**
     * @return RTCRtpParameters
     */
    public function getRtp(): RTCRtpParameters
    {
        return $this->rtp;
    }

    /**
     * @param RTCRtpParameters $rtp
     * @return void
     */
    public function setRtp(RTCRtpParameters $rtp): void
    {
        $this->rtp = $rtp;
    }

    /**
     * @return string|null
     */
    public function getMsid(): ?string
    {
        return $this->msid;
    }

    /**
     * @param string|null $msid
     * @return void
     */
    public function setMsid(?string $msid): void
    {
        $this->msid = $msid;
    }

    /**
     * @return int|null
     */
    public function getRtcpPort(): ?int
    {
        return $this->rtcpPort;
    }

    /**
     * @param int|null $rtcpPort
     * @return void
     */
    public function setRtcpPort(?int $rtcpPort): void
    {
        $this->rtcpPort = $rtcpPort;
    }

    /**
     * @return string|null
     */
    public function getRtcpHost(): ?string
    {
        return $this->rtcpHost;
    }

    /**
     * @param string|null $rtcpHost
     * @return void
     */
    public function setRtcpHost(?string $rtcpHost): void
    {
        $this->rtcpHost = $rtcpHost;
    }

    /**
     * @return bool
     */
    public function isRtcpMux(): bool
    {
        return $this->rtcpMux;
    }

    /**
     * @param bool $rtcpMux
     * @return void
     */
    public function setRtcpMux(bool $rtcpMux): void
    {
        $this->rtcpMux = $rtcpMux;
    }

    /**
     * @return string[]
     */
    public function getSctpmap(): array
    {
        return $this->sctpmap;
    }

    /**
     * @param array $sctpmap
     * @return void
     */
    public function setSctpmap(array $sctpmap): void
    {
        $this->sctpmap = $sctpmap;
    }

    /**
     * @param int $formatId
     * @param string $formatDesc
     * @return void
     */
    public function addSctpmap(int $formatId, string $formatDesc): void
    {
        $this->sctpmap[$formatId] = $formatDesc;
    }

    /**
     * @return int|null
     */
    public function getSctpPort(): ?int
    {
        return $this->sctpPort;
    }

    /**
     * @param int|null $sctpPort
     * @return void
     */
    public function setSctpPort(?int $sctpPort): void
    {
        $this->sctpPort = $sctpPort;
    }

    /**
     * @return GroupDescription[]
     */
    public function getSsrcGroup(): array
    {
        return $this->ssrcGroup;
    }

    /**
     * @param array $ssrcGroup
     * @return void
     */
    public function setSsrcGroup(array $ssrcGroup): void
    {
        $this->ssrcGroup = $ssrcGroup;
    }

    /**
     * @param GroupDescription $ssrcGroup
     * @return void
     */
    public function addSsrcGroup(GroupDescription $ssrcGroup): void
    {
        $this->ssrcGroup []= $ssrcGroup;
    }

    /**
     * @return SsrcDescription[]
     */
    public function getSsrc(): array
    {
        return $this->ssrc;
    }

    /**
     * @param array $ssrc
     * @return void
     */
    public function setSsrc(array $ssrc): void
    {
        $this->ssrc = $ssrc;
    }

    /**
     * @param SsrcDescription $ssrc
     * @return void
     */
    public function addSsrc(SsrcDescription $ssrc): void
    {
        $this->ssrc []= $ssrc;
    }

    /**
     * @return string
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * @param string $kind
     * @return void
     */
    public function setKind(string $kind): void
    {
        $this->kind = $kind;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @param int $port
     * @return void
     */
    public function setPort(int $port): void
    {
        $this->port = $port;
    }

    /**
     * @return string
     */
    public function getProfile(): string
    {
        return $this->profile;
    }

    /**
     * @param string $profile
     * @return void
     */
    public function setProfile(string $profile): void
    {
        $this->profile = $profile;
    }

    /**
     * @return SDPDirections|null
     */
    public function getDirection(): ?SDPDirections
    {
        return $this->direction;
    }

    /**
     * @param SDPDirections|null $direction
     * @return void
     */
    public function setDirection(?SDPDirections $direction): void
    {
        $this->direction = $direction;
    }

    /**
     * @return array
     */
    public function getFmt(): array
    {
        return $this->fmt;
    }

    /**
     * @param array $fmt
     * @return void
     */
    public function setFmt(array $fmt): void
    {
        $this->fmt = $fmt;
    }
}