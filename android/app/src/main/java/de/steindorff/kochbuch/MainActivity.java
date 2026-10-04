package de.steindorff.kochbuch;

import android.os.Bundle;
import android.webkit.CookieManager;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // App-local plugin (plain source here, not an npm package), so `cap
        // sync` doesn't register it - must happen before super.onCreate().
        registerPlugin(ShareReceiverPlugin.class);
        registerPlugin(ClipboardReaderPlugin.class);
        super.onCreate(savedInstanceState);
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
