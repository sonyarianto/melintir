use crate::document::{Document, Node, Style};

/// Must stay in sync with includes/Renderer.php + editor/src/cssFallback.ts
/// Selector: .mel-{id}
pub fn generate_css(doc: &Document) -> String {
    let mut out = String::from(".mel-page{box-sizing:border-box}.mel-container{display:flex;flex-direction:column}\n.mel-loop{display:grid;gap:16px}.mel-cols-1{grid-template-columns:1fr}.mel-cols-2{grid-template-columns:repeat(2,1fr)}.mel-cols-3{grid-template-columns:repeat(3,1fr)}.mel-cols-4{grid-template-columns:repeat(4,1fr)}@media(max-width:767px){.mel-loop{grid-template-columns:1fr}}\n.mel-card{border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff}.mel-card-img img{width:100%;height:auto;display:block}.mel-card-title{font-size:18px;margin:12px 12px 4px}.mel-card-ex{font-size:14px;color:#475569;margin:0 12px 12px}\n.mel-badge{display:inline-block;background:#dc2626;color:#fff;font-size:12px;font-weight:700;padding:2px 8px;border-radius:999px;margin:8px 12px 0}.mel-price{font-size:16px;font-weight:700;margin:4px 12px}.mel-price del{color:#94a3b8;font-weight:400;margin-right:6px}.mel-price ins{text-decoration:none;background:none}.mel-stars{margin:0 12px;font-size:14px;color:#f59e0b;letter-spacing:2px}.mel-addcart{display:inline-block;background:#2563eb;color:#fff;font-weight:600;padding:8px 16px;border-radius:8px;text-decoration:none;margin:8px 12px 12px}\n.mel-ptitle{font-size:32px;margin:0 0 8px}.mel-pexcerpt{color:#475569;margin:8px 0}.mel-pimg img{width:100%;height:auto;display:block;border-radius:8px}.mel-pthumbs{display:flex;gap:8px;margin-top:8px}.mel-pthumbs img{width:72px;height:auto;border-radius:6px}\n.mel-menucart{display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:inherit;font-weight:600}.mel-cart-icon{font-size:20px}.mel-cart-count{display:inline-block;min-width:20px;text-align:center;background:#2563eb;color:#fff;font-size:12px;font-weight:700;border-radius:999px;padding:1px 6px}.mel-cart-total{font-size:14px;color:#475569}\n.mel-anim{opacity:0}.mel-anim.mel-in{animation-duration:.7s;animation-fill-mode:both;animation-timing-function:ease}[data-anim=\"fade-up\"].mel-in{animation-name:melUp}[data-anim=\"fade-in\"].mel-in{animation-name:melIn}[data-anim=\"zoom-in\"].mel-in{animation-name:melZoom}[data-anim=\"slide-left\"].mel-in{animation-name:melLeft}[data-anim=\"slide-right\"].mel-in{animation-name:melRight}@keyframes melUp{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:none}}@keyframes melIn{from{opacity:0}to{opacity:1}}@keyframes melZoom{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:none}}@keyframes melLeft{from{opacity:0;transform:translateX(36px)}to{opacity:1;transform:none}}@keyframes melRight{from{opacity:0;transform:translateX(-36px)}to{opacity:1;transform:none}}@media(prefers-reduced-motion:reduce){.mel-anim{opacity:1!important;animation:none!important}}\n.mel-countdown{display:flex;gap:12px}.mel-countdown span{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;text-align:center;min-width:72px}.mel-countdown b{display:block;font-size:28px}.mel-countdown small{color:#64748b}.mel-carousel{overflow:hidden}.mel-track{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:8px}.mel-slide{position:relative;flex:0 0 100%;scroll-snap-align:center}.mel-slide img{width:100%;height:auto;display:block;border-radius:8px}.mel-slide-cap{position:absolute;left:12px;bottom:12px;background:rgba(15,23,42,.65);color:#fff;padding:8px 12px;border-radius:8px;display:flex;flex-direction:column}.mel-price-table{border:1px solid #e2e8f0;border-radius:12px;padding:24px;text-align:center;background:#fff;max-width:340px}.mel-price-table.mel-hot{border-color:#2563eb;box-shadow:0 8px 24px rgba(37,99,235,.15)}.mel-pt-price{font-size:40px;font-weight:800}.mel-pt-price small{font-size:14px;font-weight:400;color:#64748b}.mel-pt-features{list-style:none;margin:16px 0;padding:0;display:flex;flex-direction:column;gap:8px}.mel-social{display:flex;gap:8px}.mel-social a{display:inline-flex;width:36px;height:36px;border-radius:50%;background:#f1f5f9;color:#0f172a;align-items:center;justify-content:center}.mel-social svg{width:18px;height:18px}.mel-stars-static{position:relative;display:inline-block;font-size:24px;line-height:1;letter-spacing:2px}.mel-stars-bg{color:#cbd5e1}.mel-stars-fg{position:absolute;left:0;top:0;overflow:hidden;white-space:nowrap;color:#f59e0b}\n.mel-gallery{display:grid;gap:12px}.mel-gcols-1{grid-template-columns:1fr}.mel-gcols-2{grid-template-columns:repeat(2,1fr)}.mel-gcols-3{grid-template-columns:repeat(3,1fr)}.mel-gcols-4{grid-template-columns:repeat(4,1fr)}.mel-gcols-5{grid-template-columns:repeat(5,1fr)}.mel-gcols-6{grid-template-columns:repeat(6,1fr)}@media(max-width:767px){.mel-gallery{grid-template-columns:repeat(2,1fr)}}\n.mel-gimg img{width:100%;height:auto;display:block;border-radius:8px}.mel-acc-item{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px}.mel-acc-item summary{cursor:pointer;padding:12px 16px;font-weight:600}.mel-acc-item summary+div{padding:0 16px 12px}.mel-counter{font-size:40px;font-weight:800}.mel-testimonial{border-left:4px solid #2563eb;padding:8px 16px;margin:0}.mel-testimonial blockquote{margin:0 0 8px;font-style:italic}.mel-tavatar{width:40px;height:40px;border-radius:50%;vertical-align:middle;margin-right:8px}.mel-tname{font-weight:700}.mel-trole{color:#64748b;margin-left:8px}\n.mel-nav-list{display:flex;gap:16px;list-style:none;margin:0;padding:0}.mel-nav-vertical .mel-nav-list{flex-direction:column}.mel-nav-list a{text-decoration:none;color:inherit}.mel-nav-sub{list-style:none;margin:4px 0 0 12px;padding:0}.mel-nav-check{display:none}.mel-nav-burger{display:none;cursor:pointer;font-size:24px}@media(max-width:767px){.mel-has-toggle .mel-nav-burger{display:block}.mel-has-toggle .mel-nav-list{display:none;flex-direction:column}.mel-has-toggle .mel-nav-check:checked+.mel-nav-burger+.mel-nav-list{display:flex}}\n");
    node_css(&doc.root, &mut out);
    if out.len() > 100 * 1024 {
        out.truncate(100 * 1024);
    }
    // Prepend :root palette after the cap check would risk cutting it;
    // globals are tiny, so build them first instead.
    let mut with_globals = globals_css(doc);
    with_globals.push_str(&out);
    if with_globals.len() > 100 * 1024 {
        with_globals.truncate(100 * 1024);
    }
    with_globals
}

