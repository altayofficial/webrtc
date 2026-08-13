<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\MediaStreamTrack;

use Webrtc\RTP\Receiver\RTCRtpReceiver;
use Webrtc\RTP\RTCRtpTransceiver;

class RTCTrackEvent
{
    /**
     * This event is fired on RTCPeerConnection when a new MediaStreamTrack is added by the remote party.
     *
     * @param RTCRtpReceiver $receiver The RTCRtpReceiver associated with the event.
     * @param MediaStreamTrack $track The MediaStreamTrack associated with the event.
     * @param RTCRtpTransceiver $transceiver The RTCRtpTransceiver associated with the event.
     */
    public function __construct(
        public RTCRtpReceiver    $receiver,
        public MediaStreamTrack  $track,
        public RTCRtpTransceiver $transceiver
    )
    {
    }
}