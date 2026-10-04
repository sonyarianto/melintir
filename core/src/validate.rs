use crate::document::Document;

pub const ALLOWED_WIDGETS: &[&str] = &[
    "heading", "text", "image", "button",
    "video", "divider", "spacer", "icon-box", "tabs",
];
pub const MAX_NODES: usize = 1000;
pub const MAX_DEPTH: usize = 6;

/// Returns list of error strings. Empty = valid (warnings allowed by caller).
pub fn validate(doc: &Document) -> Vec<String> {
    let mut errors = Vec::new();
    if doc.root.el_type != "container" && doc.root.el_type != "widget" {
        errors.push("root elType must be container or widget".to_string());
    }
    let n = doc.root.count();
    if n > MAX_NODES {
        errors.push(format!("too many nodes {n} (max {MAX_NODES})"));
    }
    if doc.root.depth() > MAX_DEPTH {
        errors.push(format!("max depth {} exceeded", MAX_DEPTH));
    }
    check_node(&doc.root, 0, &mut errors);
    errors
}

fn check_node(node: &crate::document::Node, depth: usize, errors: &mut Vec<String>) {
    if depth > MAX_DEPTH {
        errors.push(format!("node {} too deep", node.id));
        return;
    }
    if node.el_type == "widget" {
        let w = node.widget_type.as_deref().unwrap_or("");
        if !ALLOWED_WIDGETS.contains(&w) {
            errors.push(format!("disallowed widget: {w}"));
        }
    }
    for child in &node.elements {
        check_node(child, depth + 1, errors);
    }
}
