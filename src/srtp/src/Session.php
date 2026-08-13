<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Srtp;

use FFI;
use FFI\CData;
use Webrtc\Mixin\SharedLibraryInterface;
use Webrtc\Srtp\Exception\SrtpException;
use Webrtc\Srtp\Exception\SrtpValidateException;
use Webrtc\Srtp\Trait\SrtpErrStatusAssertion;

/**
 * Class Session
 *
 * This class provides methods to manage SRTP sessions, including adding and removing streams,
 * and protecting and unprotected RTP and RTCP packets.
 */
class Session implements SharedLibraryInterface
{
    use SrtpErrStatusAssertion;

    /**
     * @var int
     */
    private const SRTP_MAX_TRAILER_LEN = 16 + 128;
    private FFI $libsrtp;
    private CData $session;
    private CData $cdata;
    private CData $buffer;

    /**
     * Session constructor.
     *
     * @param Policy|null $policy The policy to apply to this session.
     * @throws SrtpValidateException if SRTP session creation fails.
     */
    public function __construct(?Policy $policy = null)
    {
        $this->initiateSharedLibrary();
        $this->session = $this->libsrtp->new('srtp_t');
        $this->buffer = $this->libsrtp->new('char[1500]');
        $policyAddr = $policy ? FFI::addr($policy->getPolicy()) : null;
        $this->srtpCreate($policyAddr);
    }

    /**
     * Adds a stream to the SRTP session.
     *
     * @param Policy $policy The policy to apply to the new stream.
     * @throws SrtpValidateException if adding the stream fails.
     */
    public function addStream(Policy $policy): void
    {
        $this->assertSrtp($this->libsrtp->srtp_add_stream($this->session, FFI::addr($policy->getPolicy())));
    }

    /**
     * Removes a stream from the SRTP session.
     *
     * @param int|string $ssrc The SSRC of the stream to remove.
     * @throws SrtpValidateException if removing the stream fails.
     */
    public function removeStream(int|string $ssrc): void
    {
        $this->assertSrtp($this->libsrtp->srtp_remove_stream($this->session, $this->htonl($ssrc)));
    }

    /**
     * Protects an RTP packet.
     *
     * @param string $packet The RTP packet to protect.
     * @return string The protected RTP packet.
     * @throws SrtpException if protecting the packet fails.
     */
    public function protect(string $packet): string
    {
        return $this->process($packet, $this->libsrtp->srtp_protect, self::SRTP_MAX_TRAILER_LEN);
    }

    /**
     * Protects an RTCP packet.
     *
     * @param string $packet The RTCP packet to protect.
     * @return string The protected RTCP packet.
     * @throws SrtpException if protecting the packet fails.
     */
    public function protectRtcp(string $packet): string
    {
        return $this->process($packet, $this->libsrtp->srtp_protect_rtcp, self::SRTP_MAX_TRAILER_LEN);
    }

    /**
     * Unprotect an RTP packet.
     *
     * @param string $packet The RTP packet to unprotect.
     * @return string The unprotected RTP packet.
     * @throws SrtpException if unprotected, the packet fails.
     */
    public function unprotect(string $packet): string
    {
        return $this->process($packet, $this->libsrtp->srtp_unprotect);
    }

    /**
     * Unprotect an RTCP packet.
     *
     * @param string $packet The RTCP packet to unprotect.
     * @return string The unprotected RTCP packet.
     * @throws SrtpException if unprotected, the packet fails.
     */
    public function unprotectRtcp(string $packet): string
    {
        return $this->process($packet, $this->libsrtp->srtp_unprotect_rtcp);
    }

    /**
     * Processes a packet with the given SRTP function.
     *
     * @param string $data The packet data to process.
     * @param callable $func The SRTP function to apply to the packet.
     * @param int $trailer The trailer length to consider.
     * @return string The processed packet data.
     * @throws SrtpException if processing the packet fails.
     */
    private function process(string $data, callable $func, int $trailer = 0): string
    {
        $dataLength = strlen($data);
        if ($dataLength > (1500 - $trailer)) {
            throw new SrtpException("Packet is too long.");
        }
        $lenP = $this->libsrtp->new('int[1]');
        $lenP[0] = $dataLength;
        FFI::memcpy($this->buffer, $data, $dataLength);
        $this->assertSrtp($func($this->session, $this->buffer, $lenP));
        return FFI::string($this->buffer, $lenP[0]);
    }

    /**
     * Converts a host order long integer to network order.
     *
     * @param string $hostlong The host order long integer.
     * @return string The network orders long integer.
     */
    private function htonl(string $hostlong): string
    {
        return unpack('N', pack('L', $hostlong))[1];
    }

    /**
     * Registers a shutdown function to deallocate the SRTP session.
     */
    public function __destruct()
    {
        register_shutdown_function(function (): void {
            $this->libsrtp->srtp_dealloc($this->session);
        });
    }

    /**
     * handle for session
     *
     * @param CData|null $policyAddr
     * @return void
     * @throws SrtpValidateException
     */
    private function srtpCreate(?CData $policyAddr): void
    {
        $this->assertSrtp($this->libsrtp->srtp_create(FFI::addr($this->session), $policyAddr));
    }

    /**
     * @return void
     */
    public function initiateSharedLibrary(): void
    {
        global $libsrtp;

        if ($libsrtp instanceof FFI) {
            $this->libsrtp = $libsrtp;
        }
    }
}