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

use FFI;
use FFI\CData;
use Webrtc\AVCodec\AVCodec;
use Webrtc\AVCodec\Data\Packet;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\AVCodec\Frame\VideoFrame;
use Webrtc\AVCodec\SWScale;
use Webrtc\Codecs\CodecUtility;
use Webrtc\Codecs\Encoder;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SharedLibraryInterface;
use Webrtc\VPX\Exception\VpxException;
use Webrtc\VPX\Vpx;

/**
 * VP8 Video Encoder Class
 *
 * Implements real-time VP8 video encoding optimized for WebRTC applications.
 * Provides efficient frame encoding with adaptive bitrate control and
 * packetization for RTP transport.
 *
 * @package Webrtc\Codecs\Video\Vp8
 */
class Vp8Encoder extends Encoder implements SharedLibraryInterface
{
    /**
     * @var int VIDEO_CLOCK_RATE Standard video clock rate (90kHz)
     */
    private const VIDEO_CLOCK_RATE = 90000;

    /**
     * @var int MAX_FRAME_RATE Maximum supported frame rate (30fps)
     */
    private const MAX_FRAME_RATE = 30;

    /**
     * @var int $pictureId Current picture identifier (15-bit)
     */
    private int $pictureId;

    /**
     * @var FFI $libVpx FFI instance for libvpx
     */
    private FFI $libVpx;

    /**
     * @var CData|null $config Encoder configuration
     */
    private ?CData $config;

    /**
     * @var CData|null $cx Codec interface
     */
    private ?CData $cx;

    /**
     * @var int $timestampIncrement Timestamp increment per frame
     */
    private int $timestampIncrement = self::VIDEO_CLOCK_RATE / self::MAX_FRAME_RATE;

    /**
     * @var CData|null $codec Encoder instance
     */
    private ?CData $codec = null;

    /**
     * @var bool $updateConfigNeeded Flag for config updates
     */
    private bool $updateConfigNeeded = false;

    /**
     * @var int $bitrate Current target bitrate (500kbps default)
     */
    protected int $bitrate = 500000;

    /**
     * @var CData|null $image Temporary image buffer
     */
    private ?CData $image;

    /**
     * @var string $buffer Output buffer for encoded data
     */
    private string $buffer;

    /**
     * Constructor
     *
     * Initializes required libraries and encoder configuration:
     * - libvpx (VP8 codec)
     * - AVCodec (FFmpeg infrastructure)
     * - SWScale (format conversion)
     * Sets up default encoder parameters
     * @throws VpxException
     * @throws AvCodecException
     */
    public function __construct()
    {
        Vpx::init();
        AVCodec::init();
        SWScale::init();
        $this->initiateSharedLibrary();
        $this->cx = $this->libVpx->vpx_codec_vp8_cx();
        $this->config = $this->libVpx->new("vpx_codec_enc_cfg_t");
        $this->buffer = str_repeat("\0", 8000);

        // Configure encoder
        $this->vpxAssert($this->libVpx->vpx_codec_enc_config_default($this->cx, FFI::addr($this->config), 0));
        $this->pictureId = rand(0, (1 << 15) - 1);
    }

    /**
     * Encodes a video frame to VP8 format
     *
     * @param FrameInterface|VideoFrame $frame Input video frame
     * @param bool $useKeyframe Force keyframe generation
     * @return array [payloads, timestamp] Encoded packets and presentation timestamp
     */
    public function encode(FrameInterface|VideoFrame $frame, bool $useKeyframe = false): array
    {
        if ($frame->getVideoFormat()->getName() !== "yuv420p") {
            $frame = $frame->reformat(format: "yuv420p");
        }

        if ($this->codec && ($frame->getVideoFormat()->getWidth() !== $this->config->g_w || $frame->getVideoFormat()->getHeight() !== $this->config->g_h)) {
            $this->libVpx->vpx_codec_destroy(FFI::addr($this->codec));
            $this->codec = null;
        }

        if (!$this->codec) {
            $this->initializeEncoder($frame);
            $this->initializeCodecControl();
            $this->initializeImage($frame);
        } elseif ($this->updateConfigNeeded) {
            $this->updateConfig();
            $this->vpxAssert($this->libVpx->vpx_codec_enc_config_set(FFI::addr($this->codec), FFI::addr($this->config)));
        }

        for ($i = 0; $i < 3; $i++) {
            $this->image->planes[$i] = $frame->getFrame()->data[$i];
            $this->image->stride[$i] = $frame->getFrame()->linesize[$i];
        }

        $flags = 0;
        if ($useKeyframe) {
            $flags |= (1 << 0);
        }

        // Encode the frame
        $this->vpxAssert(
            $this->libVpx->vpx_codec_encode(
                FFI::addr($this->codec),
                FFI::addr($this->image),
                $frame->getPts(),
                $this->timestampIncrement,
                $flags,
                VPX_DL_REALTIME
            )
        );

        $length = $this->getEncodedData();

        // Packetize
        $payloads = $this->packetize(substr($this->buffer, 0, $length), $this->pictureId);
        $this->pictureId = ($this->pictureId + 1) % (1 << 15);
        return [$payloads, $this->getTimeBase($frame)];
    }

