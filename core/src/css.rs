use crate::document::{Document, Node, Style};

/// Must stay in sync with includes/Renderer.php + editor/src/cssFallback.ts
/// Selector: .mel-{id}
pub fn generate_css(doc: &Document) -> String {
    let mut out = String::from(".mel-page{box-sizing:border-box}.mel-container{display:flex;flex-direction:column}\n.mel-loop{display:grid;gap:16px}.mel-cols-1{grid-template-columns:1fr}.mel-cols-2{grid-template-columns:repeat(2,1fr)}.mel-cols-3{grid-template-columns:repeat(3,1fr)}.mel-cols-4{grid-template-columns:repeat(4,1fr)}@media(max-width:767px){.mel-loop{grid-template-columns:1fr}}\n.mel-card{border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff}.mel-card-img img{width:100%;height:auto;display:block}.mel-card-title{font-size:18px;margin:12px 12px 4px}.mel-card-ex{font-size:14px;color:#475569;margin:0 12px 12px}\n.mel-gallery{display:grid;gap:12px}.mel-gcols-1{grid-template-columns:1fr}.mel-gcols-2{grid-template-columns:repeat(2,1fr)}.mel-gcols-3{grid-template-columns:repeat(3,1fr)}.mel-gcols-4{grid-template-columns:repeat(4,1fr)}.mel-gcols-5{grid-template-columns:repeat(5,1fr)}.mel-gcols-6{grid-template-columns:repeat(6,1fr)}@media(max-width:767px){.mel-gallery{grid-template-columns:repeat(2,1fr)}}\n.mel-gimg img{width:100%;height:auto;display:block;border-radius:8px}.mel-acc-item{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px}.mel-acc-item summary{cursor:pointer;padding:12px 16px;font-weight:600}.mel-acc-item summary+div{padding:0 16px 12px}.mel-counter{font-size:40px;font-weight:800}.mel-testimonial{border-left:4px solid #2563eb;padding:8px 16px;margin:0}.mel-testimonial blockquote{margin:0 0 8px;font-style:italic}.mel-tavatar{width:40px;height:40px;border-radius:50%;vertical-align:middle;margin-right:8px}.mel-tname{font-weight:700}.mel-trole{color:#64748b;margin-left:8px}\n.mel-nav-list{display:flex;gap:16px;list-style:none;margin:0;padding:0}.mel-nav-vertical .mel-nav-list{flex-direction:column}.mel-nav-list a{text-decoration:none;color:inherit}.mel-nav-sub{list-style:none;margin:4px 0 0 12px;padding:0}.mel-nav-check{display:none}.mel-nav-burger{display:none;cursor:pointer;font-size:24px}@media(max-width:767px){.mel-has-toggle .mel-nav-burger{display:block}.mel-has-toggle .mel-nav-list{display:none;flex-direction:column}.mel-has-toggle .mel-nav-check:checked+.mel-nav-burger+.mel-nav-list{display:flex}}\n");
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
