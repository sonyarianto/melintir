mod css;
mod document;
mod validate;

use wasm_bindgen::prelude::*;
use document::Document;

/// JSON string in -> CSS string out. Never throws: returns "" on bad input.
#[wasm_bindgen]
pub fn generate_css(doc_json: &str) -> String {
    let doc: Result<Document, _> = serde_json::from_str(doc_json);
    match doc {
        Ok(d) => css::generate_css(&d),
        Err(_) => String::new(),
    }
}

/// JSON string in -> JSON array string of errors out, e.g. `[]` or `["too many nodes"]`.
#[wasm_bindgen]
pub fn validate(doc_json: &str) -> String {
    let doc: Result<Document, _> = serde_json::from_str(doc_json);
    match doc {
        Ok(d) => serde_json::to_string(&validate::validate(&d)).unwrap_or_else(|_| "[]".to_string()),
        Err(e) => serde_json::to_string(&vec![format!("invalid JSON: {e}")]).unwrap(),
    }
}

/// Node count helper for editor perf badge.
#[wasm_bindgen]
pub fn stats(doc_json: &str) -> String {
    let doc: Result<Document, _> = serde_json::from_str(doc_json);
    match doc {
        Ok(d) => serde_json::json!({"nodes": d.root.count(), "depth": d.root.depth()}).to_string(),
        Err(_) => "{\"nodes\":0,\"depth\":0}".to_string(),
    }
}