/// :root{--mel-name: #hex; ...} from globals.colors. Names/values re-validated.
fn globals_css(doc: &Document) -> String {
    let mut decls = String::new();
    if let Some(colors) = doc.globals.get("colors").and_then(|v| v.as_object()) {
        for (name, value) in colors.iter().take(20) {
            let clean_name: String = name
                .chars()
                .filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-')
                .take(32)
                .collect();
            if clean_name.is_empty() {
                continue;
            }
            if let Some(hex) = value.as_str() {
                if is_hex_color(hex) {
                    decls.push_str(&format!("--mel-{clean_name}:{hex};"));
                }
            }
        }
    }
    if let Some(fonts) = doc.globals.get("fonts").and_then(|v| v.as_object()) {
        for (name, stack) in fonts.iter().take(20) {
            let clean_name: String = name
                .chars()
                .filter(|c| c.is_ascii_alphanumeric() || *c == '_' || *c == '-')
                .take(32)
                .collect();
            if clean_name.is_empty() {
                continue;
            }
            if let Some(stack) = stack.as_str() {
                if let Some(resolved) = resolve_font(stack) {
                    decls.push_str(&format!("--mel-font-{clean_name}:{resolved};"));
                }
            }
        }
    }
    if decls.is_empty() {
        String::new()
    } else {
        format!(":root{{{decls}}}\n")
    }
}

const FONT_STACKS: &[(&str, &str)] = &[
    ("system-sans", "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"),
    ("system-serif", "Georgia, 'Times New Roman', serif"),
    ("system-mono", "ui-monospace, Menlo, Consolas, monospace"),
    ("display", "Impact, 'Arial Narrow', sans-serif"),
    ("handwriting", "'Comic Sans MS', 'Chalkboard SE', cursive"),
];

/// Curated key -> stack; var(--mel-font-*) and safe custom stacks pass through.
fn resolve_font(s: &str) -> Option<String> {
    if let Some((_, stack)) = FONT_STACKS.iter().find(|(k, _)| *k == s) {
        return Some(stack.to_string());
    }
    if s.starts_with("var(--mel-font-") && s.ends_with(')') {
        let name = &s[13..s.len() - 1];
        if !name.is_empty()
            && name.len() <= 32
            && name.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_')
        {
            return Some(s.to_string());
        }
        return None;
    }
    let s = s.trim();
    if !s.is_empty()
        && s.len() <= 200
        && s.chars().all(|c| c.is_ascii_alphanumeric() || " ,'\"-".contains(c))
    {
        return Some(s.to_string());
    }
    None
}

