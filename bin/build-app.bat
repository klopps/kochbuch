@echo off
REM Builds the Android app shell (android/, Capacitor) from the current
REM working tree - ported from YTAN's bin\build-app-release.bat.
REM
REM   bin\build-app.bat           debug build (debug keystore, for testing)
REM   bin\build-app.bat release   signed release .apk + .aab (needs
REM                               android\keystore.properties - gitignored,
REM                               pointing at a keystore in ~\.android\)
REM
REM Steps: npm install (Capacitor CLI), npx cap sync android (pushes
REM capacitor.config.json into android/ - the app-local ShareReceiverPlugin
REM lives directly in android/app/src and needs no sync), then gradlew.
REM
REM The app loads the live site (capacitor.config.json server.url), so web
REM changes only need bin\deploy.bat - a new APK is only needed when
REM something under android/ or capacitor.config.json changes.
REM
REM NOTE: never put an unescaped less-than, greater-than or pipe character
REM in a REM line of this file - cmd.exe still parses them as redirection
REM (YTAN found this the hard way: stray empty files in the repo root).
REM
REM JDK 21, NOT Android Studio's bundled JBR (v25 breaks Gradle's Groovy
REM compiler as soon as build.gradle changes) and not JDK 17 (Capacitor 8
REM needs Java 21) - see YTAN's notes. Override with ANDROID_BUILD_JDK_HOME.

setlocal

for %%I in ("%~dp0..") do set "ROOT_DIR=%%~fI"

set "VARIANT=debug"
if /i "%~1"=="release" set "VARIANT=release"

if "%VARIANT%"=="release" if not exist "%ROOT_DIR%\android\keystore.properties" (
    echo ==^> android\keystore.properties not found - a release build needs a signing
    echo      keystore configured there ^(storeFile/storePassword/keyAlias/keyPassword^).
    echo      Nothing was built.
    goto :error
)

if not defined ANDROID_BUILD_JDK_HOME set "ANDROID_BUILD_JDK_HOME=C:\Program Files\Eclipse Adoptium\jdk-21.0.12.101-hotspot"
if not exist "%ANDROID_BUILD_JDK_HOME%\bin\java.exe" (
    echo ==^> ANDROID_BUILD_JDK_HOME ^(%ANDROID_BUILD_JDK_HOME%^) has no bin\java.exe -
    echo      set it to a real JDK 21 install before running.
    goto :error
)
set "JAVA_HOME=%ANDROID_BUILD_JDK_HOME%"

if not exist "%ROOT_DIR%\android\local.properties" (
    echo sdk.dir=%LOCALAPPDATA:\=/%/Android/Sdk> "%ROOT_DIR%\android\local.properties"
)

pushd "%ROOT_DIR%" || goto :error

echo ==^> Installing npm dependencies (Capacitor CLI)
call npm install
if errorlevel 1 goto :error_in_root

echo ==^> Syncing config into the native Android project (cap sync android)
call node_modules\.bin\cap.cmd sync android
if errorlevel 1 goto :error_in_root

pushd android || goto :error_in_root
if "%VARIANT%"=="release" (
    echo ==^> Building signed release .aab + .apk ^(JAVA_HOME=%JAVA_HOME%^)
    call .\gradlew.bat :app:bundleRelease :app:assembleRelease
) else (
    echo ==^> Building debug .apk ^(JAVA_HOME=%JAVA_HOME%^)
    call .\gradlew.bat :app:assembleDebug
)
if errorlevel 1 (
    popd
    goto :error_in_root
)
popd

set "VERSION_NAME="
for /f "tokens=2 delims=	 " %%V in ('findstr /r "versionName" "android\app\build.gradle"') do set "VERSION_NAME=%%~V"

set "APK_DIR=android\app\build\outputs\apk\%VARIANT%"
if defined VERSION_NAME (
    copy /y "%APK_DIR%\app-%VARIANT%.apk" "%APK_DIR%\kochbuch_%VERSION_NAME%_%VARIANT%.apk" >nul
)

echo.
echo ==^> Build finished (%VARIANT%, version %VERSION_NAME%).
echo      APK: %ROOT_DIR%\%APK_DIR%\kochbuch_%VERSION_NAME%_%VARIANT%.apk
if "%VARIANT%"=="release" echo      AAB: %ROOT_DIR%\android\app\build\outputs\bundle\release\app-release.aab
echo      Install on a Xiaomi/MIUI phone: adb push the APK to //sdcard/Download/
echo      and open it in the file manager ^(adb install is blocked by MIUI^).

popd
endlocal
exit /b 0

:error_in_root
popd

:error
echo ==^> App build FAILED.
endlocal
exit /b 1
