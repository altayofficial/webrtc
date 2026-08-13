<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Srtp\Trait;

use Webrtc\Srtp\Exception\SrtpValidateException;

/**
 * Trait SrtpErrStatusAssertion
 *
 * Provides a method to assert the SRTP error status.
 */
trait SrtpErrStatusAssertion
{
    /**
     * Asserts that the SRTP error status is okay.
     *
     * @param int $errorStatus The SRTP error status to check.
     * @throws SrtpValidateException if the error status is not okay.
     */
    private function assertSrtp(int $errorStatus): void
    {
        if ($errorStatus !== $this->libsrtp->srtp_err_status_ok) {
            throw new SrtpValidateException($errorStatus);
        }
    }
}