# Melintir — Data Model (v0.1)

Same shape in 3 places: Rust struct <-> TS type <-> PHP array. JSON is contract.

## 1. Document

```json
{
  "version": "0.1.0",
  "root": { "id": "root", "elType": "container", "...": "..." },
  "globals": {
    "colors": { "primary": "#2563eb", "text": "#0f172a" },
    "fonts": { "heading": "Inter", "body": "Inter" },
    "breakpoints": { "tablet": 1024, "mobile": 767 }
  }
}
```

WP storage:

* `postmeta _melintir_data` = raw JSON (canonical)
* `postmeta _melintir_css` = generated CSS cache (PHP mirror)
* `postmeta _melintir_version` = `0.1.0`
* Editor autosave -> `_melintir_autosave` (then publish copies to `_melintir_data`)

## 2. Node

```ts
type ElType = "container" | "widget";
type WidgetType = "heading" | "text" | "image" | "button" | "video" | "divider" | "spacer" | "icon-box" | "tabs";

interface Node {
  id: string;              // nanoid(8), e.g. "a1b2c3d4"
  elType: ElType;
  widgetType?: WidgetType; // only if elType==="widget"
  settings: Settings;      // per-widget controls
  style: Style;            // shared: margin/padding/typography/responsive
  elements: Node[];        // children (only container, max depth 10)
}

interface Style {
  layout?: { direction?: "row"|"column"; gap?: number; justify?: string; align?: string };
  box?: { margin?: Box4; padding?: Box4; bg?: string; radius?: number };
  typo?: { size?: number; weight?: number; color?: string };
  responsive?: {
    tablet?: Partial<Style>;
    mobile?: Partial<Style>;
  };
  customCss?: string;      // v0.2+, sanitized, capped 2KB/node
}
```

Example (Heading in Container):

```json
{
  "id": "c001", "elType": "container",
  "settings": {}, "style": { "layout": { "direction": "column", "gap": 16 } },
  "elements": [
    { "id": "w001", "elType": "widget", "widgetType": "heading",
      "settings": { "text": "Hello", "tag": "h1" },
      "style": { "typo": { "size": 48, "weight": 700 } },
      "elements": [] }
  ]
}
```

## 3. CSS generation (must match in Rust + PHP)

Selector: `.mel-{id}` e.g. `.mel-w001 { font-size:48px; font-weight:700 }`

Tablet: `@media(max-width:1024px){ .mel-w001{...} }`
Mobile: `@media(max-width:767px){ ... }`

WASM API (wasm-bindgen):

```rust
generate_css(doc_json: &str) -> String
validate(doc_json: &str) -> Vec<String> // error list, empty = ok
diff(a_json: &str, b_json: &str) -> String // for history
```

PHP `Renderer::render_node($node): string` must output same class names so editor preview == frontend.

## 4. Limits v0.1 (anti-abuse + perf)

* max 1000 nodes, depth <= 6, settings JSON <= 500KB
* allow tags: `div,h1-h6,p,a,img,button,iframe,hr,span,i`
* allow attrs: `class,style,src,href,alt,loading`
* strip: `script,style[data-hack],on*`, `javascript:` URLs
