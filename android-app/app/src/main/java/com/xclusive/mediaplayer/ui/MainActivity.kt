package com.xclusive.mediaplayer.ui

import android.content.pm.ActivityInfo
import android.os.Bundle
import android.view.KeyEvent
import android.view.View
import androidx.activity.OnBackPressedCallback
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.core.view.updatePadding
import androidx.media3.common.MediaItem
import androidx.media3.common.util.UnstableApi
import androidx.media3.ui.PlayerView
import com.xclusive.mediaplayer.databinding.ActivityMainBinding
import com.xclusive.mediaplayer.di.appModule
import com.xclusive.mediaplayer.player.PlayerManager
import com.xclusive.mediaplayer.util.DeviceType
import com.xclusive.mediaplayer.util.DeviceUtils
import com.xclusive.mediaplayer.web.WebViewManager
import org.json.JSONArray
import org.koin.android.ext.android.inject
import org.koin.android.ext.koin.androidContext
import org.koin.core.context.startKoin

@UnstableApi
class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private val viewModel: MainViewModel by viewModels()
    private val playerManager: PlayerManager by inject()
    private val webViewManager: WebViewManager by inject()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Initialize Koin if not already started
        if (org.koin.core.context.GlobalContext.getOrNull() == null) {
            startKoin {
                androidContext(this@MainActivity)
                modules(appModule)
            }
        }

        // Set orientation BEFORE setContentView
        setupOrientation()

        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setupWindowInsets()
        setupBackPressHandler()
        setupWebView()
        setupPlayer()
        setupObservers()

        viewModel.loadInitialUrl()
    }

    private fun setupOrientation() {
        val deviceType = DeviceUtils.getDeviceType(this)
        val configRepository = org.koin.android.ext.android.getKoin().get<com.xclusive.mediaplayer.data.repository.ConfigRepository>()
        val forceLandscapeOnTablets = configRepository.shouldForceLandscapeOnTablets()

        requestedOrientation = when {
            deviceType == DeviceType.TV -> ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE
            deviceType == DeviceType.TABLET && forceLandscapeOnTablets -> ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE
            else -> ActivityInfo.SCREEN_ORIENTATION_FULL_SENSOR
        }
    }

    private fun setupWindowInsets() {
        WindowCompat.setDecorFitsSystemWindows(window, false)

        val controller = WindowInsetsControllerCompat(window, window.decorView)
        controller.hide(WindowInsetsCompat.Type.systemBars())
        controller.systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE

        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { view, windowInsets ->
            val insets = windowInsets.getInsets(WindowInsetsCompat.Type.systemBars())
            view.updatePadding(
                top = insets.top,
                bottom = insets.bottom
            )
            WindowInsetsCompat.CONSUMED
        }
    }

    private fun setupBackPressHandler() {
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (!viewModel.onBackPressed()) {
                    finish()
                }
            }
        })
    }

    private fun setupWebView() {
        webViewManager.configureWebView(binding.webView, this)
    }

    private fun setupPlayer() {
        binding.playerView.player = playerManager.createPlayer()
        binding.playerView.useController = false
    }

    private fun setupObservers() {
        viewModel.isLoading.observe(this) { isLoading ->
            binding.progressBar.visibility = if (isLoading) View.VISIBLE else View.GONE
        }

        viewModel.error.observe(this) { error ->
            binding.errorView.visibility = if (error != null) View.VISIBLE else View.GONE
            binding.errorText.text = error
        }

        viewModel.isPlayerVisible.observe(this) { isVisible ->
            binding.playerView.visibility = if (isVisible) View.VISIBLE else View.GONE
            binding.webView.visibility = if (isVisible) View.GONE else View.VISIBLE
        }

        viewModel.currentUrl.observe(this) { url ->
            webViewManager.loadUrl(url)
        }
    }

    fun playWithExoPlayer(playlistJson: String, index: Int, startTime: Double) {
        val playlist = JSONArray(playlistJson)
        val items = mutableListOf<MediaItem>()

        for (i in 0 until playlist.length()) {
            val url = playlist.getString(i)
            items.add(MediaItem.fromUri(url))
        }

        viewModel.playPlaylist(items, index, (startTime * 1000).toLong())
    }

    fun playVideo(url: String) {
        viewModel.playVideo(url)
    }

    fun stopFromJs() {
        viewModel.stopPlayback()
    }

    fun loadWebViewUrl(url: String) {
        webViewManager.loadUrl(url)
    }

    override fun dispatchKeyEvent(event: KeyEvent): Boolean {
        if (event.action == KeyEvent.ACTION_DOWN && viewModel.isPlayerVisible.value == true) {
            when (event.keyCode) {
                KeyEvent.KEYCODE_DPAD_RIGHT -> {
                    playerManager.seekToNext()
                    return true
                }
                KeyEvent.KEYCODE_DPAD_LEFT -> {
                    playerManager.seekToPrevious()
                    return true
                }
                KeyEvent.KEYCODE_DPAD_UP, KeyEvent.KEYCODE_DPAD_DOWN -> {
                    viewModel.stopPlayback()
                    return true
                }
                KeyEvent.KEYCODE_DPAD_CENTER, KeyEvent.KEYCODE_ENTER -> {
                    if (playerManager.isPlaying()) {
                        playerManager.pause()
                    } else {
                        playerManager.play()
                    }
                    return true
                }
            }
        }
        return super.dispatchKeyEvent(event)
    }

    override fun onDestroy() {
        super.onDestroy()
        viewModelStore.clear()
    }
}