    /**
     * Packages encoded data for RTP transport
     *
     * @param Packet $packet Encoded video packet
     * @return array [payloads, timestamp] Packets and converted timestamp
     */
    public function pack(Packet $packet): array
    {
        $payloads = $this->packetize($packet->getData(), $this->pictureId);
        $timestamp = $this->convertTimebase($packet->getPts(), (array)$packet->getTimeBase(), [1, self::VIDEO_CLOCK_RATE]);
        $this->pictureId = ($this->pictureId + 1) % (1 << 15);
        return [$payloads, $timestamp];
    }

    /**
     * Packetizes encoded data with VP8 payload descriptors
     *
     * @param string $buffer Encoded frame data
     * @param int $pictureId Picture identifier
     * @return array Array of RTP payload packets
     */
    public function packetize(string $buffer, int $pictureId): array
    {
        $payloads = [];
        $descr = new Vp8PayloadDescriptor(1, 0);
        $descr->setPictureId($pictureId);

        $length = strlen($buffer);
        $pos = 0;

        while ($pos < $length) {
            $descrBytes = $descr->encode();
            $size = min($length - $pos, PACKET_MAX - strlen($descrBytes));
            $payloads[] = $descrBytes . substr($buffer, $pos, $size);
            $descr->setPartitionStart(0); // Update the descriptor's partition start flag
            $pos += $size;
        }

        return $payloads;
    }

    /**
     * Converts frame timestamp to 90kHz clock base
     *
     * @param VideoFrame $frame Input video frame
     * @return int Timestamp in 90kHz units
     */
    public function getTimeBase(VideoFrame $frame): int
    {
        return $this->convertTimebase($frame->getPts(), (array)$frame->getTimeBase(), [1, self::VIDEO_CLOCK_RATE]);
    }

    /**
     * Initializes shared library interface
     *
     * @throws InvalidArgumentException If library not available
     */
    public function initiateSharedLibrary(): void
    {
        global $libVpx;
        if ($libVpx instanceof FFI) {
            $this->libVpx = $libVpx;
        } else {
            throw new InvalidArgumentException("Shared library not initialized.");
        }
    }

    /**
     * Validates libvpx operation results
     *
     * @param int $err Error code
     * @throws RuntimeException If operation failed
     */
    function vpxAssert(int $err): void
    {
        if ($err !== $this->libVpx->VPX_CODEC_OK) {
            $reason = $this->libVpx->vpx_codec_err_to_string($err);
            throw new RuntimeException("libvpx error: " . $reason);
        }
    }

    /**
     * Initializes VP8 encoder instance
     *
     * @param VideoFrame $frame Reference frame for configuration
     */
    private function initializeEncoder(VideoFrame $frame): void
    {
        $this->codec = $this->libVpx->new("vpx_codec_ctx_t");

        $this->config->g_w = $frame->getVideoFormat()->getWidth();
        $this->config->g_h = $frame->getVideoFormat()->getHeight();
        $this->config->rc_target_bitrate = 500;
        $this->config->g_timebase->num = 1;
        $this->config->g_timebase->den = self::VIDEO_CLOCK_RATE;
        $this->config->g_lag_in_frames = 0;
        $this->config->g_threads = $this->calcCpuCore($frame);
        $this->config->rc_resize_allowed = 0;
        $this->config->rc_end_usage = $this->libVpx->VPX_CBR;
        $this->config->rc_min_quantizer = 2;
        $this->config->rc_max_quantizer = 56;
        $this->config->rc_undershoot_pct = 100;
        $this->config->rc_overshoot_pct = 15;
        $this->config->rc_buf_initial_sz = 500;
        $this->config->rc_buf_optimal_sz = 600;
        $this->config->rc_buf_sz = 1000;
        $this->config->kf_mode = $this->libVpx->VPX_KF_AUTO;
        $this->config->kf_max_dist = 3000;
        $this->updateConfig();

        // Initialize encoder
        $this->vpxAssert(
            $this->libVpx->vpx_codec_enc_init_ver(
                FFI::addr($this->codec),
                $this->cx,
                FFI::addr($this->config),
                0, VPX_ENCODER_ABI_VERSION
            )
        );
    }

