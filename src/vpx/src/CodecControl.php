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

/**
 * CodecControl provides an interface to control various VPX codec parameters.
 *
 * This class implements the SharedLibraryInterface and provides methods to adjust
 * encoder settings such as noise sensitivity, static threshold, CPU usage, and
 * token partitions for VP8/VP9 video encoding.
 *
 * @deprecated
 */
class CodecControl implements SharedLibraryInterface
{
    /** @var FFI Reference to the loaded libvpx shared library */
    private FFI $libVpx;

    /**
     * Initializes a new CodecControl instance with the given encoding context.
     *
     * @param Context $ctx The VPX encoding context to control
     */
    public function __construct(private readonly Context $ctx)
    {
        $this->initiateSharedLibrary();
    }

    /**
     * Sets the noise sensitivity level for the encoder.
     *
     * Higher values will make the encoder more sensitive to noise, which can
     * improve quality in noisy sources but may increase bitrate.
     *
     * @param int $value Sensitivity level (typically 0-4)
     * @return void
     */
    public function setNoiseSensitivity(int $value): void
    {
        $this->libVpx->vpx_codec_control_(FFI::addr($this->ctx->getCtx()), $this->libVpx->VP8E_SET_NOISE_SENSITIVITY, $value);
    }

    /**
     * Sets the static threshold for keyframe generation.
     *
     * This controls how much change in the video is required to trigger a new
     * keyframe. Lower values make the encoder more sensitive to scene changes.
     *
     * @param int $value Threshold value (implementation specific)
     * @return void
     */
    public function setStaticThreshold(int $value): void
    {
        $this->libVpx->vpx_codec_control_(FFI::addr($this->ctx->getCtx()), $this->libVpx->VP8E_SET_STATIC_THRESHOLD, $value);
    }

    /**
     * Sets the CPU usage level for the encoder.
     *
     * Higher values will make encoding faster but may reduce quality.
     * Typical range is 0-16 where 0 is highest quality/slowest.
     *
     * @param int $value CPU usage level (typically 0-16)
     * @return void
     */
    public function setCpuUsed(int $value): void
    {
        $this->libVpx->vpx_codec_control_(FFI::addr($this->ctx->getCtx()), $this->libVpx->VP8E_SET_CPUUSED, $value);
    }

    /**
     * Sets the number of token partitions to use during encoding.
     *
     * This controls parallelization of entropy encoding. More partitions can
     * improve multi-core performance but may reduce compression efficiency.
     *
     * @param int $value Number of partitions (typically 1-3)
     * @return void
     */
    public function setTokenPartitions(int $value): void
    {
        $this->libVpx->vpx_codec_control_(FFI::addr($this->ctx->getCtx()), $this->libVpx->VP8E_SET_TOKEN_PARTITIONS, $value);
    }

    /**
     * Initializes the shared library reference from the global scope.
     *
     * This implements the SharedLibraryInterface requirement to connect the
     * class instance with the loaded FFI library instance.
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
}