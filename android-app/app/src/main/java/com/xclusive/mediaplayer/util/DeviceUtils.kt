package com.xclusive.mediaplayer.util

import android.content.Context
import android.content.res.Configuration
import android.os.Build

enum class DeviceType {
    PHONE,
    TABLET,
    TV
}

object DeviceUtils {
    
    fun getDeviceType(context: Context): DeviceType {
        val uiMode = context.resources.configuration.uiMode
        
        // Check for TV first
        if ((uiMode and Configuration.UI_MODE_TYPE_MASK) == 
            Configuration.UI_MODE_TYPE_TELEVISION) {
            return DeviceType.TV
        }
        
        // Check for tablet (smallest width >= 600dp)
        val smallestWidth = context.resources.configuration.smallestScreenWidthDp
        return if (smallestWidth >= 600) DeviceType.TABLET else DeviceType.PHONE
    }
    
    fun isRunningOnFireTv(context: Context): Boolean {
        return Build.MANUFACTURER.contains("Amazon", ignoreCase = true) ||
               context.packageManager.hasSystemFeature("amazon.hardware.fire_tv")
    }
    
    fun isLowEndDevice(context: Context): Boolean {
        // Fire TV devices are generally lower-end
        if (isRunningOnFireTv(context)) return true
        
        // Check available memory
        val activityManager = context.getSystemService(Context.ACTIVITY_SERVICE) 
            as android.app.ActivityManager
        val memoryInfo = android.app.ActivityManager.MemoryInfo()
        activityManager.getMemoryInfo(memoryInfo)
        
        // Consider low-end if less than 2GB RAM
        return memoryInfo.totalMem < 2L * 1024 * 1024 * 1024
    }
    
    fun getScreenOrientation(context: Context): Int {
        return context.resources.configuration.orientation
    }
}