    /**
     * Calculates optimal thread count for encoding
     *
     * @param VideoFrame $frame Input video frame
     * @return int Number of threads to use
     */
    private function calcCpuCore(VideoFrame $frame): int
    {
        $totalPixel = $frame->getVideoFormat()->getWidth() * $frame->getVideoFormat()->getHeight();
        $cpuCores = CodecUtility::getNumberOfCPUCores();

        return self::numberOfThreads($totalPixel, $cpuCores);
    }

    /**
     * Determines thread count based on resolution and CPU cores
     *
     * @param int $pixels Frame resolution (width × height)
     * @param int $cpus Available CPU cores
     * @return int Recommended thread count
     */
    public static function numberOfThreads(int $pixels, int $cpus): int
    {
        if ($pixels >= 1920 * 1080 && $cpus > 8) {
            return 8;
        } elseif ($pixels > 1280 * 960 && $cpus >= 6) {
            return 3;
        } elseif ($pixels > 640 * 480 && $cpus >= 3) {
            return 2;
        } else {
            return 1;
        }
    }

    /**
     * Updates encoder configuration
     */
    private function updateConfig(): void
    {
        $this->config->rc_target_bitrate = $this->bitrate / 1000;
        $this->updateConfigNeeded = false;
    }

    /**
     * Configures codec control parameters
     */
    private function initializeCodecControl(): void
    {
        $this->libVpx->vpx_codec_control_(FFI::addr($this->codec), $this->libVpx->VP8E_SET_NOISE_SENSITIVITY, 4);
        $this->libVpx->vpx_codec_control_(FFI::addr($this->codec), $this->libVpx->VP8E_SET_STATIC_THRESHOLD, 1);
        $this->libVpx->vpx_codec_control_(FFI::addr($this->codec), $this->libVpx->VP8E_SET_CPUUSED, -6);
        $this->libVpx->vpx_codec_control_(FFI::addr($this->codec), $this->libVpx->VP8E_SET_TOKEN_PARTITIONS, 0);
    }

    /**
     * Allocates image buffer for encoding
     *
     * @param VideoFrame $frame Reference frame for dimensions
     */
    private function initializeImage(VideoFrame $frame): void
    {
        $this->image = $this->libVpx->new("vpx_image_t");

        // Allocate image
        $image_ptr = $this->libVpx->vpx_img_alloc(FFI::addr($this->image), $this->libVpx->VPX_IMG_FMT_I420, $frame->getVideoFormat()->getWidth(), $frame->getVideoFormat()->getHeight(), 1);
        if ($image_ptr === NULL) {
            throw new InvalidArgumentException("Failed to allocate image.\n");
        }
    }

    /**
     * Sets current picture identifier
     *
     * @param int $pictureId New picture ID (15-bit)
     */
    public function setPictureId(int $pictureId): void
    {
        $this->pictureId = $pictureId;
    }

    /**
     * Retrieves encoded data from codec
     *
     * @return int Length of encoded data
     */
    private function getEncodedData(): int
    {
        $length = 0;
        $iter = $this->libVpx->new("vpx_codec_iter_t");
        while ($pkt = $this->libVpx->vpx_codec_get_cx_data(FFI::addr($this->codec), FFI::addr($iter))) {
            if ($pkt->kind === $this->libVpx->VPX_CODEC_CX_FRAME_PKT) {

                if ($length + $pkt->data->frame->sz > strlen($this->buffer)) {
                    $newBuffer = str_repeat("\0", $length + $pkt->data->frame->sz);
                    $newBuffer = substr_replace($newBuffer, $this->buffer, 0, $length);
                    $this->buffer = $newBuffer;
                }

                $frameData = FFI::string($pkt->data->frame->buf, $pkt->data->frame->sz);
                $this->buffer = substr_replace($this->buffer, $frameData, $length, $pkt->data->frame->sz);
                $length += $pkt->data->frame->sz;
            }
        }

        return $length;
    }

    /**
     * Destructor - cleans up encoder resources
     */
    public function __destruct()
    {
        if ($this->codec) {
            $this->libVpx->vpx_codec_destroy(FFI::addr($this->codec));
        }
    }

    /**
     * Updates target bitrate
     *
     * @param int $bitrate New bitrate in bits per second
     */
    public function setBitrate(int $bitrate): void
    {
        parent::setBitrate($bitrate);
        $this->updateConfigNeeded = true;
    }
}
