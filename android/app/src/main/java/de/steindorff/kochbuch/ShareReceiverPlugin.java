package de.steindorff.kochbuch;

import android.content.ClipData;
import android.content.ContentResolver;
import android.content.Intent;
import android.net.Uri;

import com.getcapacitor.JSArray;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import java.util.ArrayList;
import java.util.List;

/**
 * Receives images and text shared into the app from any other app via
 * Android's share sheet (todo.md "Share to Kochbuch") - the reason this
 * native shell exists at all: as an installed PWA (Chrome WebAPK), Chrome
 * refuses to hand its own "share image" files to the web app (logcat:
 * "cr_WebAppLaunchHandler: Invalid launch URI:
 * content://com.android.chrome.FileProvider/..."), so sharing an image
 * straight out of Chrome arrived as an empty form. A real app is the
 * share target here instead, and Android grants it read access to the
 * shared content:// URIs regardless of which app (or browser) they come
 * from.
 *
 * Flow: the share intent (cold start: load(); app already running:
 * handleOnNewIntent()) is only remembered here and announced to the web
 * page via a retained "shareReceived" event. The page then calls
 * getPendingShare(), which reads the files (on Capacitor's plugin thread,
 * not the UI thread, via SharedContent) and returns them base64-encoded -
 * see public/js/native-share.js.
 */
@CapacitorPlugin(name = "ShareReceiver")
public class ShareReceiverPlugin extends Plugin {

    private static final int MAX_FILES = 8;

    private Intent pendingShare;

    @Override
    public void load() {
        remember(getActivity().getIntent());
    }

    @Override
    protected void handleOnNewIntent(Intent intent) {
        super.handleOnNewIntent(intent);
        remember(intent);
    }

    private void remember(Intent intent) {
        if (intent == null) {
            return;
        }
        String action = intent.getAction();
        if (!Intent.ACTION_SEND.equals(action) && !Intent.ACTION_SEND_MULTIPLE.equals(action)) {
            return;
        }
        synchronized (this) {
            pendingShare = intent;
        }
        // Retained: delivered as soon as the page registers its listener,
        // even if the share arrived before the page finished loading.
        notifyListeners("shareReceived", new JSObject(), true);
    }

    @PluginMethod
    public void getPendingShare(PluginCall call) {
        Intent intent;
        synchronized (this) {
            intent = pendingShare;
            pendingShare = null;
        }

        JSObject result = new JSObject();
        if (intent == null) {
            result.put("hasShare", false);
            call.resolve(result);
            return;
        }

        result.put("hasShare", true);
        result.put("title", stringExtra(intent, Intent.EXTRA_SUBJECT, Intent.EXTRA_TITLE));
        result.put("text", stringExtra(intent, Intent.EXTRA_TEXT));

        JSArray files = new JSArray();
        int skipped = 0;
        ContentResolver resolver = getContext().getContentResolver();
        for (Uri uri : sharedUris(intent)) {
            if (files.length() >= MAX_FILES) {
                skipped++;
                continue;
            }
            JSObject file = SharedContent.readImage(resolver, uri, intent.getType(), files.length() + 1);
            if (file == null) {
                skipped++;
                continue;
            }
            files.put(file);
        }
        result.put("files", files);
        result.put("skipped", skipped);
        call.resolve(result);
    }

    private static String stringExtra(Intent intent, String... keys) {
        for (String key : keys) {
            CharSequence value = intent.getCharSequenceExtra(key);
            if (value != null && value.toString().trim().length() > 0) {
                return value.toString();
            }
        }
        return null;
    }

    @SuppressWarnings("deprecation")
    private static List<Uri> sharedUris(Intent intent) {
        List<Uri> uris = new ArrayList<>();
        if (Intent.ACTION_SEND_MULTIPLE.equals(intent.getAction())) {
            ArrayList<Uri> list = intent.getParcelableArrayListExtra(Intent.EXTRA_STREAM);
            if (list != null) {
                uris.addAll(list);
            }
        } else {
            Uri single = intent.getParcelableExtra(Intent.EXTRA_STREAM);
            if (single != null) {
                uris.add(single);
            }
        }
        // Some senders only fill ClipData (which is also what carries the
        // read permission grant).
        if (uris.isEmpty() && intent.getClipData() != null) {
            ClipData clip = intent.getClipData();
            for (int i = 0; i < clip.getItemCount(); i++) {
                Uri uri = clip.getItemAt(i).getUri();
                if (uri != null) {
                    uris.add(uri);
                }
            }
        }
        return uris;
    }
}