fn is_hex_color(s: &str) -> bool {
    let h = s.strip_prefix('#').unwrap_or("\0");
    (h.len() == 3 || h.len() == 6) && h.chars().all(|c| c.is_ascii_hexdigit())
}

/// Hex or var(--mel-name); anything else becomes None (dropped).
fn sanitize_color(v: &serde_json::Value) -> Option<String> {
    let s = v.as_str()?;
    if is_hex_color(s) {
        return Some(s.to_string());
    }
    if s.starts_with("var(--mel-") && s.ends_with(')') {
        let name = &s[10..s.len() - 1];
        if !name.is_empty()
            && name.len() <= 32
            && name.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_')
        {
            return Some(s.to_string());
        }
    }
    None
}

fn node_css(node: &Node, out: &mut String) {
    let sel = format!(".mel-{}", sanitize_id(&node.id));
    let decl = style_decls(&node.style);
    if !decl.is_empty() {
        out.push_str(&sel);
        out.push('{');
        out.push_str(&decl);
        out.push_str("}\n");
    }
    // v0.1 supports both `tablet`/`mobile` top-level and `responsive.tablet`.
    let bps = [("tablet", 1024, breakpoint_style(&node.style, "tablet")), ("mobile", 767, breakpoint_style(&node.style, "mobile"))];
    for (_, max, bp) in bps {
        if let Some(s) = bp {
            let d = style_decls(s);
            if !d.is_empty() {
                out.push_str(&format!("@media(max-width:{max}px){{{sel}{{{d}}}}}\n"));
            }
        }
    }
    for child in &node.elements {
        node_css(child, out);
    }
}

fn breakpoint_style<'a>(style: &'a Style, bp: &str) -> Option<&'a Style> {
    match bp {
        "tablet" => style.tablet.as_deref().or_else(|| style.responsive.get("tablet").map(|b| b.as_ref())),
        "mobile" => style.mobile.as_deref().or_else(|| style.responsive.get("mobile").map(|b| b.as_ref())),
        _ => style.responsive.get(bp).map(|b| b.as_ref()),
    }
}

fn style_decls(style: &Style) -> String {
    let mut d = String::new();
    if let Some(v) = style.layout.get("direction").and_then(|v| v.as_str()) {
        if v == "row" || v == "column" {
            d.push_str(&format!("flex-direction:{v};"));
        }
    }
    if let Some(v) = num(&style.layout, "gap") {
        d.push_str(&format!("gap:{v}px;"));
    }
    if let Some(v) = style.layout.get("justify").and_then(|v| v.as_str()) {
        d.push_str(&format!("justify-content:{v};"));
    }
    if let Some(v) = style.layout.get("align").and_then(|v| v.as_str()) {
        d.push_str(&format!("align-items:{v};"));
    }
    if let Some(v) = style.layout.get("bg") {
        if let Some(c) = sanitize_color(v) {
            d.push_str(&format!("background:{c};"));
        }
    }
    if let Some(v) = num(&style.layout, "padding") {
        d.push_str(&format!("padding:{v}px;"));
    }
    if let Some(v) = num(&style.layout, "radius") {
        d.push_str(&format!("border-radius:{v}px;"));
    }
    if let Some(v) = style.layout.get("shadow").and_then(|v| v.as_str()) {
        let shadow = match v {
            "sm" => Some("0 1px 2px rgba(15,23,42,.08)"),
            "md" => Some("0 4px 12px rgba(15,23,42,.12)"),
            "lg" => Some("0 10px 28px rgba(15,23,42,.16)"),
            "xl" => Some("0 20px 48px rgba(15,23,42,.2)"),
            _ => None,
        };
        if let Some(s) = shadow {
            d.push_str(&format!("box-shadow:{s};"));
        }
    }
    if let Some(w) = num(&style.layout, "borderWidth") {
        if w > 0 {
            if let Some(c) = style.layout.get("borderColor").and_then(sanitize_color) {
                let st = style
                    .layout
                    .get("borderStyle")
                    .and_then(|v| v.as_str())
                    .filter(|s| *s == "solid" || *s == "dashed" || *s == "dotted")
                    .unwrap_or("solid");
                let w = w.clamp(1, 8);
                d.push_str(&format!("border:{w}px {st} {c};"));
            }
        }
    }
    if let Some(g) = style.layout.get("gradient").and_then(|v| v.as_object()) {
        let from = g.get("from").and_then(sanitize_color);
        let to = g.get("to").and_then(sanitize_color);
        if let (Some(f), Some(t)) = (from, to) {
            let angle = g
                .get("angle")
                .and_then(|v| v.as_i64().or_else(|| v.as_u64().map(|n| n as i64)))
                .unwrap_or(135)
                .clamp(0, 360);
            d.push_str(&format!("background:linear-gradient({angle}deg,{f},{t});"));
        }
    }
    if let Some(v) = num(&style.typo, "size") {
        d.push_str(&format!("font-size:{v}px;"));
    }
    if let Some(v) = num(&style.typo, "weight") {
        d.push_str(&format!("font-weight:{v};"));
    }
    if let Some(v) = style.typo.get("color") {
        if let Some(c) = sanitize_color(v) {
            d.push_str(&format!("color:{c};"));
        }
    }
    if let Some(v) = style.typo.get("family").and_then(|v| v.as_str()) {
        if let Some(f) = resolve_font(v) {
            d.push_str(&format!("font-family:{f};"));
        }
    }
    if let Some(raw) = style.custom_css.as_deref() {
        let clean = sanitize_custom_css(raw);
        if !clean.is_empty() {
            d.push_str(&clean);
            if !clean.ends_with(';') {
                d.push(';');
            }
        }
    }
    d
}

