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

use Webrtc\Exception\InvalidArgumentException;
use Webrtc\ICE\RTCIceCandidate;
use Webrtc\RTPParameter\RTCRtcpFeedback;
use Webrtc\RTPParameter\RTCRtpCodecParameters;
use Webrtc\RTPParameter\RTCRtpHeaderExtensionParameters;
use Webrtc\SDP\DtlsParameter\RTCDtlsFingerprint;
use Webrtc\SDP\Enum\DtlsRole;
use Webrtc\SDP\Enum\SDPDirections;
use Webrtc\SDP\SctpParameter\RTCSctpCapabilities;

/**
 * Represents a session description for SDP (Session Description Protocol).
 */
class SessionDescription
{
    /** @var array<string, string> Mapping of DTLS setup roles. */
    private const DTLS_SETUP_ROLE = [
        "actpass" => DtlsRole::Auto,
        "active" => DtlsRole::Client,
        "passive" => DtlsRole::Server,
    ];

    /** @var string[] Supported SSRC attributes. */
    private const SSRC_INFO_ATTRS = ["cname", "msid", "mslabel", "label"];
    /**
     * @var mixed[]
     */
    private const FORBIDDEN_PAYLOAD_TYPES = [72, 73, 74, 75, 76];
    /**
     * @var mixed[]
     */
    const DIRECTIONS = ["inactive", "sendonly", "recvonly", "sendrecv"];
    /** @var int The SDP version. */
    private int $version = 0;
    /** @var string|null The origin field. */
    private ?string $origin = null;
    /** @var string The session name. */
    private string $name = "-";
    /** @var string The session time. */
    private string $time = "0 0";
    /** @var string|null The connection host. */
    private ?string $host = null;
    /** @var GroupDescription[] The group descriptions. */
    private array $group = [];
    /** @var GroupDescription[] The MSID semantic descriptions. */
    private array $msidSemantic = [];
    /** @var MediaDescription[] The media descriptions. */
    private array $media = [];
    /** @var RTCDtlsFingerprint[] */
    private ?array $dtlsFingerprints = null;
    private bool $iceLite = false;
    private ?string $iceOptions = null;
    private ?DtlsRole $dtlsRole = null;
    private ?string $icePassword = null;
    private ?string $iceUsernameFragment = null;
    private ?string $type = null;

    /**
     * Initializes a new SessionDescription instance.
     */
    public function __construct()
    {
    }

    /**
     * Decodes an SDP string and creates a SessionDescription instance.
     *
     * @param string $sdp The SDP string to decode.
     * @return SessionDescription The decoded session description.
     */
    public static function decode(string $sdp): SessionDescription
    {
        $session = new SessionDescription();
        list($sessionLines, $mediaGroups) = self::splitSdpIntoLines($sdp);

        $session->decodeSessionLines($sessionLines);
        $session->decodeMediaGroups($mediaGroups);

        return $session;
    }

