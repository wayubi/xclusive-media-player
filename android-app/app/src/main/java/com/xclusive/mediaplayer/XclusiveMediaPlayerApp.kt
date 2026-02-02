package com.xclusive.mediaplayer

import android.app.Application
import com.xclusive.mediaplayer.di.appModule
import org.koin.android.ext.koin.androidContext
import org.koin.core.context.startKoin

class XclusiveMediaPlayerApp : Application() {
    override fun onCreate() {
        super.onCreate()
        
        startKoin {
            androidContext(this@XclusiveMediaPlayerApp)
            modules(appModule)
        }
    }
}
