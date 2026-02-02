package com.xclusive.mediaplayer.ui

import androidx.lifecycle.LiveData
import androidx.lifecycle.MutableLiveData
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.media3.common.MediaItem
import com.xclusive.mediaplayer.data.repository.ConfigRepository
import com.xclusive.mediaplayer.player.PlayerManager
import com.xclusive.mediaplayer.player.PlayerState
import com.xclusive.mediaplayer.web.WebViewManager
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch

class MainViewModel(
    private val configRepository: ConfigRepository,
    private val playerManager: PlayerManager,
    private val webViewManager: WebViewManager
) : ViewModel() {

    private val _isLoading = MutableLiveData<Boolean>()
    val isLoading: LiveData<Boolean> = _isLoading

    private val _error = MutableLiveData<String?>()
    val error: LiveData<String?> = _error

    private val _isPlayerVisible = MutableLiveData<Boolean>()
    val isPlayerVisible: LiveData<Boolean> = _isPlayerVisible

    private val _currentUrl = MutableLiveData<String>()
    val currentUrl: LiveData<String> = _currentUrl

    val playerState: LiveData<PlayerState> = MutableLiveData()

    private val _canGoBack = MutableLiveData<Boolean>()
    val canGoBack: LiveData<Boolean> = _canGoBack

    init {
        viewModelScope.launch {
            playerManager.playerState.collectLatest { state ->
                (playerState as MutableLiveData).postValue(state)
            }
        }
    }

    fun loadInitialUrl() {
        val url = configRepository.getServerUrl()
        _currentUrl.value = url
        webViewManager.loadUrl(url)
    }

    fun playVideo(url: String) {
        val items = listOf(MediaItem.fromUri(url))
        playPlaylist(items, 0, 0)
    }

    fun playPlaylist(items: List<MediaItem>, startIndex: Int, startPositionMs: Long) {
        if (!configRepository.shouldUseExoPlayer()) {
            return
        }

        _isPlayerVisible.value = true
        playerManager.playPlaylist(items, startIndex, startPositionMs)
    }

    fun stopPlayback() {
        playerManager.stop()
        _isPlayerVisible.value = false
        _error.value = null
    }

    fun onBackPressed(): Boolean {
        return when {
            _isPlayerVisible.value == true -> {
                stopPlayback()
                true
            }
            webViewManager.canGoBack() -> {
                webViewManager.goBack()
                true
            }
            else -> false
        }
    }

    fun onWebViewBackStateChanged(canGoBack: Boolean) {
        _canGoBack.value = canGoBack
    }

    fun retry() {
        _error.value = null
        loadInitialUrl()
    }

    fun setError(message: String) {
        _error.value = message
    }

    override fun onCleared() {
        super.onCleared()
        playerManager.release()
        webViewManager.destroy()
    }
}
