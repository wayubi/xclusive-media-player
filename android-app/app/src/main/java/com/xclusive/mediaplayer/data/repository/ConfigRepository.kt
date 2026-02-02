package com.xclusive.mediaplayer.data.repository

import android.content.Context
import com.xclusive.mediaplayer.R
import org.json.JSONObject

class ConfigRepository(private val context: Context) {
    
    private val config: JSONObject by lazy {
        loadConfig()
    }
    
    private fun loadConfig(): JSONObject {
        return try {
            val inputStream = context.resources.openRawResource(R.raw.config)
            val jsonString = inputStream.bufferedReader().use { it.readText() }
            JSONObject(jsonString)
        } catch (e: Exception) {
            // Fallback to default config if file not found
            createDefaultConfig()
        }
    }
    
    private fun createDefaultConfig(): JSONObject {
        return JSONObject().apply {
            put("server", JSONObject().apply {
                put("host", "127.0.0.1")
                put("port", 8050)
                put("useHttps", false)
            })
            put("features", JSONObject().apply {
                put("useExoPlayer", true)
                put("enableDiscovery", false)
            })
            put("ui", JSONObject().apply {
                put("forceLandscapeOnTablets", true)
            })
        }
    }
    
    fun getServerUrl(): String {
        val serverConfig = config.getJSONObject("server")
        val host = serverConfig.getString("host")
        val port = serverConfig.getInt("port")
        val useHttps = serverConfig.getBoolean("useHttps")
        val protocol = if (useHttps) "https" else "http"
        return "$protocol://$host:$port"
    }
    
    fun shouldUseExoPlayer(): Boolean {
        return config.getJSONObject("features").getBoolean("useExoPlayer")
    }
    
    fun shouldEnableDiscovery(): Boolean {
        return config.getJSONObject("features").optBoolean("enableDiscovery", false)
    }
    
    fun shouldForceLandscapeOnTablets(): Boolean {
        return config.getJSONObject("ui").optBoolean("forceLandscapeOnTablets", true)
    }
    
    fun getServerHost(): String {
        return config.getJSONObject("server").getString("host")
    }
    
    fun getServerPort(): Int {
        return config.getJSONObject("server").getInt("port")
    }
}
