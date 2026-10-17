package de.steindorff.kochbuch;

import android.os.Bundle;
import android.webkit.CookieManager;
import android.webkit.WebView;

import androidx.activity.OnBackPressedCallback;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // App-local plugin (plain source here, not an npm package), so `cap
        // sync` doesn't register it - must happen before super.onCreate().
        registerPlugin(ShareReceiverPlugin.class);
        registerPlugin(ClipboardReaderPlugin.class);
        registerPlugin(AppInfoPlugin.class);
        registerPlugin(AppUpdatePlugin.class);
        super.onCreate(savedInstanceState);
        registerBackHandler();
    }

    /**
     * Android back button (todo.md "Zurück in Android App funktioniert nicht
     * immer"): without @capacitor/app, nothing ties it to the WebView, so
     * Android just closed the activity and the user landed on the home
     * screen. Now: first let the page close an open drawer/dialog/dropdown
     * (window.KochbuchHandleBack(), public/js/native-app.js - absent on an
     * older web version, then simply "false"), else go back in the
     * WebView's history (the hash router's pages are history entries), and
     * only at the very first page fall through to Android's default.
     */
    private void registerBackHandler() {
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override
            public void handleOnBackPressed() {
                WebView webView = getBridge() != null ? getBridge().getWebView() : null;
                if (webView == null) {
                    leaveApp(this);
                    return;
                }
                webView.evaluateJavascript(
                    "(function(){try{return !!(window.KochbuchHandleBack&&window.KochbuchHandleBack());}catch(e){return false;}})()",
                    result -> {
                        if ("true".equals(result)) {
                            return;
                        }
                        if (webView.canGoBack()) {
                            webView.goBack();
                        } else {
                            leaveApp(this);
                        }
                    }
                );
            }
        });
    }

    private void leaveApp(OnBackPressedCallback callback) {
        callback.setEnabled(false);
        getOnBackPressedDispatcher().onBackPressed();
        callback.setEnabled(true);
    }

    /**
     * The WebView's CookieManager only persists cookie writes periodically,
     * and onDestroy() isn't guaranteed to run (process killed when swiped
     * away) - so the "settings" cookie (language, theme; public/js/
     * settings.js) could silently be lost on the next cold start. onPause()
     * always runs before the process becomes killable. Same fix as YTAN's
     * MainActivity.
     */
    @Override
    public void onPause() {
        super.onPause();
        CookieManager.getInstance().flush();
    }
}
