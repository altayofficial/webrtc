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
use Webrtc\AVCodec\Frame\AudioFrame;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\AVCodec\Frame\VideoFrame;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * Graph Class
 *
 * Manages a filter graph for audio and video processing using FFmpeg's libavfilter library.
 *
 * This class provides methods to construct, configure, and manage filter graphs, including:
 * - Adding and linking filters.
 * - Configuring the filter graph.
 * - Pushing and pulling frames for processing.
 */
class Graph implements SharedLibraryInterface
{
    /**
     * @var FFI\CData $graph Pointer to the FFmpeg filter graph.
     */
    private FFI\CData $graph;
    /**
     * @var bool $configured Indicates if the graph is configured for processing.
     */
    private bool $configured = false;
    /**
     * @var array $nameCounts Tracks filter name counts for unique naming.
     */
    private array $nameCounts = [];
    /**
     * @var int $nbFiltersSeen Number of filters processed by the graph.
     */
    private int $nbFiltersSeen = 0;
    /**
     * @var array $contextByPtr Filter contexts indexed by pointer.
     */
    private array $contextByPtr = [];
    /**
     * @var array $contextByName Filter contexts indexed by name.
     */
    private array $contextByName = [];
    /**
     * @var array $contextByType Filter contexts grouped by type.
     */
    private array $contextByType = [];
    /**
     * @var FFI $libAVFilter
     */
    private FFI $libAVFilter;

    /**
     * Constructor
     * Allocates a new filter graph.
     */
    public function __construct()
    {
        $this->initiateSharedLibrary();
        $this->graph = $this->libAVFilter->avfilter_graph_alloc();
    }

    /**
     * Destructor
     * Frees the graph
     */
    public function __destruct()
    {
        if ($this->graph) {
            $this->libAVFilter->avfilter_graph_free(FFI::addr($this->graph));
        }
    }

    /**
     * Get a unique name for a filter.
     *
     * @param string $name Base name of the filter.
     * @return string Unique name for the filter.
     */
    private function getUniqueName(string $name): string
    {
        $count = $this->nameCounts[$name] ?? 0;
        $this->nameCounts[$name] = $count + 1;
        return $count > 0 ? "{$name}_{$count}" : $name;
    }

    /**
     * Configure the filter graph.
     *
     * @param bool $autoBuffer Automatically buffer the graph.
     * @param bool $force Force reconfiguration of the graph.
     * @return void
     */
    public function configure(bool $autoBuffer = true, bool $force = false): void
    {
        if ($this->configured && !$force) {
            return;
        }

        $ret = $this->libAVFilter->avfilter_graph_config($this->graph, null);
        if ($ret < 0) {
            throw new RuntimeException("Cannot configure graph config.");
        }
        $this->configured = true;

        // TODO: Check this line later
//        $this->autoRegister();
    }

    /**
     * Link nodes together for simple filter graphs.
     *
     * @param array $nodes Array of nodes to link.
     * @return self
     */
    public function linkNodes(array $nodes): self
    {
        for ($i = 0; $i < count($nodes) - 1; $i++) {
            $nodes[$i]->linkTo($nodes[$i + 1]);
        }
        return $this;
    }

    /**
     * Add a filter to the graph.
     *
     * @param mixed $filter Filter name or Filter object.
     * @param array|null $args
     * @param array|null $options
     * @return FilterContext The created filter context.
     */
    public function add(Filter $filter, ?array $args = null, ?array $options = null): FilterContext
    {
        $name = $this->getUniqueName($options['name'] ?? $filter->getName());
        unset($options['name']);
        $context = new FilterContext($this, $filter, $name);
        if ($args) {
            $context->setup(...$args);
        }
        if (!empty($options)) {
            $context->setupWithDictionary($options);
        }

        // TODO: Check this line later
//        $this->registerContext($context);
        $this->autoRegister();

        return $context;
    }

    /**
     * Register a filter context.
     *
     * @param FilterContext $context The filter context to register.
     * @return void
     */
    private function registerContext(FilterContext $context): void
    {
        $this->contextByPtr[spl_object_id($context)] = $context;
        $this->contextByName[$context->getName()] = $context;
        $this->contextByType[$context->getFilter()->getName()][] = $context;
    }

    /**
     * Automatically register filter contexts in the graph.
     *
     * @return void
     */
    private function autoRegister(): void
    {
        for ($i = $this->nbFiltersSeen; $i < $this->graph->nb_filters; $i++) {
            $cCtx = $this->graph->filters[$i];
            if (isset($this->contextByPtr[spl_object_id($cCtx)])) {
                continue;
            }
            $filter = new Filter($cCtx->filter);
            $cloneCtx = new FilterContext($this, $filter, context: $cCtx);
            $this->registerContext($cloneCtx);
        }
        $this->nbFiltersSeen = $this->graph->nb_filters;
    }

    /**
     * @param int $frameSize
     * @return void
     */
    public function setAudioFrameSize(int $frameSize): void
    {
        if (!$this->configured) {
            throw new InvalidArgumentException("Graph not configured");
        }

        $sinks = $this->contextByType['abuffersink'] ?? [];
        if (empty($sinks)) {
            throw new InvalidArgumentException("Missing abuffersink filter");
        }

        foreach ($sinks as $sink) {
            $this->libAVFilter->av_buffersink_set_frame_size($sink->getCtx(), $frameSize);
        }
    }

    /**
     * @param FrameInterface|null $frame
     * @return void
     */
    public function push(?FrameInterface $frame): void
    {
        if ($frame === null) {
            $contexts = array_merge(
                $this->contextByType['buffer'] ?? [],
                $this->contextByType['abuffer'] ?? []
            );
        } elseif ($frame instanceof VideoFrame) {
            $contexts = $this->contextByType['buffer'] ?? [];
        } elseif ($frame instanceof AudioFrame) {
            $contexts = $this->contextByType['abuffer'] ?? [];
        } else {
            throw new InvalidArgumentException("Invalid frame type");
        }

        foreach ($contexts as $ctx) {
            $ctx->push($frame);
        }
    }

    /**
     * @return FrameInterface
     */
    public function pull(): FrameInterface
    {
        $videoSinks = $this->contextByType['buffersink'] ?? [];
        $audioSinks = $this->contextByType['abuffersink'] ?? [];

        $numSinks = count($videoSinks) + count($audioSinks);

        if ($numSinks !== 1) {
            throw new InvalidArgumentException("Can only auto-pull with a single sink; found {$numSinks}");
        }

        return ($videoSinks ?: $audioSinks)[0]->pull();
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
     * @return mixed
     */
    public function getGraph()
    {
        return $this->graph;
    }
}

