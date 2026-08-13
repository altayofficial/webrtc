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
use FFI\CData;
use Webrtc\Mixin\SharedLibraryInterface;
use Webrtc\VPX\Enum\BriefInterface;
use Webrtc\VPX\Exception\VpxException;

/**
 * Configuration class for VPX codec settings.
 *
 * This class provides methods to configure VPX codec settings such as width, height, timebase, and bitrate.
 * It utilizes the Foreign Function Interface (FFI) to interact with the VPX shared library.
 * @deprecated
 */
class Config implements SharedLibraryInterface
{
    private FFI $libVpx;
    private ?CData $cfg;

    /**
     * Configuration constructor.
     *
     * Initializes the configuration with the provided codec interface and sets up default settings.
     *
     * @param BriefInterface $interface The codec interface to be used.
     * @throws VpxException If the encoder interface or default codec config cannot be found.
     */
    public function __construct(BriefInterface $interface)
    {
        $this->initiateSharedLibrary();
        $this->cfg = $this->libVpx->new("vpx_codec_enc_cfg_t");
        $codecInterface = $this->libVpx->{$interface->value}();

        if ($codecInterface === NULL) {
            throw new VpxException("Failed to find encoder interface.");
        }

        if ($this->libVpx->vpx_codec_enc_config_default($codecInterface, FFI::addr($this->cfg), 0)) {
            throw new VpxException("Failed to get default codec config.");
        }

    }
//
//    /**
//     * Set the width of the video.
//     *
//     * @param int $value The width to be set.
//     * @return void
//     */
//    public function setWidth(int $value): void
//    {
//        $this->cfg->g_w = $value;
//    }
//
//    /**
//     * Set the height of the video.
//     *
//     * @param int $value The height to be set.
//     * @return void
//     */
//    public function setHeight(int $value): void
//    {
//        $this->cfg->g_h = $value;
//    }
//
//    /**
//     * Set the timebase for the video.
//     *
//     * @param int $num The numerator of the timebase.
//     * @param int $den The denominator of the timebase.
//     * @return void
//     */
//    public function setTimebase(int $num, int $den): void
//    {
//        $this->cfg->g_timebase->num = $num;
//        $this->cfg->g_timebase->den = $den;
//    }
//
//    /**
//     * Set the bitrate for the video.
//     *
//     * @param int $value The target bitrate to be set.
//     * @return void
//     */
//    public function setBitrate(int $value): void
//    {
//        $this->cfg->rc_target_bitrate = $value;
//    }
//
//    /**
//     * Set the lag in frame for the video.
//     *
//     * @param int $value
//     * @return void
//     */
//    public function setLagInFrames(int $value): void
//    {
//        $this->cfg->g_lag_in_frames = $value;
//    }
//
//    /**
//     * Set the threads for the video.
//     *
//     * @param int $value
//     * @return void
//     */
//    public function setThreads(int $value): void
//    {
//        $this->cfg->g_threads = $value;
//    }
//
//    /**
//     * Set the threads for the video.
//     *
//     * @param bool $status
//     * @return void
//     */
//    public function setResizeAllowed(bool $status): void
//    {
//        $this->cfg->rc_resize_allowed = intval($status);
//    }
//
//    /**
//     * Set the End of usage for the video.
//     *
//     * @param int $value
//     * @return void
//     */
//    public function setEndUsage(int $value): void
//    {
//        $this->cfg->rc_end_usage = $value;
//    }
//
//    /**
//     * Set the Min Quantizer for the video.
//     *
//     * @param int $value
//     * @return void
//     */
//    public function setMinQuantizer(int $value): void
//    {
//        $this->cfg->rc_min_quantizer = $value;
//    }
//
//    /**
//     * Set the Max Quantizer for the video.
//     *
//     * @param int $value
//     * @return void
//     */
//    public function setMaxQuantizer(int $value): void
//    {
//        $this->cfg->rc_max_quantizer = $value;
//    }
//
//    /**
//     * Set the Undershoot Pct for the video.
//     *
//     * @param int $value
//     * @return void
//     */
//    public function setUndershootPct(int $value): void
//    {
//        $this->cfg->rc_undershoot_pct = $value;
//    }
//
//    /**
//     * Set the Overshoot Pct for the video.
//     *
//     * @param int $value
//     * @return void
//     */
//    public function setOvershootPct(int $value): void
//    {
//        $this->cfg->rc_overshoot_pct = $value;
//    }
//
//    /**
//     * Set the set Buffer Initial Size for the video.
//     *
//     * @param int $value
//     * @return void
//     */
//    public function setBufInitialSz(int $value): void
//    {
//        $this->cfg->rc_buf_initial_sz = $value;
//    }
//
//    /**
//     * Set the set Buf Optimal Sz for the video.
//     *
//     * @param int $value
//     * @return void
//     */
//    public function setBufOptimalSz(int $value): void
//    {
//        $this->cfg->rc_buf_optimal_sz = $value;
//    }
//
//    /**
//     * Set the End of usage for the video.
//     *
//     * @param int $value
//     * @return void
//     */
//    public function setBufSz(int $value): void
//    {
//        $this->cfg->rc_buf_sz = $value;
//    }
//
//    /**
//     * Set the Mode for the video.
//     *
//     * @param int $value
//     * @return void
//     */
//    public function setMode(int $value): void
//    {
//        $this->cfg->kf_mode = $value;
//    }
//
//    /**
//     * Set the Max Dist for the video.
//     *
//     * @param int $value
//     * @return void
//     */
//    public function setMaxDist(int $value): void
//    {
//        $this->cfg->kf_max_dist = $value;
//    }

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
     * Get the current configuration.
     *
     * @return FFI\CData|null The current codec configuration.
     */
    public function getCfg(): ?FFI\CData
    {
        return $this->cfg;
    }
}
