# Xclusive Media Player - Android App

High-performance Android media player app with WebView UI and native ExoPlayer for video playback. Optimized for Fire TV, tablets, and phones.

## Features

- **Smart Orientation**: Landscape for tablets/TV, sensor-based for phones
- **Modern Architecture**: MVVM pattern with dependency injection (Koin)
- **Native Video Playback**: ExoPlayer with playlist support and error recovery
- **WebView Interface**: Full-featured grid-based media browser
- **Configuration System**: JSON-based server settings (not committed to git)
- **Fire TV Optimized**: Low-end device detection and performance tuning
- **Error Handling**: Retry mechanisms with exponential backoff
- **Modern Android APIs**: API 26+ (Android 8.0+) with latest best practices

## Architecture

```
com.xclusive.mediaplayer/
├── XclusiveMediaPlayerApp.kt          # Application class with Koin DI
├── data/
│   └── repository/
│       └── ConfigRepository.kt        # JSON config loader
├── di/
│   └── AppModule.kt                   # Koin dependency injection
├── player/
│   ├── PlayerManager.kt               # ExoPlayer wrapper with state management
│   └── PlayerState.kt                 # Sealed class for player states
├── ui/
│   ├── MainActivity.kt                # Main activity with smart orientation
│   └── MainViewModel.kt               # MVVM ViewModel
├── util/
│   └── DeviceUtils.kt                 # Device detection (TV/Tablet/Phone)
├── web/
│   ├── WebViewManager.kt              # WebView configuration
│   └── bridge/
│       └── PlayerBridge.kt            # JavaScript interface for web app
```

## Configuration

Server settings are stored in `res/raw/config.json`:

```json
{
  "server": {
    "host": "your-server-domain.com",
    "port": 8050,
    "useHttps": true
  },
  "features": {
    "useExoPlayer": true,
    "enableDiscovery": false
  },
  "ui": {
    "forceLandscapeOnTablets": true
  }
}
```

**Important**: `config.json` is gitignored. Use `config_template.json` as a template.

## Smart Orientation

The app automatically detects device type and sets orientation:

- **TV Devices**: Always landscape
- **Tablets**: Landscape (configurable)
- **Phones**: Follow device sensor (user can rotate)

## Build Requirements

- **Min SDK**: 26 (Android 8.0)
- **Target SDK**: 36
- **Java**: 17
- **Kotlin**: 2.0+

## Key Dependencies

- **Koin**: Dependency injection
- **Media3 ExoPlayer**: Video playback
- **Lifecycle Components**: ViewModel, LiveData
- **Coroutines**: Async operations

## ProGuard/R8

Release builds are optimized with:
- Code shrinking enabled
- Resource shrinking enabled
- Custom ProGuard rules for JavaScript interface and Media3

## Improvements from v1.0

1. ✅ Smart orientation (fixes phone landscape issue)
2. ✅ JSON configuration system (replaces hardcoded IP)
3. ✅ MVVM architecture with proper separation of concerns
4. ✅ Dependency injection with Koin
5. ✅ Modern Android APIs (WindowInsets, etc.)
6. ✅ Player state management with proper lifecycle
7. ✅ Error handling with retry mechanisms
8. ✅ Fire TV detection and optimization
9. ✅ Build optimizations (ProGuard, shrinking)
10. ✅ Proper package naming (com.xclusive.mediaplayer)

## Building

```bash
./gradlew assembleRelease
```

## Installation

Install APK on Fire TV, tablet, or phone:
```bash
adb install app/build/outputs/apk/release/app-release.apk
```