fn num(map: &std::collections::HashMap<String, serde_json::Value>, key: &str) -> Option<i64> {
    map.get(key).and_then(|v| v.as_i64().or_else(|| v.as_u64().map(|n| n as i64)))
}

fn sanitize_id(id: &str) -> String {
    id.chars().filter(|c| c.is_ascii_alphanumeric() || *c == '-' || *c == '_').take(32).collect()
}

fn sanitize_custom_css(raw: &str) -> String {
    let mut s: String = raw.chars().take(2048).collect();
    for pat in ["</style", "<style", "<script", "</script", "expression(", "javascript:", "behavior:", "@import"] {
        // Case-insensitive removal without regex (no_std-friendly approach).
        loop {
            let lower = s.to_lowercase();
            let Some(pos) = lower.find(pat) else { break };
            s.replace_range(pos..pos + pat.len(), "");
        }
    }
    s.trim().to_string()
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn heading_css() {
        let doc: Document = serde_json::from_value(serde_json::json!({
            "version": "0.1.0",
            "root": {"id":"c001","elType":"container","settings":{},"style":{"layout":{"direction":"column","gap":16}},"elements":[
                {"id":"w001","elType":"widget","widgetType":"heading","settings":{"text":"Hi"},"style":{"typo":{"size":48,"weight":700}},"elements":[]}
            ]}
        }))
        .unwrap();
        let css = generate_css(&doc);
        assert!(css.contains(".mel-w001{"));
        assert!(css.contains("font-size:48px"));
    }

    /// Box depth slice: shadow presets, borders and gradients must emit the
    /// exact same declarations as Renderer::style_decls (PHP mirror).
    #[test]
    fn box_decls() {
        let doc: Document = serde_json::from_value(serde_json::json!({
            "version": "0.1.0",
            "root": {"id":"root","elType":"container","settings":{},"style":{"layout":{
                "shadow": "lg",
                "borderWidth": 2, "borderStyle": "dashed", "borderColor": "#dc2626",
                "gradient": {"from": "#2563eb", "to": "#7c3aed", "angle": 135}
            }},"elements":[]}
        }))
        .unwrap();
        let css = generate_css(&doc);
        assert!(css.contains("box-shadow:0 10px 28px rgba(15,23,42,.16);"), "missing shadow, got: {css}");
        assert!(css.contains("border:2px dashed #dc2626;"), "missing border, got: {css}");
        assert!(css.contains("background:linear-gradient(135deg,#2563eb,#7c3aed);"), "missing gradient, got: {css}");
    }

    /// Regression: PHP encodes empty maps as `[]`. Real saved docs must parse.
    #[test]
    fn php_shaped_doc_with_empty_arrays() {
        let doc: Document = serde_json::from_value(serde_json::json!({
            "version": "0.1.0",
            "root": {"id":"root","elType":"container","settings":[],"style":{"layout":{"direction":"column","gap":20}},"elements":[
                {"id":"w1","elType":"widget","widgetType":"heading","settings":{"text":"Hi"},"style":{"typo":{"color":"var(--mel-primary)"}},"elements":[]}
            ]},
            "globals": {"colors": {"primary": "#2563eb"}, "breakpoints": {"tablet": 1024, "mobile": 767}}
        }))
        .expect("php-shaped doc must parse");
        let css = generate_css(&doc);
        assert!(css.contains(":root{--mel-primary:#2563eb;}"), "missing :root, got: {css}");
        assert!(css.contains(".mel-w1{color:var(--mel-primary);}"), "missing var ref, got: {css}");
    }
}
