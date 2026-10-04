package de.steindorff.kochbuch;

import android.content.ClipData;
import android.content.ContentResolver;
import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.provider.OpenableColumns;
import android.util.Base64;
import android.util.Log;

import com.getcapacitor.JSArray;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
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
 * not the UI thread) and returns them base64-encoded - see
 * public/js/native-share.js.
 */
@CapacitorPlugin(name = "ShareReceiver")
public class ShareReceiverPlugin extends Plugin {

    private static final String TAG = "KochbuchShare";
    private static final int MAX_FILES = 8;
    // The server's own OCR limit is 5 MB, but the page downscales photos
    // before uploading (recipe-import-photo.js prepareImageForOcr()), so a
    // larger original is still usable - just not an unbounded one.
    private static final int MAX_FILE_BYTES = 20 * 1024 * 1024;

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
        int index = 0;
        ContentResolver resolver = getContext().getContentResolver();
        for (Uri uri : sharedUris(intent)) {
            if (index >= MAX_FILES) {
                skipped++;
                continue;
            }
            JSObject file = readFile(resolver, uri, intent.getType(), index + 1);
            if (file == null) {
                skipped++;
                continue;
            }
            files.put(file);
            index++;
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

    /**
     * @return {name, mimeType, data(base64)} or null when the URI isn't a
     *         readable image within the size limit
     */
    private static JSObject readFile(ContentResolver resolver, Uri uri, String intentType, int number) {
        String mimeType = null;
        try {
            mimeType = resolver.getType(uri);
        } catch (Exception e) {
            Log.w(TAG, "getType failed for shared URI", e);
        }
        if (mimeType == null || !mimeType.startsWith("image/") || mimeType.equals("image/*")) {
            mimeType = (intentType != null && intentType.startsWith("image/") && !intentType.equals("image/*")) ? intentType : null;
        }

        String name = displayName(resolver, uri);
        if (mimeType == null) {
            mimeType = guessFromName(name);
        }
        if (name == null || name.isEmpty()) {
            name = "geteilt-" + number + "." + (mimeType != null ? mimeType.substring(mimeType.indexOf('/') + 1) : "jpg");
        }

        try (InputStream in = resolver.openInputStream(uri)) {
            if (in == null) {
                return null;
            }
            ByteArrayOutputStream out = new ByteArrayOutputStream();
            byte[] buffer = new byte[64 * 1024];
            int read;
            while ((read = in.read(buffer)) != -1) {
                if (out.size() + read > MAX_FILE_BYTES) {
                    Log.w(TAG, "Shared file larger than " + MAX_FILE_BYTES + " bytes skipped");
                    return null;
                }
                out.write(buffer, 0, read);
            }
            if (out.size() == 0) {
                return null;
            }

            JSObject file = new JSObject();
            file.put("name", name);
            file.put("mimeType", mimeType != null ? mimeType : "image/jpeg");
            file.put("data", Base64.encodeToString(out.toByteArray(), Base64.NO_WRAP));
            return file;
        } catch (Exception e) {
            // A sender's content provider can fail in odd ways (seen live: a
            // gallery entry whose file no longer exists) - skip that file
            // rather than failing the whole share.
            Log.w(TAG, "Reading shared URI failed", e);
            return null;
        }
    }

    private static String displayName(ContentResolver resolver, Uri uri) {
        try (Cursor cursor = resolver.query(uri, new String[] { OpenableColumns.DISPLAY_NAME }, null, null, null)) {
            if (cursor != null && cursor.moveToFirst()) {
                int column = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                if (column >= 0) {
                    return cursor.getString(column);
                }
            }
        } catch (Exception e) {
            Log.w(TAG, "Querying the shared file's name failed", e);
        }
        String last = uri.getLastPathSegment();
        return last != null && last.contains(".") ? last : null;
    }

    private static String guessFromName(String name) {
        if (name == null) {
            return null;
        }
        String lower = name.toLowerCase();
        if (lower.endsWith(".png")) {
            return "image/png";
        }
        if (lower.endsWith(".webp")) {
            return "image/webp";
        }
        if (lower.endsWith(".jpg") || lower.endsWith(".jpeg")) {
            return "image/jpeg";
        }
        return null;
    }
}
