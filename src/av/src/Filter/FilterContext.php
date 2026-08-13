<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Filter;

use FFI;
use FFI\CData;
use Webrtc\AVCodec\Context\Dictionary;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\Frame\AudioFrame;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\AVCodec\Frame\VideoFrame;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * Class FilterContext
 *
 * Represents a filter context in a multimedia processing graph, allowing linking,
 * initialization, and frame management. Interacts with the AVFilter library via FFI.
 */
class FilterContext implements SharedLibraryInterface
{
    /**
     * @var CData|null Pointer to the filter context structure.
     */
    private ?CData $ctx;
    private FFI $libAVFilter;

    /**
     * FilterContext constructor.
     *
     * This constructor is intentionally private and restricted to internal calls.
     *
     * @param Graph $graph
     * @param Filter $filter
     * @param string|null $name
     * @param CData|null $context
     */
    public function __construct(private readonly Graph $graph, private readonly Filter $filter, ?string $name = null, ?CData $context = null)
    {
        $this->initiateSharedLibrary();

        $this->ctx = $context ?? $this->libAVFilter->avfilter_graph_alloc_filter($graph->getGraph(), $filter->getFilter(), $name);

        if (!$this->ctx) {
            throw new RuntimeException("Could not allocate AVFilterContext.");
        }
    }

    /**
     * Initiate avfilter using string
     *
     * @param ...$args
     * @return void
     */
    public function setup(...$args): void
    {
        if (count($args) > 0) {
            $ret = $this->libAVFilter->avfilter_init_str($this->ctx, ...$args);

            if ($ret < 0) {
                throw new RuntimeException("Cannot initiate filter context.");
            }
        }
    }

    /**
     *  Initiate avfilter using Dictionary object
     *
     * @param array $opts
     * @return void
     */
    public function setupWithDictionary(array $opts): void
    {
        $dic = new Dictionary();
        $dic->update($opts);

        $castedDic = $this->libAVFilter->cast("AVDictionary*", $dic());

        $ret = $this->libAVFilter->avfilter_init_dict($this->ctx, FFI::addr($castedDic));

        if ($ret < 0) {
            throw new RuntimeException("Cannot initiate filter context.");
        }
    }

    /**
     * Get the name of the filter context.
     *
     * @return string|null The name of the filter context, or null if unavailable.
     */
    public function getName(): ?string
    {
        return FFI::string($this->ctx->name) ?? null;
    }

    /**
     * Link the current filter context to another.
     *
     * @param FilterContext $dest
     * @param int $srcPad
     * @param int $destPad
     */
    public function linkTo(FilterContext $dest, int $srcPad = 0, int $destPad = 0): void
    {
        $ret = $this->libAVFilter->avfilter_link($this->ctx, $srcPad, $dest->getCtx(), $destPad);

        if ($ret < 0) {
            throw new RuntimeException("Cannot initiate filter context.");
        }
    }

    /**
     * Push a frame to the filter context.
     *
     * @param ?FrameInterface $frame The frame to push, or null for end-of-stream.
     */
    public function push(?FrameInterface $frame): void
    {
        $frame = $frame ? $this->libAVFilter->cast("AVFrame*", $frame->getFrame()) : null;
        $ret = $this->libAVFilter->av_buffersrc_write_frame($this->ctx, $frame);
        if ($ret < 0) {
            throw new RuntimeException("Cannot initiate filter context.");
        }
    }

    /**
     * Pull a frame from the filter context.
     *
     * @return FrameInterface The pulled frame.
     * @throws AvCodecException
     */
    public function pull(): FrameInterface
    {
        $frame = $this->libAVFilter->av_frame_alloc();
        $this->graph->configure();
        $ret = $this->libAVFilter->av_buffersink_get_frame($this->ctx, $frame);

        if ($ret < 0) {
            throw new RuntimeException("Cannot initiate filter context.");
        }

        return $this->generateFrame($frame);
    }

    /**
     * @return void
     */
    public function initiateSharedLibrary(): void
    {
        global $libAVFilter;

        if ($libAVFilter instanceof FFI) {
            $this->libAVFilter = $libAVFilter;
        }
    }

    /**
     * @return Filter
     */
    public function getFilter(): Filter
    {
        return $this->filter;
    }

    /**
     * @return Graph
     */
    public function getGraph(): Graph
    {
        return $this->graph;
    }

    /**
     * @return CData|null
     */
    public function getCtx(): ?CData
    {
        return $this->ctx;
    }

    /**
     * Generate a new Video or Audio frame and copy the org frame
     *
     * @param CData $frame
     * @return FrameInterface|null
     */
    private function generateFrame(CData $frame): ?FrameInterface
    {
        $filterName = $this->filter->getName();

        return match ($filterName) {
            "buffersink" => $this->generateVideoFrame($frame),
            "abuffersink" => $this->generateAudioFrame($frame),
            default => null,
        };
    }

    /**
     *  Generate a new Video frame and copy the org frame
     *
     * @param CData $frame
     * @return VideoFrame
     */
    private function generateVideoFrame(CData $frame): VideoFrame
    {
        $videoFrame = new VideoFrame();
        FFI::memcpy(FFI::addr($videoFrame->getFrame()), FFI::addr($frame), FFI::sizeof($frame));
        $videoFrame->setVideoFormat();
        $timeBase = $this->ctx->inputs[0]->time_base;
        $videoFrame->setTimeBase($timeBase->num, $timeBase->den);

        return $videoFrame;
    }

    /**
     * Generate a new Audio frame and copy the org frame
     *
     * @param CData $frame
     * @return AudioFrame
     */
    private function generateAudioFrame(CData $frame): AudioFrame
    {
        $audioFrame = new AudioFrame();
        FFI::memcpy(FFI::addr($audioFrame->getFrame()), FFI::addr($frame), FFI::sizeof($frame));
        $audioFrame->initializeUserAttributes();
        $timeBase = $this->ctx->inputs[0]->time_base;
        $audioFrame->setTimeBase($timeBase->num, $timeBase->den);

        return $audioFrame;
    }
}
