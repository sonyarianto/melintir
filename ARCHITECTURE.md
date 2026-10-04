# Melintir — Architecture (v0.1 decision)

> Open-source Elementor competitor. Better performance by design, not by patching.
> Goal: GPLv2+, wordpress.org compatible, container-only from day 1.

## 1. Your Q3: "Rust for core?"

Yes, exactly right. **Rust is ONLY for building the `.wasm` file.** Nothing else.

```
┌─────────────────────────────────────────────────┐
│ WordPress (PHP)                                 │
│  melintir.php, Renderer.php, REST + nonce       │  <- SEO, save, security, frontend HTML
└──────────────▲──────────────────────────────────┘
               │ wp.apiFetch (JSON)
┌──────────────┴──────────────────────────────────┐
│ Editor shell (React 18 + TypeScript)            │  <- panel, drag-drop, iframe preview
│  panel/, canvas/, store.ts (zustand)           │  <- this is normal JS, touches DOM
│  calls wasm synchronously:                     │
│    css = core.generate_css(doc)                 │
└──────────────▲──────────────────────────────────┘
               │ wasm-bindgen glue (auto-generated .js)
┌──────────────┴──────────────────────────────────┐
│ Core brain (Rust -> melintir-core.wasm)         │  <- NO DOM here, pure computation
│  Document model, CSS gen, validate, diff,      │
│  history/undo, template merge                   │
└─────────────────────────────────────────────────┘
```

* **Shell = React:** because WP admin, Gutenberg, `wp.media`, drag-drop, iframe messaging all expect JS. Writing UI in Rust/Yew would mean every click crosses WASM<->JS boundary = slower + painful debug.
* **Core = Rust:** because it's pure logic (JSON in -> CSS out). Easy to unit-test, type-safe, <100KB gzip, reusable later for headless/SaaS/CLI.
* **Backend = PHP:** because wordpress.org requires it. Frontend visitor gets **zero WASM** — just HTML+CSS from PHP for SEO and speed.

Rule: **WASM computes, JS applies, PHP renders.**

## 2. Why this beats Elementor on perf

Elementor's debt: legacy Section+Column + Container, jQuery, 400KB+ frontend.js, CSS per-widget files, server roundtrip on every style change.

Melintir v1 rules:

1. **Container-only.** No `section`/`column` legacy. Flexbox + CSS grid only.
2. **Frontend JS <30KB.** Only motion/accordion/tabs handler. No editor runtime on visitor side.
3. **Single CSS file per page.** Generated once in WASM (editor, instant preview) + mirrored in PHP (frontend, cached in `postmeta _melintir_css` + file in `uploads/melintir/`).
4. **No jQuery.** Vanilla + tiny helpers.
5. **WASM CSS gen target:** <5ms for 500-node doc (measured via `performance.now()` in editor).

## 3. Repo layout (to be scaffolded)

```
melintir/                  <- WP plugin root (this repo)
  melintir.php             <- plugin header + bootstrap
  includes/
    Plugin.php             <- singleton, hooks
    Renderer.php           <- PHP mirror of CSS/HTML gen (SEO)
    Rest.php               <- /melintir/v1/{save,load,templates}
    Security.php           <- caps, nonce, wp_kses allow-list
  assets/
    editor/                <- built React app (Vite -> editor.js/css)
    core/                  <- melintir-core.wasm + melintir-core.js (wasm-pack)
    frontend/              <- frontend.js (<30KB), frontend.css base
  core/                    <- Rust crate (wasm-pack)
    src/{lib.rs, document.rs, css.rs, validate.rs}
    Cargo.toml
  editor/                  <- React+TS source (Vite)
    src/{App.tsx, store.ts, panel/, canvas/, widgets/}
    package.json
  templates/               <- starter JSON templates
  ARCHITECTURE.md (this), DATA_MODEL.md, ROADMAP.md
```

Build: `cargo build -p melintir-core --target wasm32-unknown-unknown` via `wasm-pack build core --target web`, then `npm --prefix editor run build`. No remote CDN loads (wordpress.org rule).

## 4. Security / WP compliance

* `current_user_can('edit_post', $id)` + `check_ajax_referer` / nonce on every save.
* Re-validate in PHP even if WASM already validated: allow-list tags via `wp_kses`, max nodes 2000, max depth 10, CSS size cap 100KB.
* Text Domain `melintir`, GPLv2+ header, no obfuscated blob — `core/` source ships in repo.
