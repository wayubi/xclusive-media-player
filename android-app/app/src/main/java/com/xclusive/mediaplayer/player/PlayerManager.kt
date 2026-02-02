package com.xclusive.mediaplayer.player

import android.content.Context
import androidx.media3.common.AudioAttributes
import androidx.media3.common.C
import androidx.media3.common.MediaItem
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.common.util.UnstableApi
import androidx.media3.exoplayer.DefaultLoadControl
import androidx.media3.exoplayer.DefaultRenderersFactory
import androidx.media3.exoplayer.ExoPlayer
import com.xclusive.mediaplayer.data.repository.ConfigRepository
import com.xclusive.mediaplayer.util.DeviceUtils
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

@UnstableApi
class PlayerManager(
    private val context: Context,
    private val configRepository: ConfigRepository
) {
    private var exoPlayer: ExoPlayer? = null
    private val _playerState = MutableStateFlow<PlayerState>(PlayerState.Idle)
    val playerState: StateFlow<PlayerState> = _playerState.asStateFlow()
    
    private var retryCount = 0
    private val maxRetries = 3
    
    fun createPlayer(): ExoPlayer {
        return ExoPlayer.Builder(context)
            .setAudioAttributes(
                AudioAttributes.Builder()
                    .setUsage(C.USAGE_MEDIA)
                    .setContentType(C.AUDIO_CONTENT_TYPE_MOVIE)
                    .build(),
                true
            )
            .setLoadControl(createLoadControl())
            .setRenderersFactory(createRenderersFactory())
            .build()
            .also { player ->
                exoPlayer = player
                attachListeners(player)
            }
    }
    
    private fun createLoadControl(): DefaultLoadControl {
        return DefaultLoadControl.Builder()
            .setBufferDurationsMs(
                1500,  // minBufferMs: 1.5s
                5000,  // maxBufferMs: 5s
                500,   // bufferForPlaybackMs: 0.5s
                1000   // bufferForPlaybackAfterRebufferMs: 1s
            )
            .build()
    }
    
    private fun createRenderersFactory(): DefaultRenderersFactory {
        return DefaultRenderersFactory(context).apply {
            if (DeviceUtils.isLowEndDevice(context)) {
                // Prefer hardware decoders on low-end devices
                setExtensionRendererMode(DefaultRenderersFactory.EXTENSION_RENDERER_MODE_PREFER)
            } else {
                setExtensionRendererMode(DefaultRenderersFactory.EXTENSION_RENDERER_MODE_ON)
            }
        }
    }
    
    private fun attachListeners(player: ExoPlayer) {
        player.addListener(object : Player.Listener {
            override fun onPlaybackStateChanged(playbackState: Int) {
                when (playbackState) {
                    Player.STATE_IDLE -> _playerState.value = PlayerState.Idle
                    Player.STATE_BUFFERING -> _playerState.value = PlayerState.Loading
                    Player.STATE_READY -> {
                        retryCount = 0
                        updatePlayingState()
                    }
                    Player.STATE_ENDED -> _playerState.value = PlayerState.Finished
                }
            }
            
            override fun onIsPlayingChanged(isPlaying: Boolean) {
                updatePlayingState()
            }
            
            override fun onPositionDiscontinuity(
                oldPosition: Player.PositionInfo,
                newPosition: Player.PositionInfo,
                reason: Int
            ) {
                updatePlayingState()
            }
            
            override fun onPlayerError(error: PlaybackException) {
                handleError(error)
            }
        })
    }
    
    private fun updatePlayingState() {
        exoPlayer?.let { player ->
            val position = player.currentPosition
            val duration = player.duration.coerceAtLeast(0)
            val currentIndex = player.currentMediaItemIndex
            val totalItems = player.mediaItemCount
            
            _playerState.value = if (player.isPlaying) {
                PlayerState.Playing(position, duration, currentIndex, totalItems)
            } else {
                PlayerState.Paused(position)
            }
        }
    }
    
    private fun handleError(error: PlaybackException) {
        when (error.errorCode) {
            PlaybackException.ERROR_CODE_IO_NETWORK_CONNECTION_FAILED,
            PlaybackException.ERROR_CODE_IO_NETWORK_CONNECTION_TIMEOUT -> {
                if (retryCount < maxRetries) {
                    retryCount++
                    exoPlayer?.prepare()
                } else {
                    _playerState.value = PlayerState.Error(error, retryCount)
                }
            }
            else -> {
                _playerState.value = PlayerState.Error(error, retryCount)
            }
        }
    }
    
    fun playPlaylist(items: List<MediaItem>, startIndex: Int = 0, startPositionMs: Long = 0) {
        exoPlayer?.apply {
            retryCount = 0
            setMediaItems(items, startIndex, startPositionMs)
            prepare()
            play()
        }
    }
    
    fun play() {
        exoPlayer?.play()
    }
    
    fun pause() {
        exoPlayer?.pause()
    }
    
    fun stop() {
        exoPlayer?.stop()
    }
    
    fun release() {
        exoPlayer?.release()
        exoPlayer = null
        retryCount = 0
        _playerState.value = PlayerState.Idle
    }
    
    fun seekToNext() {
        exoPlayer?.seekToNextMediaItem()
    }
    
    fun seekToPrevious() {
        exoPlayer?.seekToPreviousMediaItem()
    }
    
    fun isPlaying(): Boolean {
        return exoPlayer?.isPlaying ?: false
    }
    
    fun getCurrentPosition(): Long {
        return exoPlayer?.currentPosition ?: 0
    }
    
    fun seekTo(positionMs: Long) {
        exoPlayer?.seekTo(positionMs)
    }
}
