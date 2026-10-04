# Melintir — Roadmap (decided for you)

Goal: open-source Elementor competitor, win on perf + DX.

## v0.1 MVP — "Edit fast" (I decided: 9 widgets, no Theme Builder)

**Scope (4-6 weeks solo):**
* Core: Container (flex row/column, gap, justify/align), responsive switcher
* Widgets (9): Heading, Text, Image, Button, Video, Divider, Spacer, Icon-Box, Tabs (tabs proves nested interactivity)
* Editor: drag-drop, select, duplicate/delete, undo/redo (WASM history), navigator, autosave
* Style: margin/padding, bg, typography, global colors, tablet/mobile overrides
* Backend: save/load REST, PHP renderer, CSS cache, import/export JSON
* Perf gate: frontend JS <30KB, Lighthouse >95 on blank template

**Explicitly OUT:** Theme Builder, Popup, Form builder, Loop/Grid, Woo, Dynamic Tags, Custom CSS/JS box, AI, multilingual. These double scope. Ship editor that edits *page content* perfectly first.

Why these 9? Covers 80% of landing pages. Tabs is the hardest (JS state) — if tabs is smooth, everything else is easy. Form/Theme Builder are separate products in disguise.

## v0.2 — "Build faster"
* Accordion, Image Gallery, Counter, Testimonial
* Templates library + copy-paste cross-page
* Custom CSS per-node (sanitized), revision history UI
* CLI: `wp melintir export/import`

## v0.3 — "Theme it" (Elementor Pro parity start)
* Theme Builder: header/footer/single/archive (as CPT `melintir_template` + location rules)
* Popup builder, Form widget (lite, no integrations yet), Global widgets

## v1.0 — "Replace Elementor"
* Loop builder, menu/nav, Woo lite (product grid), dynamic tags (`{post_title}`), role manager
* Migration tool: Elementor `_elementor_data` -> `_melintir_data` converter (killer feature for adoption)
* wordpress.org release + demo site

## What I need from you to start coding

Say `scaffold v0.1` and I will create `melintir.php + includes/ + core/ (Rust) + editor/ (Vite+React)` skeleton wired to `../wordpress/` for local testing.
