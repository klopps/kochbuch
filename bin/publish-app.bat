@echo off
REM Uploads the most recently built Android APK (bin\build-app.bat) to the
REM live server, so the phone can download and install it straight from
REM https://kochen.steindorff.de/app/kochbuch.apk - no USB cable, no adb.
REM
REM   bin\publish-app.bat           uploads the debug APK
REM   bin\publish-app.bat release   uploads the release APK
REM
REM The file lands in public/app/ on the server, outside the deploy package
REM (*.apk is gitignored and bin\deploy.bat only extracts over the existing
REM tree, never deletes) - so it survives later deploys until replaced.
REM Same connection settings and .env password lookup as bin\deploy.bat.
REM
REM NOTE: never put an unescaped less-than, greater-than or pipe character
REM in a REM line of this file - cmd.exe parses them even in comments.

setlocal

for %%I in ("%~dp0..") do set "ROOT_DIR=%%~fI"

set "VARIANT=debug"
if /i "%~1"=="release" set "VARIANT=release"
set "APK=%ROOT_DIR%\android\app\build\outputs\apk\%VARIANT%\app-%VARIANT%.apk"
if not exist "%APK%" (
    echo ==^> %APK% not found - run bin\build-app.bat %~1 first.
    goto :error
)

if not defined DEPLOY_USER set "DEPLOY_USER=csteindorff"
if not defined DEPLOY_HOST set "DEPLOY_HOST=steindorff.de"
if not defined DEPLOY_PORT set "DEPLOY_PORT=22"
if not defined DEPLOY_PATH set "DEPLOY_PATH=/home/www/doc/9769/kochen.steindorff.de/www"
if not defined DEPLOY_SSH_CLIENT set "DEPLOY_SSH_CLIENT=plink"
if not defined DEPLOY_SSH_PASSWORD (
    for /f "usebackq eol=# tokens=1,* delims==" %%A in ("%ROOT_DIR%\.env") do (
        if "%%A"=="DEPLOY_SSH_PASSWORD" set "DEPLOY_SSH_PASSWORD=%%B"
    )
)
if not defined DEPLOY_SSH_PASSWORD (
    echo ==^> DEPLOY_SSH_PASSWORD is not set and not found in .env.
    goto :error
)

echo ==^> Uploading %VARIANT% APK to https://kochen.steindorff.de/app/kochbuch.apk
REM Written to a temp name first and renamed, so a phone never downloads a
REM half-uploaded file.
"%DEPLOY_SSH_CLIENT%" -ssh -P %DEPLOY_PORT% -l %DEPLOY_USER% -pw %DEPLOY_SSH_PASSWORD% %DEPLOY_HOST% "mkdir -p '%DEPLOY_PATH%/public/app' && cat > '%DEPLOY_PATH%/public/app/kochbuch.apk.tmp' && mv '%DEPLOY_PATH%/public/app/kochbuch.apk.tmp' '%DEPLOY_PATH%/public/app/kochbuch.apk' && ls -l '%DEPLOY_PATH%/public/app/kochbuch.apk'" < "%APK%"
if errorlevel 1 goto :error

echo ==^> Published. Download on the phone: https://kochen.steindorff.de/app/kochbuch.apk
endlocal
exit /b 0

:error
echo ==^> Publishing the app FAILED.
endlocal
exit /b 1
