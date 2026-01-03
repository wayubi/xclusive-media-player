package com.example.xclusivemediaplayer

import android.webkit.JavascriptInterface
import org.json.JSONArray

class PlayerBridge(private val activity: MainActivity) {

    @JavascriptInterface
    fun playFullscreen(playlistJson: String, index: Int, startTime: Double) {
        activity.runOnUiThread {
            // Convert local paths to HTTP URLs
            val httpPlaylistJson = convertLocalPathsToHttp(playlistJson)
            activity.playWithExoPlayer(httpPlaylistJson, index, startTime)
        }
    }

    @JavascriptInterface
    fun closeFullscreen() {
        activity.runOnUiThread {
            activity.stopFromJs()
        }
    }

    private fun convertLocalPathsToHttp(playlistJson: String): String {
        val playlist = JSONArray(playlistJson)
        val httpPlaylist = mutableListOf<String>()
        val serverBase = "http://192.168.11.200:8050"

        for (i in 0 until playlist.length()) {
            val localPath = playlist.getString(i).replace("\\", "/")
            val httpUrl = "$serverBase$localPath"
            httpPlaylist.add(httpUrl)
        }

        return JSONArray(httpPlaylist).toString()
    }
}