<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\VPX;

use FFI;
use Webrtc\Mixin\SharedLibraryInterface;
use Webrtc\VPX\Enum\BriefInterface;
use Webrtc\VPX\Exception\VpxException;

/**
 * Decoder provides VP8/VP9 video decoding functionality using libvpx.
 *
 * This class implements the SharedLibraryInterface and handles the initialization,
 * decoding, and cleanup of VPX decoder instances. It supports frame-by-frame
 * decoding through a generator pattern.
 *
 * @deprecated
 */
class Decoder implements SharedLibraryInterface
{
    /** @var FFI Reference to the loaded libvpx shared library */
    private FFI $libVpx;

    /** @var Context The VPX decoding context */
    private Context $context;

    /** @var BriefInterface The codec interface specification */
    private BriefInterface $interface;

    /**
     * Initializes a new Decoder instance.
     *
     * @param Context $context The VPX decoding context
     * @param BriefInterface $interface The codec interface to use (VP8/VP9)
     * @throws VpxException If decoder initialization fails
     */
    public function __construct(Context $context, BriefInterface $interface)
    {
        $this->initiateSharedLibrary();
        $this->context = $context;
        $this->interface = $interface;

        $this->initializeDecoder();
    }

    /**
     * Initializes the VPX decoder with the specified interface.
     *
     * @throws VpxException If decoder initialization fails
     */
    private function initializeDecoder(): void
    {
        if ($this->libVpx->vpx_codec_dec_init_ver(
                FFI::addr($this->context->getCtx()),
                $this->libVpx->{$this->interface->value}(),
                null, 0, VPX_DECODER_ABI_VERSION
            ) !== 0) {
            throw new VpxException("Failed to initialize decoder: " .
                $this->libVpx->vpx_codec_error(FFI::addr($this->context->getCtx())));
        }
    }

    /**
     * Decodes a frame of video data and yields decoded frames.
     *
     * This method is a generator that yields decoded frames one by one. It handles
     * memory allocation and cleanup automatically.
     *
     * @param string $data The encoded video data to decode
     * @return \Generator Yields decoded frames
     * @throws VpxException If decoding fails or empty data is provided
     *
     * @example
     * foreach ($decoder->decode($videoData) as $frame) {
     *     // Process each frame
     * }
     */
    public function decode(string $data): \Generator
    {
        if (empty($data)) {
            throw new VpxException("Data buffer cannot be empty.");
        }

        $dataLength = strlen($data);
        $data_c = $this->libVpx->new("uint8_t[$dataLength]", false);
        FFI::memcpy($data_c, $data, $dataLength);

        $ctx = FFI::addr($this->context->getCtx());
        if ($this->libVpx->vpx_codec_decode($ctx, $data_c, $dataLength, NULL, VPX_DL_REALTIME) != 0) {
            $err = $this->libVpx->vpx_codec_error($ctx);
            unset($data_c); // Free memory
            throw new VpxException("Failed to decode frame: " . $err);
        }

        $iter = $this->libVpx->new("vpx_codec_iter_t");
        while ($img = $this->libVpx->vpx_codec_get_frame($ctx, FFI::addr($iter))) {
            yield $img;
        }

        unset($data_c, $iter); // Explicitly free memory
    }

//    public function decode(string $data): \Generator
//    {
//        $dataLength = strlen($data);
//        if ($dataLength === 0) {
//            throw new VpxException("Empty data provided for decoding.");
//        }
//
//        $dataC = $this->libVpx->new("uint8_t[$dataLength]", false);
//        FFI::memcpy($dataC, $data, $dataLength);
//
//        if ($this->libVpx->vpx_codec_decode(
//                FFI::addr($this->context->getCtx()),
//                $dataC,
//                $dataLength,
//                null,
//                VPX_DL_REALTIME
//            ) !== 0) {
//            throw new VpxException("Failed to decode frame: " .
//                $this->libVpx->vpx_codec_error(FFI::addr($this->context->getCtx())));
//        }
//
//        unset($dataC); // Ensure memory is freed
//
//        $iter = $this->libVpx->new("vpx_codec_iter_t");
//        while (($img = $this->libVpx->vpx_codec_get_frame(FFI::addr($this->context->getCtx()), FFI::addr($iter)))) {
//            yield $img;
//        }
//    }

    /**
     * Destructor - ensures proper cleanup of decoder resources.
     */
    public function __destruct()
    {
        if (isset($this->libVpx)) {
            $this->libVpx->vpx_codec_destroy(FFI::addr($this->context->getCtx()));
        }
    }

    /**
     * Initializes the shared library reference from the global scope.
     *
     * Implements the SharedLibraryInterface requirement to connect the
     * class instance with the loaded FFI library instance.
     */
    public function initiateSharedLibrary(): void
    {
        global $libVpx;

        if ($libVpx instanceof FFI) {
            $this->libVpx = $libVpx;
        }
    }
}