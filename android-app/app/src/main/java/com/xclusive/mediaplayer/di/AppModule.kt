package com.xclusive.mediaplayer.di

import com.xclusive.mediaplayer.data.repository.ConfigRepository
import com.xclusive.mediaplayer.player.PlayerManager
import com.xclusive.mediaplayer.ui.MainViewModel
import com.xclusive.mediaplayer.web.WebViewManager
import org.koin.android.ext.koin.androidContext
import org.koin.androidx.viewmodel.dsl.viewModel
import org.koin.dsl.module

val appModule = module {
    // Config repository
    single { ConfigRepository(androidContext()) }
    
    // Player manager
    single { PlayerManager(androidContext(), get()) }
    
    // WebView manager
    single { WebViewManager(androidContext(), get()) }
    
    // ViewModel
    viewModel { MainViewModel(get(), get(), get()) }
}
