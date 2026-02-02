package com.xclusive.mediaplayer.player

sealed class PlayerState {
    object Idle : PlayerState()
    object Loading : PlayerState()
    data class Playing(
        val position: Long,
        val duration: Long,
        val currentIndex: Int,
        val totalItems: Int
    ) : PlayerState()
    data class Paused(val position: Long) : PlayerState()
    data class Error(val error: Throwable, val retryCount: Int = 0) : PlayerState()
    object Finished : PlayerState()
}
