package de.steindorff.kochbuch;

import android.content.Intent;
import android.net.Uri;

import androidx.core.content.FileProvider;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;

/**
 * In-app update (todo.md "Update-Funktion für die App"): the app isn't
 * distributed through the Play Store but as an APK on the Kochbuch server
 * (bin\publish-app.bat, public/app/kochbuch.apk + version.json).
 * public/js/native-app.js compares version.json with AppInfoPlugin's
 * installed version and, on "Jetzt aktualisieren", calls
 * downloadAndInstall(): the APK is downloaded into the app's cache (with
 * "downloadProgress" events) and handed to Android's package installer.
 * On the first update Android itself asks to allow "install unknown apps"
 * for Kochbuch (REQUEST_INSTALL_PACKAGES in the manifest makes that
 * possible). Only APKs from the app's own server are accepted, so the
 * method can't be used to install arbitrary packages.
 */
@CapacitorPlugin(name = "AppUpdate")
public class AppUpdatePlugin extends Plugin {

    private static final int MAX_APK_BYTES = 100 * 1024 * 1024;

    @PluginMethod
    public void downloadAndInstall(PluginCall call) {
        String url = call.getString("url");
        if (url == null || !isOwnServer(url)) {
            call.reject("Only updates from the Kochbuch server are allowed.");
            return;
        }

        File dir = new File(getContext().getCacheDir(), "updates");
        if (!dir.isDirectory() && !dir.mkdirs()) {
            call.reject("Cannot create the download folder.");
            return;
        }
        File apk = new File(dir, "kochbuch-update.apk");

        HttpURLConnection connection = null;
        try {
            connection = (HttpURLConnection) new URL(url).openConnection();
            connection.setConnectTimeout(15000);
            connection.setReadTimeout(60000);
            connection.setUseCaches(false);
            int status = connection.getResponseCode();
            if (status != HttpURLConnection.HTTP_OK) {
                call.reject("Download failed (HTTP " + status + ").");
                return;
            }
            long total = connection.getContentLengthLong();
            long done = 0;
            int lastPercent = -1;
            try (InputStream in = connection.getInputStream(); OutputStream out = new FileOutputStream(apk)) {
                byte[] buffer = new byte[64 * 1024];
                int read;
                while ((read = in.read(buffer)) != -1) {
                    done += read;
                    if (done > MAX_APK_BYTES) {
                        call.reject("Download too large.");
                        return;
                    }
                    out.write(buffer, 0, read);
                    if (total > 0) {
                        int percent = (int) (done * 100 / total);
                        if (percent != lastPercent) {
                            lastPercent = percent;
                            JSObject progress = new JSObject();
                            progress.put("percent", percent);
                            notifyListeners("downloadProgress", progress);
                        }
                    }
                }
            }
        } catch (Exception e) {
            call.reject("Download failed: " + e.getMessage(), e);
            return;
        } finally {
            if (connection != null) {
                connection.disconnect();
            }
        }

        try {
            Uri uri = FileProvider.getUriForFile(getContext(), getContext().getPackageName() + ".fileprovider", apk);
            Intent install = new Intent(Intent.ACTION_VIEW);
            install.setDataAndType(uri, "application/vnd.android.package-archive");
            install.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
            getContext().startActivity(install);
        } catch (Exception e) {
            call.reject("Cannot open the installer: " + e.getMessage(), e);
            return;
        }

        call.resolve();
    }

    private boolean isOwnServer(String url) {
        try {
            String serverUrl = getBridge().getServerUrl();
            String allowedHost = serverUrl != null ? Uri.parse(serverUrl).getHost() : getBridge().getHost();
            Uri target = Uri.parse(url);
            return "https".equalsIgnoreCase(target.getScheme())
                    && allowedHost != null
                    && allowedHost.equalsIgnoreCase(target.getHost());
        } catch (Exception e) {
            return false;
        }
    }
}
