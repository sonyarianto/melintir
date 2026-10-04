=== Melintir ===
Contributors: melintir
Tags: page builder, gutenberg, elementor alternative, wasm
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Open-source page builder with Rust/WASM brain. Container-only, fast frontend.

== Description ==

Melintir v0.1: 9 widgets (Container, Heading, Text, Image, Button, Video, Divider, Spacer, Icon Box, Tabs), responsive controls, undo/redo, import/export JSON, PHP renderer + CSS cache.

Frontend visitors get plain HTML+CSS, no WASM, no jQuery.

== Installation ==

1. Copy this folder to `wp-content/plugins/melintir/` (or symlink for dev).
2. `npm --prefix editor install && npm --prefix editor run build`
3. `wasm-pack build core --target web --out-dir assets/core --out-name melintir-core`
4. Activate in wp-admin > Plugins.
5. Edit a Page > "Edit with Melintir".

== Frequently Asked Questions ==

= Does the visitor need WASM? =
No. WASM runs only in the editor (admin). Visitors get HTML+CSS.

= How is this faster than Elementor? =
Container-only, single CSS file per page, <30KB frontend JS, no editor runtime on frontend.
