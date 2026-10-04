=== Melintir ===
Contributors: melintir
Tags: page builder, elementor alternative, landing page, contact form, gutenberg
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Open-source page builder with a Rust/WASM brain. Container-only, fast frontend.

== Description ==

Melintir is an open-source page builder for people who like Elementor's
workflow but not its weight. The editor is a normal React app; the brain —
document model, CSS generation, validation — is a small Rust core compiled
to WebAssembly, so style calculations stay instant even on huge pages.

What you get in 0.5.0:

* 17 content widgets + Container layout: Heading, Text, Image, Button,
  Video, Divider, Spacer, Icon Box, Tabs, Form, Loop, Accordion, Gallery,
  Counter, Testimonial, Nav, Products — plus per-node custom CSS.
* Block copy-paste across pages (OS clipboard, Ctrl+C / Ctrl+V).
* Responsive controls (desktop / tablet / mobile) with live canvas preview.
* Undo/redo, autosave with 5-revision history and undo-safe restore.
* Starter templates, JSON import/export, Elementor migrator (WP-CLI + REST).
* Theme Builder slices 1–2: Canvas template, block-theme header/footer
  swap, display conditions, shortcodes, popup builder (lite).
* Form upgrades: per-form recipients, Turnstile, spam-check filter,
  CSV entry export.
* Dynamic tags resolved at render time; global color palette
  (`var(--mel-*)` refs) and global font tokens with curated stacks.
* Saved patterns: store any block as a reusable pattern, re-insert
  anywhere as a copy (Global widgets slice 1).
* Role manager: restrict builder access by role in Melintir → Settings.
* Woo lite slice 1: Products grid with live Store-API preview, sale
  badges, ratings, prices and add-to-cart (needs WooCommerce).
* Visitor frontend is plain HTML + cached CSS + ~1KB of JS.
  No WASM, no jQuery, no editor runtime on the public site.

== Installation ==

1. Upload this folder to `wp-content/plugins/melintir/` and activate.
   (Developers: `make all` rebuilds the WASM core and the editor.)
2. Edit any Page, then choose "Edit with Melintir" (Posts list row action
   or the Melintir admin menu).
3. Pick a starter template or build from widgets, Save, view the page.

== Frequently Asked Questions ==

= Does the visitor's browser need WebAssembly? =
No. WASM runs only in the editor (wp-admin), where it generates CSS
instantly. Visitors receive plain HTML + CSS.

= How is this faster than Elementor? =
Container-only layout (no legacy section/column wrappers), a single CSS
file per page cached in postmeta, and a frontend script under 1KB.
The editor never loads on the public site.

= Can I migrate from Elementor? =
Yes — for content pages. Run `wp melintir migrate <post-id> --dry-run`
first to see what maps and what is skipped (third-party widgets, motion
effects, custom CSS and column % widths are reported, not silently dropped).

= Where do form entries go? =
Melintir > Entries in wp-admin, plus an email to the site admin.
Spam defense is nonce + honeypot, with optional Cloudflare Turnstile
and a `melintir_form_spam_check` filter for custom rules.

= Will updating/deleting the plugin destroy my pages? =
No. Page data lives in `_melintir_data` postmeta and is kept on
uninstall; only generated CSS cache files are removed.

== Screenshots ==

1. Editor with panel, canvas preview and breakpoint switcher.
2. Per-widget inspector controls.
3. Starter templates, history, JSON import/export.
4. Form entries list.

== Changelog ==

= 0.5.0 =
* Block copy-paste across pages via OS clipboard (buttons + Ctrl+C/V,
  undo-safe fresh-ID copies, works with full-doc JSON too).
* Products widget (Woo lite slice 1): order by newest/price/rating/
  popularity/title, category picker, sale badges, ratings, prices,
  purchasable-aware add-to-cart, live Store-API preview in the canvas.

= 0.4.0 =
* Saved patterns (Global widgets slice 1): save any block as a reusable
  pattern, insert anywhere as a fresh-ID copy. Same sanitizer pipeline,
  max 50 patterns, verified end-to-end in Docker.
* Role manager: `melintir_allowed_roles` option + Settings UI; admins
  always keep access, empty = anyone with `edit_posts`.

= 0.3.0 =
* Nav menu widget with CSS-only mobile toggle.
* Dynamic tags resolved at render time.
* Global color palette with `var(--mel-*)` refs; tolerate PHP
  empty-array JSON in WASM.
* Global font tokens with curated stacks (system-sans/serif/mono,
  display, handwriting) wired to `typo.family` via font picker.

= 0.2.0 =
* 8 new widgets: Loop (live wp/v2 preview), Accordion, Gallery,
  Counter, Testimonial; per-node custom CSS (scoped, sanitized).
* Autosave with 5-revision history and undo-safe restore.
* Theme Builder slices 1–2: template CPT, Canvas template, block-theme
  header/footer swap, display conditions, shortcodes/template tags,
  popup builder lite (load/click triggers, session frequency).
* Form upgrades: per-form recipients, Cloudflare Turnstile,
  `melintir_form_spam_check` filter, CSV entry export, Settings screen.
* Editor bundle hardened as IIFE (no more `window.wp` collisions).

= 0.1.0 =
* Initial release: 10 content widgets + lite form widget.
* Responsive editing, undo/redo, templates, import/export.
* Elementor migrator (WP-CLI + REST, dry-run support).
* Rust/WASM CSS engine with JS fallback.
