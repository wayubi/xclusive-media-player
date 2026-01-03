package com.example.xclusivemediaplayer

import android.webkit.JavascriptInterface
import org.json.JSONArray

class PlayerBridge(private val activity: MainActivity, private val serverBase: String, private val useExoPlayer: Boolean) {

    @JavascriptInterface
    fun playFullscreen(playlistJson: String, index: Int, startTime: Double) {
        activity.runOnUiThread {
            if (useExoPlayer) {
                // Convert local paths to HTTP URLs
                val httpPlaylistJson = convertLocalPathsToHttp(playlistJson)
                activity.playWithExoPlayer(httpPlaylistJson, index, startTime)
            } else {
                // If using WebView only, maybe just load the URL directly
                val playlist = JSONArray(playlistJson)
                if (playlist.length() > 0) {
                    activity.loadWebViewUrl("$serverBase${playlist.getString(0)}")
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

    private fun convertLocalPathsToHttp(playlistJson: String): String {
        val playlist = JSONArray(playlistJson)
        val httpPlaylist = mutableListOf<String>()

        for (i in 0 until playlist.length()) {
            val localPath = playlist.getString(i).replace("\\", "/")
            val httpUrl = "$serverBase$localPath"
            httpPlaylist.add(httpUrl)
        }

        return JSONArray(httpPlaylist).toString()
    }
}