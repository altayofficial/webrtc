<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Codecs\Video\Vp8;

use Webrtc\Codecs\PayloadDescriptorInterface;
use Webrtc\Exception\InvalidArgumentException;

/**
 * VP8 Payload Descriptor Class
 *
 * Handles the generation and parsing of VP8 payload descriptors for RTP packets.
 * Implements RFC 7741 for VP8 video payload header in RTP streams.
 *
 * @package Webrtc\Codecs\Video\Vp8
 */
class Vp8PayloadDescriptor implements PayloadDescriptorInterface
{
    /**
     * @var int $partitionStart Indicates start of partition (1-bit)
     */
    private int $partitionStart;

    /**
     * @var int $partitionId Partition index (4-bit)
     */
    private int $partitionId;

    /**
     * @var int|null $pictureId Picture identifier (7 or 15-bit)
     */
    private ?int $pictureId;

    /**
     * @var int|null $tl0picidx Temporal layer zero index (8-bit)
     */
    private ?int $tl0picidx;

    /**
     * @var array|null $tid Temporal layer indices [Y, K] (2-bit + 1-bit)
     */
    private ?array $tid;

    /**
     * @var int|null $keyidx Key frame index (5-bit)
     */
    private ?int $keyidx;

    /**
     * Constructor
     *
     * @param int $partitionStart Partition start flag
     * @param int $partitionId Partition identifier
     * @param int|null $pictureId Optional picture ID
     * @param int|null $tl0picidx Optional TL0PICIDX
     * @param array|null $tid Optional temporal layer info
     * @param int|null $keyidx Optional keyframe index
     */
    public function __construct(
        int    $partitionStart,
        int    $partitionId,
        ?int   $pictureId = null,
        ?int   $tl0picidx = null,
        ?array $tid = null,
        ?int   $keyidx = null
    )
    {
        $this->partitionStart = $partitionStart;
        $this->partitionId = $partitionId;
        $this->pictureId = $pictureId;
        $this->tl0picidx = $tl0picidx;
        $this->tid = $tid;
        $this->keyidx = $keyidx;
    }

    /**
     * Gets partition start flag
     *
     * @return int Partition start (0 or 1)
     */
    public function getPartitionStart(): int
    {
        return $this->partitionStart;
    }

    /**
     * Gets partition ID
     *
     * @return int Partition identifier (0-15)
     */
    public function getPartitionId(): int
    {
        return $this->partitionId;
    }

    /**
     * Gets picture ID
     *
     * @return int|null Picture identifier or null
     */
    public function getPictureId(): ?int
    {
        return $this->pictureId;
    }

    /**
     * Gets TL0PICIDX
     *
     * @return int|null Temporal layer zero index or null
     */
    public function getTl0picidx(): ?int
    {
        return $this->tl0picidx;
    }

    /**
     * Gets temporal layer info
     *
     * @return array|null [Y, K] indices or null
     */
    public function getTid(): ?array
    {
        return $this->tid;
    }

    /**
     * Gets keyframe index
     *
     * @return int|null Keyframe index or null
     */
    public function getKeyidx(): ?int
    {
        return $this->keyidx;
    }

    /**
     * Encodes descriptor to binary string
     *
     * @return string Binary payload descriptor
     */
    public function encode(): string
    {
        $data = $this->encodeHeader();
        $data .= $this->encodeExtendedFields();
        return $data;
    }

    /**
     * Encode Header
     *
     * @return string
     */
    private function encodeHeader(): string
    {
        $octet = ($this->partitionStart << 4) | $this->partitionId;
        $extOctet = $this->computeExtensionOctet();

        return $extOctet ? pack('C', (1 << 7) | $octet) . pack('C', $extOctet) : pack('C', $octet);
    }

    /**
     * @return int
     */
    private function computeExtensionOctet(): int
    {
        $extOctet = 0;
        if ($this->pictureId !== null) {
            $extOctet |= 1 << 7;
        }
        if ($this->tl0picidx !== null) {
            $extOctet |= 1 << 6;
        }
        if ($this->tid !== null) {
            $extOctet |= 1 << 5;
        }
        if ($this->keyidx !== null) {
            $extOctet |= 1 << 4;
        }
        return $extOctet;
    }

    /**
     * @return string
     */
    private function encodeExtendedFields(): string
    {
        $data = '';
        if ($this->pictureId !== null) {
            $data .= $this->encodePictureId();
        }
        if ($this->tl0picidx !== null) {
            $data .= pack('C', $this->tl0picidx);
        }
        if ($this->tid !== null || $this->keyidx !== null) {
            $data .= $this->encodeTidKeyidx();
        }
        return $data;
    }

    /**
     * @return string
     */
    private function encodePictureId(): string
    {
        return $this->pictureId < 128 ? pack('C', $this->pictureId) : pack('n', (1 << 15) | $this->pictureId);
    }

