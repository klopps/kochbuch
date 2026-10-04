package de.steindorff.kochbuch;

import android.content.ClipData;
import android.content.ClipDescription;
import android.content.ClipboardManager;
import android.content.Context;
import android.net.Uri;

import com.getcapacitor.JSArray;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * Reads the clipboard natively for the "Aus Zwischenablage einfügen" buttons
 * (helper.js readClipboardContent()): inside the app's WebView,
 * navigator.clipboard.read() is always rejected - Android WebView has no
 * permission prompt for clipboard reads and simply denies them. Android
 * itself lets the foreground app read the clipboard, and hands it read
 * access to content:// URIs in the clip (e.g. the image Chrome puts there
 * on "Bild kopieren").
 */
@CapacitorPlugin(name = "ClipboardReader")
public class ClipboardReaderPlugin extends Plugin {

    private static final int MAX_IMAGES = 8;

    @PluginMethod
    public void read(PluginCall call) {
        ClipboardManager clipboard = (ClipboardManager) getContext().getSystemService(Context.CLIPBOARD_SERVICE);
        ClipData clip = clipboard != null ? clipboard.getPrimaryClip() : null;

        JSArray images = new JSArray();
        StringBuilder text = new StringBuilder();
        if (clip != null) {
            ClipDescription description = clip.getDescription();
            String typeHint = description != null && description.getMimeTypeCount() > 0 ? description.getMimeType(0) : null;
            for (int i = 0; i < clip.getItemCount(); i++) {
                ClipData.Item item = clip.getItemAt(i);
                Uri uri = item.getUri();
                if (uri != null && images.length() < MAX_IMAGES) {
                    JSObject image = SharedContent.readImage(getContext().getContentResolver(), uri, typeHint, images.length() + 1);
                    if (image != null) {
                        images.put(image);
                        continue;
                    }
                }
                CharSequence itemText = item.getText();
                if (itemText != null && text.length() == 0) {
                    text.append(itemText);
                }
            }
        }

        JSObject result = new JSObject();
        result.put("images", images);
        result.put("text", text.toString().trim());
        call.resolve(result);
    }
}
