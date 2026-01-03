package com.example.xclusivemediaplayer

import android.annotation.SuppressLint
import android.net.Uri
import android.os.Bundle
import android.os.Message
import android.view.KeyEvent
import android.view.View
import android.webkit.*
import androidx.activity.OnBackPressedCallback
import androidx.annotation.OptIn
import androidx.appcompat.app.AppCompatActivity
import androidx.media3.common.AudioAttributes
import androidx.media3.common.C
import androidx.media3.common.MediaItem
import androidx.media3.common.util.UnstableApi
import androidx.media3.exoplayer.DefaultLoadControl
import androidx.media3.exoplayer.DefaultRenderersFactory
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.ui.PlayerView
import org.json.JSONArray

@OptIn(UnstableApi::class)
class MainActivity : AppCompatActivity() {

    private lateinit var webView: WebView
    private var playerView: PlayerView? = null
    private var player: ExoPlayer? = null

    private val serverBase = "http://192.168.11.200:8050"

    @OptIn(UnstableApi::class) override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        window.decorView.setBackgroundColor(android.graphics.Color.BLACK)

        setContentView(R.layout.activity_main)
        webView = findViewById(R.id.webView)
//        webView.setLayerType(View.LAYER_TYPE_HARDWARE, null)
        playerView = findViewById(R.id.playerView)

        playerView?.useController = false
        playerView?.setShutterBackgroundColor(android.graphics.Color.BLACK)
        playerView?.setBackgroundColor(android.graphics.Color.BLACK)
//        playerView?.isFocusable = true
//        playerView?.isFocusableInTouchMode = true
//        playerView?.requestFocus()

        setupWebView()
        webView.loadUrl(serverBase)

        // Back button handling
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (playerView?.visibility == View.VISIBLE) {
                    stopVideo()
                } else if (webView.canGoBack()) {
                    webView.goBack()
                } else {
                    finish()
                }
            }
        })

        // Fullscreen system UI
        window.decorView.systemUiVisibility =
            View.SYSTEM_UI_FLAG_FULLSCREEN or
                    View.SYSTEM_UI_FLAG_HIDE_NAVIGATION or
                    View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY

        supportActionBar?.hide()
    }

    @SuppressLint("SetJavaScriptEnabled", "JavascriptInterface")
    private fun setupWebView() {
        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            mediaPlaybackRequiresUserGesture = false
            allowFileAccess = true
            loadsImagesAutomatically = true
            useWideViewPort = true
            loadWithOverviewMode = true
            setRenderPriority(WebSettings.RenderPriority.HIGH) // Deprecated but still helps
        }

        webView.addJavascriptInterface(PlayerBridge(this), "AndroidPlayer")

        webView.webChromeClient = object : WebChromeClient() {
            override fun onCreateWindow(
                view: WebView?,
                isDialog: Boolean,
                isUserGesture: Boolean,
                resultMsg: Message
            ): Boolean {
                return false
            }
        }

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(
                view: WebView?,
                request: WebResourceRequest?
            ): Boolean {
                val url = request?.url.toString()
                if (url.endsWith(".mp4") || url.endsWith(".webm") || url.contains(".m3u8")) {
                    playVideo(url)
                    return true
                }
                return false
            }
        }
    }

    fun playWithExoPlayer(playlistJson: String, index: Int, startTime: Double) {
        val playlist = JSONArray(playlistJson)
        val items = mutableListOf<MediaItem>()

        for (i in 0 until playlist.length()) {
            val httpUrl = playlist.getString(i)
            items.add(MediaItem.fromUri(Uri.parse(httpUrl)))
        }

        webView.visibility = View.GONE
        playerView?.visibility = View.VISIBLE

        val audioAttributes = AudioAttributes.Builder()
            .setUsage(C.USAGE_MEDIA)
            .setContentType(C.AUDIO_CONTENT_TYPE_MOVIE)
            .build()

        // Small buffer setup
        val loadControl = DefaultLoadControl.Builder()
            .setBufferDurationsMs(
                1500,  // minBufferMs: 1.5s
                5000,  // maxBufferMs: 5s
                500,   // bufferForPlaybackMs: 0.5s
                1000   // bufferForPlaybackAfterRebufferMs: 1s
            )
            .build()

        // RendererFactory with extension preference (hardware + optional codecs)
        val renderersFactory = DefaultRenderersFactory(this)
            .setExtensionRendererMode(DefaultRenderersFactory.EXTENSION_RENDERER_MODE_PREFER)

        player = ExoPlayer.Builder(this)
            .setAudioAttributes(audioAttributes, true)
            .build().also {
                playerView?.player = it
                it.setMediaItems(items, index, (startTime * 1000).toLong())
                it.prepare()
                it.play()
            }

        // Fullscreen immersive mode
        window.decorView.systemUiVisibility =
            View.SYSTEM_UI_FLAG_FULLSCREEN or
                    View.SYSTEM_UI_FLAG_HIDE_NAVIGATION or
                    View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
    }

    private fun playVideo(url: String) {
        // Single video wrapper
        playWithExoPlayer(JSONArray(listOf(url)).toString(), 0, 0.0)
    }

    fun stopFromJs() {
        stopVideo()
    }

    private fun stopVideo() {
        player?.stop()
        player?.release()
        player = null
        playerView?.visibility = View.GONE
        webView.visibility = View.VISIBLE

        window.decorView.systemUiVisibility = View.SYSTEM_UI_FLAG_VISIBLE
    }

    override fun onDestroy() {
        super.onDestroy()
        player?.release()
        webView.destroy()
    }

    override fun dispatchKeyEvent(event: KeyEvent): Boolean {
        if (player != null && playerView?.visibility == View.VISIBLE && event.action == KeyEvent.ACTION_DOWN) {
            when (event.keyCode) {
                KeyEvent.KEYCODE_DPAD_RIGHT -> {
                    player!!.seekToNextMediaItem()
                    return true
                }
                KeyEvent.KEYCODE_DPAD_LEFT -> {
                    player!!.seekToPreviousMediaItem()
                    return true
                }
                KeyEvent.KEYCODE_DPAD_UP, KeyEvent.KEYCODE_DPAD_DOWN -> {
                    stopVideo()
                    return true
                }
                KeyEvent.KEYCODE_DPAD_CENTER, KeyEvent.KEYCODE_ENTER -> {
                    if (player!!.isPlaying) player!!.pause() else player!!.play()
                    return true
                }
            }
        }
        return super.dispatchKeyEvent(event)
    }
}