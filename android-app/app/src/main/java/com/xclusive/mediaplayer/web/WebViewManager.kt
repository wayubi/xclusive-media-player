package com.xclusive.mediaplayer.web

import android.annotation.SuppressLint
import android.content.Context
import android.view.View
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.webkit.WebViewAssetLoader
import com.xclusive.mediaplayer.BuildConfig
import com.xclusive.mediaplayer.data.repository.ConfigRepository
import com.xclusive.mediaplayer.ui.MainActivity
import com.xclusive.mediaplayer.util.DeviceUtils

class WebViewManager(
    private val context: Context,
    private val configRepository: ConfigRepository
) {
    
    private var webView: WebView? = null
    
    @SuppressLint("SetJavaScriptEnabled")
    fun configureWebView(webView: WebView, activity: MainActivity) {
        this.webView = webView
        
        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            mediaPlaybackRequiresUserGesture = false
            allowFileAccess = true
            loadsImagesAutomatically = true
            useWideViewPort = true
            loadWithOverviewMode = true
            
            // Performance optimizations
            cacheMode = WebSettings.LOAD_DEFAULT
            
            // Fire TV optimization
            if (DeviceUtils.isLowEndDevice(context)) {
                @Suppress("DEPRECATION")
                setRenderPriority(WebSettings.RenderPriority.HIGH)
            }
        }
        
        // Add JavaScript interface
        webView.addJavascriptInterface(
            createBridge(activity),
            "AndroidPlayer"
        )
        
        webView.webChromeClient = object : WebChromeClient() {
            // Handle fullscreen video if needed
        }
        
        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(
                view: WebView?,
                request: WebResourceRequest?
            ): Boolean {
                val url = request?.url?.toString() ?: return false
                
                // Intercept video URLs to play with ExoPlayer
                if (configRepository.shouldUseExoPlayer() && 
                    (url.endsWith(".mp4") || 
                     url.endsWith(".webm") || 
                     url.contains(".m3u8"))) {
                    activity.playVideo(url)
                    return true
                }
                
                return false
            }
            
            override fun onPageFinished(view: WebView?, url: String?) {
                super.onPageFinished(view, url)
                // Inject configuration into JavaScript
                view?.evaluateJavascript(
                    "window.useExoPlayer = ${configRepository.shouldUseExoPlayer()}; " +
                    "window.AndroidPlayerAvailable = true;",
                    null
                )
            }
        }
        
        // Enable debugging in debug builds
        if (BuildConfig.DEBUG) {
            WebView.setWebContentsDebuggingEnabled(true)
        }
    }
    
    fun createBridge(activity: MainActivity): com.xclusive.mediaplayer.web.bridge.PlayerBridge {
        return com.xclusive.mediaplayer.web.bridge.PlayerBridge(
            activity = activity,
            serverBase = configRepository.getServerUrl(),
            useExoPlayer = configRepository.shouldUseExoPlayer()
        )
    }
    
    fun loadUrl(url: String) {
        webView?.loadUrl(url)
    }
    
    fun canGoBack(): Boolean {
        return webView?.canGoBack() ?: false
    }
    
    fun goBack() {
        webView?.goBack()
    }
    
    fun destroy() {
        webView?.destroy()
        webView = null
    }
    
    fun setVisibility(visibility: Int) {
        webView?.visibility = visibility
    }
}
