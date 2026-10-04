use crate::document::{Document, Node, Style};

/// Must stay in sync with includes/Renderer.php + editor/src/cssFallback.ts
/// Selector: .mel-{id}
pub fn generate_css(doc: &Document) -> String {
    let mut out = String::from(".mel-page{box-sizing:border-box}.mel-container{display:flex;flex-direction:column}\n.mel-loop{display:grid;gap:16px}.mel-cols-1{grid-template-columns:1fr}.mel-cols-2{grid-template-columns:repeat(2,1fr)}.mel-cols-3{grid-template-columns:repeat(3,1fr)}.mel-cols-4{grid-template-columns:repeat(4,1fr)}@media(max-width:767px){.mel-loop{grid-template-columns:1fr}}\n.mel-card{border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff}.mel-card-img img{width:100%;height:auto;display:block}.mel-card-title{font-size:18px;margin:12px 12px 4px}.mel-card-ex{font-size:14px;color:#475569;margin:0 12px 12px}\n.mel-gallery{display:grid;gap:12px}.mel-gcols-1{grid-template-columns:1fr}.mel-gcols-2{grid-template-columns:repeat(2,1fr)}.mel-gcols-3{grid-template-columns:repeat(3,1fr)}.mel-gcols-4{grid-template-columns:repeat(4,1fr)}.mel-gcols-5{grid-template-columns:repeat(5,1fr)}.mel-gcols-6{grid-template-columns:repeat(6,1fr)}@media(max-width:767px){.mel-gallery{grid-template-columns:repeat(2,1fr)}}\n.mel-gimg img{width:100%;height:auto;display:block;border-radius:8px}.mel-acc-item{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px}.mel-acc-item summary{cursor:pointer;padding:12px 16px;font-weight:600}.mel-acc-item summary+div{padding:0 16px 12px}.mel-counter{font-size:40px;font-weight:800}.mel-testimonial{border-left:4px solid #2563eb;padding:8px 16px;margin:0}.mel-testimonial blockquote{margin:0 0 8px;font-style:italic}.mel-tavatar{width:40px;height:40px;border-radius:50%;vertical-align:middle;margin-right:8px}.mel-tname{font-weight:700}.mel-trole{color:#64748b;margin-left:8px}\n");
    node_css(&doc.root, &mut out);
    if out.len() > 100 * 1024 {
        out.truncate(100 * 1024);
    }
    out
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
    if let Some(v) = style.layout.get("bg").and_then(|v| v.as_str()) {
        if !v.is_empty() {
            d.push_str(&format!("background:{v};"));
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
    if let Some(v) = style.typo.get("color").and_then(|v| v.as_str()) {
        if !v.is_empty() {
            d.push_str(&format!("color:{v};"));
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
}
