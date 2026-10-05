package de.steindorff.kochbuch;

import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.os.Build;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * Exposes the installed APK's own versionName/versionCode
 * (android/app/build.gradle) - ported from YTAN's AppInfoPlugin. The web
 * code is always loaded live from the server, so only the app shell itself
 * knows which APK version is installed; public/js/native-app.js shows it
 * next to the Kochbuch version at the bottom of the navigation drawer.
 */
@CapacitorPlugin(name = "AppInfo")
public class AppInfoPlugin extends Plugin {

    @PluginMethod
    public void getInfo(PluginCall call) {
        try {
            PackageInfo packageInfo = getContext().getPackageManager()
                    .getPackageInfo(getContext().getPackageName(), 0);

            long versionCode = (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P)
                    ? packageInfo.getLongVersionCode()
                    : packageInfo.versionCode;

            JSObject result = new JSObject();
            result.put("versionName", packageInfo.versionName);
            result.put("versionCode", versionCode);
            call.resolve(result);
        } catch (PackageManager.NameNotFoundException e) {
            call.reject("Could not read package info", e);
        }
    }
}