    /**
     * @return string
     */
    private function encodeTidKeyidx(): string
    {
        $t_k = 0;
        if ($this->tid !== null) {
            $t_k |= ($this->tid[0] << 6) | ($this->tid[1] << 5);
        }
        if ($this->keyidx !== null) {
            $t_k |= $this->keyidx;
        }
        return pack('C', $t_k);
    }

    /**
     * Decodes descriptor from binary data
     *
     * @param string $data Binary input data
     * @return array [Vp8PayloadDescriptor, remaining_data]
     * @throws InvalidArgumentException On malformed input
     */
    public static function decode(string $data): array
    {
        if (strlen($data) < 1) {
            throw new InvalidArgumentException("VPX descriptor is too short");
        }

        $pos = 0;
        $octet = ord($data[$pos++]);
        $extended = $octet >> 7;
        $partitionStart = ($octet >> 4) & 1;
        $partitionId = $octet & 0xF;

        list($pictureId, $tl0picidx, $tid, $keyidx, $pos) = self::decodeExtendedFields($data, $pos, $extended);

        return [new self($partitionStart, $partitionId, $pictureId, $tl0picidx, $tid, $keyidx), substr($data, $pos)];
    }

    /**
     * @param string $data
     * @param int $pos
     * @param bool $extended
     * @return array
     */
    private static function decodeExtendedFields(string $data, int $pos, bool $extended): array
    {
        $pictureId = $tl0picidx = $tid = $keyidx = null;
        if ($extended) {
            list($extI, $extL, $extT, $extK, $pos) = self::decodeExtensionOctet($data, $pos);
            if ($extI) {
                list($pictureId, $pos) = self::decodePictureId($data, $pos);
            }
            if ($extL) {
                if (strlen($data) < $pos + 1) {
                    throw new InvalidArgumentException("VPX descriptor has truncated TL0PICIDX");
                }
                $tl0picidx = ord($data[$pos++]);
            }
            if ($extT || $extK) {
                list($tid, $keyidx) = self::decodeTidKeyidx($data, $pos++, $extT, $extK);
            }
        }
        return [$pictureId, $tl0picidx, $tid, $keyidx, $pos];
    }

    /**
     * @param string $data
     * @param int $pos
     * @return int[]
     */
    private static function decodeExtensionOctet(string $data, int $pos): array
    {
        if (strlen($data) < $pos + 1) {
            throw new InvalidArgumentException("VPX descriptor has truncated extended bits");
        }
        $octet = ord($data[$pos++]);
        $extI = ($octet >> 7) & 1;
        $extL = ($octet >> 6) & 1;
        $extT = ($octet >> 5) & 1;
        $extK = ($octet >> 4) & 1;

        return [$extI, $extL, $extT, $extK, $pos];
    }

    /**
     * @param string $data
     * @param int $pos
     * @return array
     */
    private static function decodePictureId(string $data, int $pos): array
    {
        if (strlen($data) < $pos + 1) {
            throw new InvalidArgumentException("VPX descriptor has truncated PictureID");
        }
        if (ord($data[$pos]) & 0x80) {
            if (strlen($data) < $pos + 2) {
                throw new InvalidArgumentException("VPX descriptor has truncated long PictureID");
            }
            $pictureId = unpack('n', substr($data, $pos, 2))[1] & 0x7FFF;
            $pos += 2;
        } else {
            $pictureId = ord($data[$pos++]);
        }

        return [$pictureId, $pos];
    }

    /**
     * @param string $data
     * @param int $pos
     * @param int $extT
     * @param int $extK
     * @return array
     */
    private static function decodeTidKeyidx(string $data, int $pos, int $extT, int $extK): array
    {
        if (strlen($data) < $pos + 1) {
            throw new InvalidArgumentException("VPX descriptor has truncated T/K");
        }
        $t_k = ord($data[$pos++]);
        $keyidx = null;
        $tid = null;

        if ($extT) {
            $tid = [($t_k >> 6) & 3, ($t_k >> 5) & 1];
        }
        if ($extK) {
            $keyidx = $t_k & 0x1F;
        }

        return [$tid, $keyidx];
    }

    /**
     * String representation of descriptor
     *
     * @return string Human-readable descriptor info
     */
    public function __toString(): string
    {
        return sprintf(
            "VpxPayloadDescriptor(S=%d, PID=%d, pic_id=%s)",
            $this->partitionStart,
            $this->partitionId,
            $this->pictureId !== null ? $this->pictureId : 'null'
        );
    }

    /**
     * Sets partition start flag
     *
     * @param int $partitionStart New partition start value
     */
    public function setPartitionStart(int $partitionStart): void
    {
        $this->partitionStart = $partitionStart;
    }

    /**
     * Sets picture ID
     *
     * @param int|null $pictureId New picture ID
     */
    public function setPictureId(?int $pictureId): void
    {
        $this->pictureId = $pictureId;
    }
}

