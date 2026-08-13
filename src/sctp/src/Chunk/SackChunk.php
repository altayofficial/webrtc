<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP\Chunk;

/**
 * Selective Acknowledgment chunk.
 */
class SackChunk extends Chunk
{
    /** @var int Chunk type identifier. */
    protected int $type = 3;

    /** @var int Cumulative TSN. */
    protected int $cumulativeTsn;

    /** @var int Advertised receiver window. */
    protected int $advertisedRwnd;

    /** @var array List of gaps. */
    protected array $gaps = [];

    /** @var array List of duplicate TSNs. */
    protected array $duplicates = [];

    /**
     * SackChunk constructor.
     *
     * @param int $flags Chunk flags.
     * @param string|null $body Chunk body.
     */
    public function __construct(int $flags = 0, ?string $body = null)
    {
        parent::__construct($flags);
        if ($body) {
            $unpacked = unpack("NcumulativeTsn/NadvertisedRwnd/ngaps/nduplicates", substr($body, 0, 12));
            $this->cumulativeTsn = $unpacked["cumulativeTsn"];
            $this->advertisedRwnd = $unpacked["advertisedRwnd"];
            $pos = 12;
            for ($i = 0; $i < $unpacked["gaps"]; $i++) {
                $this->gaps[] = array_values(unpack("nstart/nend", substr($body, $pos, 4)));
                $pos += 4;
            }
            for ($i = 0; $i < $unpacked["duplicates"]; $i++) {
                $this->duplicates[] = unpack("N", substr($body, $pos, 4))[1];
                $pos += 4;
            }
        } else {
            $this->cumulativeTsn = 0;
            $this->advertisedRwnd = 0;
        }
    }

    /**
     * Encodes the chunk into a binary string.
     *
     * @return string Binary representation of the chunk.
     */
    public function encode(): string
    {
        $length = 16 + 4 * (count($this->gaps) + count($this->duplicates));
        $data = pack("CCnNNnn", $this->type, $this->flags, $length, $this->cumulativeTsn, $this->advertisedRwnd, count($this->gaps), count($this->duplicates));
        foreach ($this->gaps as [$start, $end]) {
            $data .= pack("nn", $start, $end);
        }
        foreach ($this->duplicates as $tsn) {
            $data .= pack("N", $tsn);
        }
        return $data;
    }

    /**
     * Returns a string representation of the chunk.
     *
     * @return string String representation.
     */
    public function __toString(): string
    {
        return sprintf(
            "SackChunk(flags=%d, advertisedRwnd=%d, cumulativeTsn=%d, gaps=%s)",
            $this->flags, $this->advertisedRwnd, $this->cumulativeTsn, json_encode($this->gaps)
        );
    }

    /**
     * @return int
     */
    public function getCumulativeTsn(): int
    {
        return $this->cumulativeTsn;
    }

    /**
     * @param int $cumulativeTsn
     * @return void
     */
    public function setCumulativeTsn(int $cumulativeTsn): void
    {
        $this->cumulativeTsn = $cumulativeTsn;
    }

    /**
     * @return array
     */
    public function getGaps(): array
    {
        return $this->gaps;
    }

    /**
     * @param array $gaps
     * @return void
     */
    public function setGaps(array $gaps): void
    {
        $this->gaps = $gaps;
    }

    /**
     * @return int
     */
    public function getAdvertisedRwnd(): int
    {
        return $this->advertisedRwnd;
    }

    /**
     * @param int $advertisedRwnd
     * @return void
     */
    public function setAdvertisedRwnd(int $advertisedRwnd): void
    {
        $this->advertisedRwnd = $advertisedRwnd;
    }

    /**
     * @return array
     */
    public function getDuplicates(): array
    {
        return $this->duplicates;
    }

    /**
     * @param array $duplicates
     * @return void
     */
    public function setDuplicates(array $duplicates): void
    {
        $this->duplicates = $duplicates;
    }
}

