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

//define('VPX_ENCODER_ABI_VERSION', 36); // Ensure this matches the version in your vpx.h

/**
 * Encoder class for VPX codec.
 *
 * This class provides methods to encode VPX video streams.
 * It uses the Foreign Function Interface (FFI) to interact with the VPX shared library.
 *
 * @deprecated
 */
class Encoder implements SharedLibraryInterface
{

    private FFI $libVpx;

    private string $buffer = "";

    /**
     * Encoder constructor.
     *
     * Initializes the encoder with the provided configuration, context, and codec interface.
     *
     * @param Config $config The codec configuration to be used.
     * @param Context $context The codec context to be used.
     * @param BriefInterface $interface The codec interface to be used.
     * @throws VpxException If the encoder initialization fails.
     */
    public function __construct(private readonly Context $context, Config $config, BriefInterface $interface)
    {
        $this->initiateSharedLibrary();

        if ($this->libVpx->vpx_codec_enc_init_ver(FFI::addr($context->getCtx()), $this->libVpx->{$interface->value}(), FFI::addr($config->getCfg()), 0, VPX_ENCODER_ABI_VERSION) != 0) {
            $err = $this->libVpx->vpx_codec_error(FFI::addr($context->getCtx()));
            throw new VpxException("Failed to initialize encoder: " . $err);
        }
    }

    /**
     * Encode an image frame.
     *
     * @param Image $image The image frame to encode.
     * @param int $timestamp The timestamp for the frame.
     * @param int $flags The encoding flags.
     * @return string The encoded packet.
     * @throws VpxException If the frame encoding or packet retrieval fails.
     */
    public function encode(Image $image, int $timestamp, int $flags): string
    {
        if ($this->libVpx->vpx_codec_encode(FFI::addr($this->context->getCtx()), FFI::addr($image->getImage()), $timestamp, 1, $flags, 1) != 0) {
            $err = $this->libVpx->vpx_codec_error(FFI::addr($this->context->getCtx()));
            throw new VpxException("Failed to encode frame: " . $err);
        }

        $iter = $this->libVpx->new("vpx_codec_iter_t");
        while (true) {
            $pkt = $this->libVpx->vpx_codec_get_cx_data(FFI::addr($this->context->getCtx()), FFI::addr($iter));
            if (!$pkt) {
                break;
            } elseif ($pkt->kind == $this->libVpx->VPX_CODEC_CX_FRAME_PKT) {
                $this->buffer .= FFI::string($pkt->data->frame->buf, $pkt->data->frame->sz);
            } else {
                throw new VpxException("No encoded packet found.");
            }
        }

        return $this->buffer;
    }

    public function setConfig(Context $context, Config $configuration): void
    {
        if ($this->libVpx->vpx_codec_enc_config_set($context->getCtx(), $configuration->getCfg()) != 0) {
            $err = $this->libVpx->vpx_codec_error(FFI::addr($context->getCtx()));
            throw new VpxException("Failed to set encoder: " . $err);
        }
    }

    /**
     * Get the shared library instance.
     *
     * Sets the libVpx property if the global libVpx instance is available.
     *
     * @return void
     */
    public function initiateSharedLibrary(): void
    {
        global $libVpx;

        if ($libVpx instanceof FFI) {
            $this->libVpx = $libVpx;
        }
    }

    /**
     * Destructor for the Encoder class.
     *
     * Cleans up the resources by destroying the codec context.
     */
    public function __destruct()
    {
//        $this->libVpx->vpx_codec_destroy(FFI::addr($this->context->getContext()));
    }
}