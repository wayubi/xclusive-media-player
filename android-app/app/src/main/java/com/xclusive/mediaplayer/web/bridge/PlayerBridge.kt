package com.xclusive.mediaplayer.web.bridge

import android.net.Uri
import android.webkit.JavascriptInterface
import androidx.media3.common.MediaItem
import com.xclusive.mediaplayer.ui.MainActivity
import org.json.JSONArray

class PlayerBridge(
    private val activity: MainActivity,
    private val serverBase: String,
    private val useExoPlayer: Boolean
) {
    @JavascriptInterface
    fun playFullscreen(playlistJson: String, index: Int, startTime: Double) {
        activity.runOnUiThread {
            if (useExoPlayer) {
                val httpPlaylistJson = convertLocalPathsToHttp(playlistJson)
                activity.playWithExoPlayer(httpPlaylistJson, index, startTime)
            } else {
                val playlist = JSONArray(playlistJson)
                if (playlist.length() > 0) {
                    val firstItem = playlist.getString(0)
                    activity.loadWebViewUrl("$serverBase$firstItem")
                }
            }
        }
    }
    
    @JavascriptInterface
    fun closeFullscreen() {
        activity.runOnUiThread {
            if (useExoPlayer) activity.stopFromJs()
        }
    }
    
    @JavascriptInterface
    fun isExoPlayerAvailable(): Boolean {
        return useExoPlayer
    }
    
    private fun convertLocalPathsToHttp(playlistJson: String): String {
        val playlist = JSONArray(playlistJson)
        val httpPlaylist = mutableListOf<String>()
        
        for (i in 0 until playlist.length()) {
            val localPath = playlist.getString(i).replace("\\", "/")
            val httpUrl = if (localPath.startsWith("http")) {
                localPath
            } else {
                "$serverBase$localPath"
            }
            httpPlaylist.add(httpUrl)
        }
        
        return JSONArray(httpPlaylist).toString()
    }
}
