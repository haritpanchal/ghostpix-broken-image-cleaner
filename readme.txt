=== Ghostpix Broken Image Cleaner ===
Contributors: haritpanchal
Tags: broken images, missing images, media cleanup, classic editor, content cleanup
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find images whose files are missing from your uploads folder and remove them from post content safely — after review, with one-click undo.

== Description ==

Over the years, a WordPress site loses image files. Migrations drop them, media
libraries get purged, folders get reorganised. The `<img>` tags stay behind in your
post content, pointing at files that are no longer there. Visitors see a broken-image
icon, your server answers a 404, and the damage is spread across thousands of old
posts that nobody is going to open by hand.

Ghostpix Broken Image Cleaner finds those references and removes them from the content itself.

**It edits your content, so it is built to be careful about it:**

* **Nothing is changed without your say-so.** Scanning is read-only. It produces a
  list. You decide which entries to act on.
* **Detection is based on the filesystem, not guesswork.** The plugin checks whether
  the file actually exists in your uploads folder. There are no network requests, so
  there is nothing to be confused by a slow server, a timeout, or hotlink protection.
* **Every removal can be undone.** The post's original content is saved before the
  edit, and a single click puts it back.
* **The rest of your post is left alone, byte for byte.** Only the broken image is
  touched. Your markup, shortcodes and formatting are not reformatted around it.

**It cleans up the leftovers, too.** Removing an `<img>` usually strands the markup
that wrapped it. The plugin also removes the container when nothing else is left in
it — the Classic Editor's `[caption]` shortcode, a link that only wrapped the image,
and empty `<figure>`, `<figcaption>` and `<picture>` elements. No empty boxes or
orphaned captions left behind.

= Built for Classic Editor content =

This plugin targets content where images live as raw HTML in the post body, which is
how the Classic Editor stores them. That is exactly the older content most likely to
have accumulated broken images.

= No external services =

This plugin makes no outbound connections of any kind. It does not phone home, does
not send your content anywhere, and does not require an account. Everything happens
on your own server.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/ghostpix-broken-image-cleaner`, or install it
   through the Plugins screen.
2. Activate the plugin.
3. Go to **Tools → Broken Images** and start a scan.
4. Review the results, select the entries you want to clean, and choose **Remove**.

Taking a database backup before your first bulk removal is always a good idea, even
though the plugin keeps its own undo data.

== Frequently Asked Questions ==

= Does this delete my image files? =

No. It never deletes, moves or modifies any file, and it never deletes anything from
your Media Library. It only edits the HTML in your post content, and only where the
file is already missing.

= What counts as a broken image? =

An image is reported when its file cannot be found in your uploads folder, or when
the file exists but is empty. Images hosted on other websites are not checked in this
version.

= An image shows up that I know is fine. Why? =

The most common cause is a missing generated size — for example, `photo-150x150.jpg`
is gone but `photo.jpg` is still there. Those are reported separately and are *not*
included in the default selection, because the right fix is usually to regenerate
your thumbnails, not to delete the image.

= An image that displays perfectly well is in the list. Why? =

Responsive images offer the browser several sizes through a `srcset` attribute.
If one of those sizes has gone missing the image still displays, because the
browser falls back to the one that works. Cleaning it edits only the `srcset`
list — the image itself is left exactly where it is.

= Can I undo a removal? =

Yes. The post's original content is stored before every edit, and the Undo button in
the results table restores it. Undo data is kept for 30 days by default, and you can
change that in the settings.

= Does it work with the Block Editor? =

Detection works on any content stored as HTML, but this version is designed and
tested for Classic Editor content. Block editor support is planned.

= Will it slow down my site? =

No. Scanning runs in the background in small batches and only in the admin. Nothing
runs on the front end at all.

== Screenshots ==

1. The results table, showing each broken image with the post it appears in.
2. Reviewing a removal before applying it.
3. Plugin settings, including which post types to scan and how long to keep undo data.

== Changelog ==

= 1.0.0 =
* Initial release.
* Background scanning of post content for images missing from the uploads folder.
* Review table with bulk remove, ignore and re-check.
* Removal of stranded `[caption]` shortcodes and empty link, figure and picture wrappers.
* Missing responsive sizes are dropped from `srcset`, leaving the image itself in place.
* Per-post undo with configurable retention.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
