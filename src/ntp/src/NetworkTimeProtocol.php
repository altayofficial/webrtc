<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\NTP;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Class NetworkTimeProtocol
 *
 * This class provides functionality to work with NTP (Network Time Protocol) timestamps.
 * NTP timestamps are based on a custom epoch (January 1, 1900, 00:00:00 UTC) and are represented
 * as 64-bit fixed-point numbers, where the high 32 bits represent seconds and the low 32 bits
 * represent fractional seconds. This class uses GMP for handling large numbers.
 */
class NetworkTimeProtocol
{
    /** The NTP epoch (January 1, 1900, 00:00:00 UTC) as a Unix timestamp.
     * @var int */
    private const NTP_EPOCH = -2208988800; // strtotime('1900-01-01 00:00:00 UTC')

    /**
     * Get the current datetime in UTC.
     *
     * @return DateTimeImmutable The current datetime in UTC.
     * @throws DateMalformedStringException
     */
    public static function currentDatetime(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Get the current time in milliseconds since the NTP epoch.
     *
     * @return int The number of milliseconds since the NTP epoch.
     */
    public static function currentMs(): int
    {
        return (time() - self::NTP_EPOCH) * 1000;
    }

    /**
     * Get the current NTP time as a 64-bit fixed-point number.
     *
     * @return string The current NTP timestamp as a string.
     * @throws DateMalformedStringException
     */
    public static function currentNtpTime(): string
    {
        return self::fromDatetime(self::currentDatetime());
    }

    /**
     * Convert an NTP timestamp to a DateTimeImmutable object.
     *
     * @param string $ntp The NTP timestamp as a string (can be larger than 64 bits).
     * @return DateTimeImmutable The corresponding DateTime object in UTC.
     * @throws DateMalformedStringException
     */
    public static function toDatetime(string $ntp): DateTimeImmutable
    {
        $ntp = gmp_init($ntp);

        // Extract the high 32 bits (seconds) and low 32 bits (fractional seconds)
        $high = gmp_div_q($ntp, gmp_pow(2, 32)); // Seconds
        $low = gmp_mod($ntp, gmp_pow(2, 32));   // Fractional seconds

        $microseconds = gmp_intval(gmp_div_q(gmp_mul($low, 1000000), gmp_pow(2, 32)));
        $timestamp = self::NTP_EPOCH + gmp_intval($high);
        $datetime = new DateTimeImmutable('@' . $timestamp, new DateTimeZone('UTC'));

        return $datetime->modify("+$microseconds microseconds");
    }

    /**
     * Convert a DateTimeImmutable object to an NTP timestamp.
     *
     * @param DateTimeImmutable $dt The DateTime object to convert.
     * @return string The NTP timestamp as a string.
     */
    public static function fromDatetime(DateTimeImmutable $dt): string
    {
        $delta = $dt->getTimestamp() - self::NTP_EPOCH;
        $microseconds = (int)$dt->format('u'); // Microseconds

        // Calculate the high and low parts
        $high = gmp_init($delta); // Seconds since NTP epoch
        $low = gmp_init((int)(($microseconds * (1 << 32)) / 1000000)); // Fractional seconds

        // Combine high and low parts into a single GMP number
        $ntp = gmp_add(gmp_mul($high, gmp_pow(2, 32)), $low);

        return gmp_strval($ntp);
    }
}