    /**
     * Splits an SDP string into session lines and media groups.
     *
     * @param string $sdp The SDP string.
     * @return array{string[], string[][]} The session lines and media groups.
     */
    private static function splitSdpIntoLines(string $sdp): array
    {
        $lines = explode("\r\n", trim($sdp));
        $sessionLines = [];
        $mediaGroups = [];
        $currentMediaGroup = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, "m=")) {
                if (!empty($currentMediaGroup)) {
                    $mediaGroups[] = $currentMediaGroup;
                }
                $currentMediaGroup = [$line];
            } elseif (!empty($currentMediaGroup)) {
                $currentMediaGroup[] = $line;
            } else {
                $sessionLines[] = $line;
            }
        }

        if (!empty($currentMediaGroup)) {
            $mediaGroups[] = $currentMediaGroup;
        }

        return [$sessionLines, $mediaGroups];
    }

    /**
     * Decodes session-level lines.
     *
     * @param string[] $sessionLines The session lines.
     */
    private function decodeSessionLines(array $sessionLines): void
    {
        foreach ($sessionLines as $line) {
            if (str_starts_with($line, "v=")) {
                $this->version = (int)substr($line, 2);
            } elseif (str_starts_with($line, "o=")) {
                $this->origin = substr($line, 2);
            } elseif (str_starts_with($line, "s=")) {
                $this->name = substr($line, 2);
            } elseif (str_starts_with($line, "c=")) {
                $this->host = SDPUtility::ipAddressFromSdp(substr($line, 2));
            } elseif (str_starts_with($line, "t=")) {
                $this->time = substr($line, 2);
            } elseif (str_starts_with($line, "a=")) {
                list($attr, $value) = self::parseAttributeLine($line);
                switch ($attr) {
                    case "fingerprint":
                        list($algorithm, $fingerprint) = explode(" ", $value);
                        $this->dtlsFingerprints[] = new RTCDtlsFingerprint($algorithm, $fingerprint);
                        break;
                    case "ice-lite":
                        $this->iceLite = true;
                        break;
                    case "ice-options":
                        $this->iceOptions = $value;
                        break;
                    case "ice-pwd":
                        $this->icePassword = $value;
                        break;
                    case "ice-ufrag":
                        $this->iceUsernameFragment = $value;
                        break;
                    case "group":
                        $this->group[] = SDPUtility::parseGroup($value);
                        break;
                    case "msid-semantic":
                        $this->msidSemantic[] = SDPUtility::parseGroup($value);
                        break;
                    case "setup":
                        $this->dtlsRole = self::DTLS_SETUP_ROLE[$value] ?? null;
                        break;
                }
            }
        }
    }

    /**
     * Decodes media-level groups.
     *
     * @param string[][] $mediaGroups The media groups.
     */
    private function decodeMediaGroups(array $mediaGroups): void
    {
        foreach ($mediaGroups as $mediaLines) {
            $media = $this->decodeMediaDescription($mediaLines[0]);
            $this->media[] = $media;
            $this->assignMediaAttribute($media);

            foreach (array_slice($mediaLines, 1) as $line) {
                $this->decodeMediaAttribute($media, $line);
            }

            foreach (array_slice($mediaLines, 1) as $line) {
                $this->decodeMediaFmtpAndRtcpFpAttribute($media, $line);
            }

            if ($media->getDtls()->role === null) {
                $media->setDtls(null);
            }
        }
    }

    /**
     * Apply session-level attributes to media descriptions
     *
     * @param MediaDescription $media
     * @return void
     */
    private function assignMediaAttribute(MediaDescription $media): void
    {
        if ($this->dtlsFingerprints) {
            $media->getDtls()->fingerprints = $this->dtlsFingerprints;
        }
        if ($this->dtlsRole) {
            $media->getDtls()->role = $this->dtlsRole;
        }
        if ($this->iceUsernameFragment) {
            $media->getIce()->usernameFragment = $this->iceUsernameFragment;
        }
        if($this->icePassword) {
            $media->getIce()->password = $this->icePassword;
        }
        if ($this->iceLite) {
            $media->getIce()->iceLite = true;
        }
        if ($this->iceOptions) {
            $media->setIceOptions($this->iceOptions);
        }
    }

    /**
     * Decodes a media description line.
     *
     * @param string $line The media description line.
     * @return MediaDescription The decoded media description.
     */
    private function decodeMediaDescription(string $line): MediaDescription
    {
        if (!preg_match("/^m=([^ ]+) ([0-9]+) ([A-Z\/]+) (.+)$/", $line, $matches)) {
            throw new InvalidArgumentException("Invalid media line format");
        }

        $kind = $matches[1];
        $port = (int)$matches[2];
        $profile = $matches[3];
        $fmt = explode(" ", $matches[4]);

        // Validate payload types
        $fmtInt = null;
        if (in_array($kind, ["audio", "video"])) {
            $fmtInt = array_map('intval', $fmt);
            foreach ($fmtInt as $pt) {
                if ($pt < 0 || $pt >= 256 || in_array($pt, self::FORBIDDEN_PAYLOAD_TYPES)) {
                    throw new InvalidArgumentException("Invalid payload type: $pt");
                }
            }
        }

        return new MediaDescription($kind, $port, $profile, $fmtInt ?? $fmt);
    }

    /**
     * Decodes a media attribute line.
     *
     * @param MediaDescription $media The media description.
     * @param string $line The attribute line.
     */
    private function decodeMediaAttribute(MediaDescription $media, string $line): void
    {
        if (str_starts_with($line, "c=")) {
            $media->setHost(SDPUtility::ipAddressFromSdp(substr($line, 2)));
        } elseif (str_starts_with($line, "a=")) {
            list($attr, $value) = self::parseAttributeLine($line);
            switch ($attr) {
                case "candidate":
                    $media->addIceCandidates(RTCIceCandidate::parseSDP($value));
                    break;
                case "end-of-candidates":
                    $media->setIceCandidatesComplete(true);
                    break;
                case "extmap":
                    $this->decodeExtmapAttribute($media, $value);
                    break;
                case "fingerprint":
                    list($algorithm, $fingerprint) = explode(" ", $value);
                    $media->getDtls()->fingerprints[] = new RTCDtlsFingerprint($algorithm, $fingerprint);
                    break;
                case "ice-options":
                    $media->setIceOptions($value);
                    break;
                case "ice-pwd":
                    $media->getIce()->password = $value;
                    break;
                case "ice-ufrag":
                    $media->getIce()->usernameFragment = $value;
                    break;
                case "max-message-size":
                    $media->setSctpCapabilities(new RTCSctpCapabilities((int)$value));
                    break;
                case "mid":
//                    var_dump($value);
                    $media->getRtp()->muxId = $value;
                    break;
                case "msid":
                    $media->setMsid($value);
                    break;
                case "rtcp":
                    $this->decodeRtcpAttribute($media, $value);
                    break;
                case "rtcp-mux":
                    $media->setRtcpMux(true);
                    break;
                case "setup":
                    $media->getDtls()->role = self::DTLS_SETUP_ROLE[$value] ?? "";
                    break;
                case "rtpmap":
                    $this->decodeRtpmapAttribute($media, $value);
                    break;
                case "sctpmap":
                    list($formatId, $formatDesc) = explode(" ", $value, 2);
                    $media->addSctpmap($formatId, $formatDesc);
                    break;
                case "sctp-port":
                    $media->setSctpPort($value);
                    break;
                case "ssrc-group":
                    $media->addSsrcGroup(SDPUtility::parseGroup($value, "int"));
                    break;
                case "ssrc":
                    $this->decodeSsrcAttribute($media, $value);
                    break;
                default:
                    if (in_array($attr, self::DIRECTIONS)) {
                        $media->setDirection(constant(SDPDirections::class . '::' . $attr));
                    }
            }
        }
    }

    /**
     * Decodes a media attribute of fmtp and rtcp-fb line that requires codecs to have been decoded first.
     *
     * @param MediaDescription $media The media description.
     * @param string $line The attribute line.
     */
    private function decodeMediaFmtpAndRtcpFpAttribute(MediaDescription $media, string $line): void
    {
        if (str_starts_with($line, "a=")) {
            list($attr, $value) = self::parseAttributeLine($line);
            switch ($attr) {
                case "fmtp":
                    $this->decodeFmtpAttribute($media, $value);
                    break;
                case "rtcp-fb":
                    $this->decodeRtcpFbAttribute($media, $value);
                    break;
            }
        }
    }

    /**
     * Decodes an "extmap" attribute.
     *
     * @param MediaDescription $media The media description.
     * @param string $value The attribute value.
     */
    private function decodeExtmapAttribute(MediaDescription $media, string $value): void
    {
        list($extId, $extUri) = explode(" ", $value);
        if (str_contains($extId, "/")) {
            list($extId,) = explode("/", $extId);
        }
        $extension = new RTCRtpHeaderExtensionParameters((int)$extId, $extUri);
        $media->getRtp()->headerExtensions[] = $extension;
    }

    /**
     * Decodes an "rtcp" attribute.
     *
     * @param MediaDescription $media The media description.
     * @param string $value The attribute value.
     */
    private function decodeRtcpAttribute(MediaDescription $media, string $value): void
    {
        list($port, $rest) = explode(" ", $value, 2);
        $media->setRtcpPort($port);
        $media->setRtcpHost(SDPUtility::ipAddressFromSdp($rest));
    }

    /**
     * Decodes an "rtpmap" attribute.
     *
     * @param MediaDescription $media The media description.
     * @param string $value The attribute value.
     */
    private function decodeRtpmapAttribute(MediaDescription $media, string $value): void
    {
        list($formatId, $formatDesc) = explode(" ", $value, 2);

        $bits = explode("/", $formatDesc);
        $channels = $media->getKind() === "video" ? null : (($media->getKind() === "audio" && count($bits) > 2) ? (int)$bits[2] : 1);
        $codec = new RTCRtpCodecParameters(
            $media->getKind() . "/" . $bits[0],
            (int)$bits[1],
            $channels,
            (int)$formatId
        );
        $media->getRtp()->codecs[] = $codec;
    }

    /**
     * Decodes a "ssrc" attribute.
     *
     * @param MediaDescription $media The media description.
     * @param string $value The attribute value.
     */
    private function decodeSsrcAttribute(MediaDescription $media, string $value): void
    {
        list($ssrcStr, $ssrcDesc) = explode(" ", $value, 2);
        $ssrc = (int)$ssrcStr;
        list($ssrcAttr, $ssrcValue) = explode(":", $ssrcDesc, 2);

        $ssrcInfo = null;
        foreach ($media->getSsrc() as $info) {
            if ($info->ssrc === $ssrc) {
                $ssrcInfo = $info;
                break;
            }
        }
        if ($ssrcInfo === null) {
            $ssrcInfo = new SsrcDescription($ssrc);
            $media->addSsrc($ssrcInfo);
        }
        if (in_array($ssrcAttr, self::SSRC_INFO_ATTRS)) {
            $ssrcInfo->$ssrcAttr = $ssrcValue;
        }
    }

    /**
     * Decodes an "fmtp" attribute.
     *
     * @param MediaDescription $media The media description.
     * @param string $value The attribute value.
     */
    private function decodeFmtpAttribute(MediaDescription $media, string $value): void
    {
        list($formatId, $formatDesc) = explode(" ", $value, 2);
        $codec = self::findCodec($media->getRtp()->codecs, (int)$formatId);
        $codec->parameters = SDPUtility::parametersFromSdp($formatDesc);
    }

    /**
     * Decodes an "rtcp-fb" attribute.
     *
     * @param MediaDescription $media The media description.
     * @param string $value The attribute value.
     */
    private function decodeRtcpFbAttribute(MediaDescription $media, string $value): void
    {
        $bits = explode(" ", $value, 3);
        foreach ($media->getRtp()->codecs as $codec) {
            if ($bits[0] === "*" || $bits[0] === (string)$codec->payloadType) {
                $codec->rtcpFeedback[] = new RTCRtcpFeedback(
                    $bits[1],
                    $bits[2] ?? null
                );
            }
        }
    }

    /**
     * Parses an attribute line.
     *
     * @param string $line The attribute line.
     * @return array{string, string} The attribute name and value.
     */
    private static function parseAttributeLine(string $line): array
    {
        $parts = explode(":", substr($line, 2), 2);
        return [$parts[0], $parts[1] ?? ""];
    }

    /**
     * Finds a codec by payload type.
     *
     * @param RTCRtpCodecParameters[] $codecs The codec array.
     * @param int $payloadType The payload type.
     * @return RTCRtpCodecParameters The codec.
     * @throws InvalidArgumentException If the codec is not found.
     */
    private static function findCodec(array $codecs, int $payloadType): RTCRtpCodecParameters
    {
        foreach ($codecs as $codec) {
            if ($codec->payloadType === $payloadType) {
                return $codec;
            }
        }
        throw new InvalidArgumentException("Codec not found for payload type: $payloadType");
    }

    /**
     * Returns the string representation of the session description in SDP format.
     *
     * @return string The SDP-formatted session description.
     */
    public function __toString(): string
    {
        $lines = [
            "v=$this->version",
            "o=$this->origin",
            "s=$this->name",
        ];

        if ($this->host !== null) {
            $lines[] = "c=" . SDPUtility::ipAddressToSdp($this->host);
        }

        $lines[] = "t=$this->time";

        if (array_reduce($this->media, fn($carry, $m) => $carry || $m->getIce()->iceLite, false)) {
            $lines[] = "a=ice-lite";
        }

        foreach ($this->group as $group) {
            $lines[] = "a=group:$group";
        }

        foreach ($this->msidSemantic as $group) {
            $lines[] = "a=msid-semantic:$group";
        }

        return implode("\r\n", $lines) . "\r\n" . implode("", array_map('strval', $this->media));
    }

    /**
     * Returns the WebRTC track ID for a media description.
     *
     * @param MediaDescription $media The media description.
     * @return string|null The track ID, or null if not found.
     */
    public function webrtcTrackId(MediaDescription $media): ?string
    {
        if (!in_array($media, $this->media, true)) {
            throw new InvalidArgumentException("Media description not found in session");
        }

        if ($media->getMsid() !== null && str_contains($media->getMsid(), " ")) {
            $bits = explode(" ", $media->getMsid());
            if (array_any($this->msidSemantic, fn($group) => $group->semantic === "WMS" && (in_array($bits[0], $group->items) || in_array("*", $group->items)))) {
                return $bits[1];
            }
        }

        return null;
    }

    /**
     * @return int
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * @return string|null
     */
    public function getHost(): ?string
    {
        return $this->host;
    }

    /**
     * @return string|null
     */
    public function getOrigin(): ?string
    {
        return $this->origin;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getTime(): string
    {
        return $this->time;
    }

    /**
     * @return GroupDescription[]
     */
    public function getMsidSemantic(): array
    {
        return $this->msidSemantic;
    }

    /**
     * @param GroupDescription $description
     * @return void
     */
    public function appendMsidSemantic(GroupDescription $description): void
    {
        $this->msidSemantic [] = $description;
    }

    /**
     * @return GroupDescription[]
     */
    public function getGroup(): array
    {
        return $this->group;
    }

    /**
     * @return MediaDescription[]
     */
    public function getMedia(): array
    {
        return $this->media;
    }

    /**
     * @param array $media
     * @return void
     */
    public function setMedia(array $media): void
    {
        $this->media = $media;
    }

    /**
     * @param MediaDescription $description
     * @return void
     */
    public function addMedia(MediaDescription $description): void
    {
        $this->media[] = $description;
    }

    /**
     * @return string|null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return bool
     */
    public function isType(string $type): bool
    {
        return $this->type === $type;
    }

    /**
     * @param string|null $type
     * @return void
     */
    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    /**
     * @param string|null $origin
     * @return void
     */
    public function setOrigin(?string $origin): void
    {
        $this->origin = $origin;
    }

    /**
     * @param array $group
     * @return void
     */
    public function setGroup(array $group): void
    {
        $this->group = $group;
    }

    /**
     * @param GroupDescription $group
     * @return void
     */
    public function addGroup(GroupDescription $group): void
    {
        $this->group[] = $group;
    }
}