<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Util;

use FFI\CData;
use Webrtc\Exception\InvalidArgumentException;

/**
 * Util Class
 *
 * This class provides utility functions for rescaling values between different
 * time bases, similar to the av_rescale functions in FFmpeg's libavutil.
 */
class Util
{
    /**
     * Rescales a 64-bit integer with specified rounding
     *
     * Rescales the value a from a scale with size b to a scale with size c,
     * applying the specified rounding method.
     *
     * The formula is:  a * b / c
     *
     * @param int $a Input value to rescale
     * @param int $b Input scale
     * @param int $c Output scale
     * @param int $rnd Rounding method (one of the AV_ROUND_* constants)
     *
     * @return int The rescaled value
     * @throws InvalidArgumentException When input parameters are invalid
     */
    public static function rescaleRnd(int $a, int $b, int $c, int $rnd): int
    {
        $r = 0;

        // Assertions
        if ($c <= 0 || $b < 0 || !((($rnd & ~AV_ROUND_PASS_MINMAX) <= 5) && (($rnd & ~AV_ROUND_PASS_MINMAX) != 4))) {
            throw new InvalidArgumentException("Invalid parameters for rescaling.");
        }

        if ($rnd & AV_ROUND_PASS_MINMAX) {
            if ($a == INT64_MIN || $a == INT64_MAX) {
                return $a;
            }
            $rnd -= AV_ROUND_PASS_MINMAX;
        }

        if ($a < 0) {
            return -self::rescaleRnd(-max($a, INT64_MIN), $b, $c, $rnd ^ (($rnd >> 1) & 1));
        }

        if ($rnd == AV_ROUND_NEAR_INF) {
            $r = intval($c / 2);
        } else if ($rnd & 1) {
            $r = $c - 1;
        }

        if ($b <= PHP_INT_MAX && $c <= PHP_INT_MAX) {
            if ($a <= PHP_INT_MAX) {
                return intval(($a * $b + $r) / $c);
            } else {
                $ad = intval($a / $c);
                $a2 = intval(($a % $c * $b + $r) / $c);
                if ($ad >= (PHP_INT_MAX >> 1) && $b && $ad > (INT64_MAX - $a2) / $b) {
                    return INT64_MIN;
                }
                return intval($ad * $b + $a2);
            }
        } else {
            $a0 = $a & 0xFFFFFFFF;
            $a1 = $a >> 32;
            $b0 = $b & 0xFFFFFFFF;
            $b1 = $b >> 32;
            $t1 = $a0 * $b1 + $a1 * $b0;
            $t1a = $t1 << 32;
            $a0 = $a0 * $b0 + $t1a;
            $a1 = $a1 * $b1 + ($t1 >> 32) + ($a0 < $t1a);
            $a0 += $r;
            $a1 += $a0 < $r;

            for ($i = 63; $i >= 0; $i--) {
                $a1 = ($a1 << 1) + (($a0 >> $i) & 1);
                $t1 <<= 1;
                if ($c <= $a1) {
                    $a1 -= $c;
                    $t1++;
                }
            }
            if ($t1 > INT64_MAX) {
                return INT64_MIN;
            }
            return $t1;
        }
    }

    /**
     * Rescale a value using rational numbers with specified rounding
     *
     * Rescales the value a from time base bq to time base cq using the specified
     * rounding method.
     *
     * The formula is: a * bq->num * cq->den / (cq->num * bq->den)
     *
     * @param int $a Input value to rescale
     * @param \stdClass $bq Input time base (object with num and den properties)
     * @param CData $cq Output time base (FFI CData structure with num and den properties)
     * @param int $rnd Rounding method (one of the AV_ROUND_* constants)
     *
     * @return int The rescaled value
     */
    public static function rescaleQRnd(int $a, \stdClass $bq, CData $cq, int $rnd): int
    {
        $b = $bq->num * (int)$cq->den;
        $c = $cq->num * (int)$bq->den;
        return self::rescaleRnd($a, $b, $c, $rnd);
    }

    /**
     * Rescale a value using rational numbers with nearest rounding
     *
     * Convenience wrapper around rescaleQRnd that uses AV_ROUND_NEAR_INF
     * as the rounding method.
     *
     * @param int $a Input value to rescale
     * @param \stdClass $bq Input time base (object with num and den properties)
     * @param CData $cq Output time base (FFI CData structure with num and den properties)
     *
     * @return int The rescaled value
     */
    public static function rescaleQ(int $a, \stdClass $bq, CData $cq): int
    {
        return self::rescaleQRnd($a, $bq, $cq, AV_ROUND_NEAR_INF);
    }
}