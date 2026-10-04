package de.steindorff.kochbuch;

import android.content.ContentResolver;
import android.database.Cursor;
import android.net.Uri;
import android.provider.OpenableColumns;
import android.util.Base64;
import android.util.Log;

import com.getcapacitor.JSObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;

/**
 * Reads an image behind a content:// URI into the {name, mimeType, data
 * (base64)} shape public/js/native-share.js / helper.js expect. Shared by
 * ShareReceiverPlugin (images shared into the app) and ClipboardReaderPlugin
 * (images copied to the clipboard, e.g. "Bild kopieren" in Chrome).
 */
final class SharedContent {

    private static final String TAG = "KochbuchShare";
    // The server's own upload limit is 5 MB, but the page downscales
    // larger images before uploading - so a bigger original is still
    // usable, just not an unbounded one.
    static final int MAX_FILE_BYTES = 20 * 1024 * 1024;

    private SharedContent() {}

    /**
     * @return {name, mimeType, data} or null when the URI isn't a readable
     *         image within the size limit (never throws - a sender's content
     *         provider can fail in odd ways, seen live: a gallery entry
     *         whose file no longer exists)
     */
    static JSObject readImage(ContentResolver resolver, Uri uri, String typeHint, int number) {
        String mimeType = null;
        try {
            mimeType = resolver.getType(uri);
        } catch (Exception e) {
            Log.w(TAG, "getType failed for URI", e);
        }
        if (mimeType == null || !mimeType.startsWith("image/") || mimeType.equals("image/*")) {
            mimeType = (typeHint != null && typeHint.startsWith("image/") && !typeHint.equals("image/*")) ? typeHint : null;
        }

        String name = displayName(resolver, uri);
        if (mimeType == null) {
            mimeType = guessFromName(name);
        }
        if (mimeType == null && typeHint != null && !typeHint.startsWith("image/")) {
            // Clearly not an image (e.g. a text/plain clip) - nothing to read.
            return null;
        }
        if (name == null || name.isEmpty()) {
            name = "bild-" + number + "." + (mimeType != null ? mimeType.substring(mimeType.indexOf('/') + 1) : "jpg");
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
                    Log.w(TAG, "File larger than " + MAX_FILE_BYTES + " bytes skipped");
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
            Log.w(TAG, "Reading URI failed", e);
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
            Log.w(TAG, "Querying the file name failed", e);
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
        if (lower.endsWith(".gif")) {
            return "image/gif";
        }
        return null;
    }
}
