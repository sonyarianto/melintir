use serde::{Deserialize, Serialize};
use std::collections::HashMap;

/// Mirror of DATA_MODEL.md Node. Settings kept generic so new widgets
/// don't require a Rust redeploy — CSS only reads `style`.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Document {
    #[serde(default)]
    pub version: String,
    pub root: Node,
    #[serde(default)]
    pub globals: HashMap<String, serde_json::Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Node {
    pub id: String,
    #[serde(rename = "elType")]
    pub el_type: String,
    #[serde(rename = "widgetType", default)]
    pub widget_type: Option<String>,
    #[serde(default)]
    pub settings: HashMap<String, serde_json::Value>,
    #[serde(default)]
    pub style: Style,
    #[serde(default)]
    pub elements: Vec<Node>,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct Style {
    #[serde(default)]
    pub layout: HashMap<String, serde_json::Value>,
    #[serde(default)]
    pub typo: HashMap<String, serde_json::Value>,
    #[serde(rename = "customCss", default)]
    pub custom_css: Option<String>,
    #[serde(default)]
    pub tablet: Option<Box<Style>>,
    #[serde(default)]
    pub mobile: Option<Box<Style>>,
    #[serde(default)]
    pub responsive: HashMap<String, Box<Style>>,
}

impl Node {
    pub fn count(&self) -> usize {
        1 + self.elements.iter().map(|c| c.count()).sum::<usize>()
    }
    pub fn depth(&self) -> usize {
        1 + self
            .elements
            .iter()
            .map(|c| c.depth())
            .max()
            .unwrap_or(0)
    }
}
