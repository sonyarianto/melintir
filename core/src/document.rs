use serde::{Deserialize, Deserializer, Serialize};
use serde::de::{MapAccess, SeqAccess, Visitor};
use std::collections::HashMap;
use std::fmt;
use std::marker::PhantomData;

/// PHP encodes empty maps as `[]`, so any map field may arrive as an empty
/// JSON array (or null). Accept map | [] | null, always producing a map.
fn de_map<'de, D>(d: D) -> Result<HashMap<String, serde_json::Value>, D::Error>
where
    D: Deserializer<'de>,
{
    struct MapOrEmpty(PhantomData<()>);
    impl<'de> Visitor<'de> for MapOrEmpty {
        type Value = HashMap<String, serde_json::Value>;
        fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
            f.write_str("a map, empty array, or null")
        }
        fn visit_map<M: MapAccess<'de>>(self, mut access: M) -> Result<Self::Value, M::Error> {
            let mut map = HashMap::new();
            while let Some((k, v)) = access.next_entry()? {
                map.insert(k, v);
            }
            Ok(map)
        }
        fn visit_seq<S: SeqAccess<'de>>(self, _seq: S) -> Result<Self::Value, S::Error> {
            Ok(HashMap::new())
        }
        fn visit_unit<E>(self) -> Result<Self::Value, E>
        where E: serde::de::Error {
            Ok(HashMap::new())
        }
        fn visit_none<E>(self) -> Result<Self::Value, E>
        where E: serde::de::Error {
            Ok(HashMap::new())
        }
    }
    d.deserialize_any(MapOrEmpty(PhantomData))
}

/// Same tolerance for the typed responsive-breakpoint map.
fn de_resp_map<'de, D>(d: D) -> Result<HashMap<String, Box<Style>>, D::Error>
where
    D: Deserializer<'de>,
{
    struct RespOrEmpty;
    impl<'de> Visitor<'de> for RespOrEmpty {
        type Value = HashMap<String, Box<Style>>;
        fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
            f.write_str("a map or empty array")
        }
        fn visit_map<M: MapAccess<'de>>(self, mut access: M) -> Result<Self::Value, M::Error> {
            let mut map = HashMap::new();
            while let Some((k, v)) = access.next_entry()? {
                map.insert(k, v);
            }
            Ok(map)
        }
        fn visit_seq<S: SeqAccess<'de>>(self, _seq: S) -> Result<Self::Value, S::Error> {
            Ok(HashMap::new())
        }
    }
    d.deserialize_any(RespOrEmpty)
}

/// Mirror of DATA_MODEL.md Node. Settings kept generic so new widgets
/// don't require a Rust redeploy — CSS only reads `style`.
#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Document {
    #[serde(default)]
    pub version: String,
    pub root: Node,
    #[serde(default, deserialize_with = "de_map")]
    pub globals: HashMap<String, serde_json::Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Node {
    pub id: String,
    #[serde(rename = "elType")]
    pub el_type: String,
    #[serde(rename = "widgetType", default)]
    pub widget_type: Option<String>,
    #[serde(default, deserialize_with = "de_map")]
    pub settings: HashMap<String, serde_json::Value>,
    #[serde(default)]
    pub style: Style,
    #[serde(default)]
    pub elements: Vec<Node>,
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct Style {
    #[serde(default, deserialize_with = "de_map")]
    pub layout: HashMap<String, serde_json::Value>,
    #[serde(default, deserialize_with = "de_map")]
    pub typo: HashMap<String, serde_json::Value>,
    #[serde(rename = "customCss", default)]
    pub custom_css: Option<String>,
    #[serde(default)]
    pub tablet: Option<Box<Style>>,
    #[serde(default)]
    pub mobile: Option<Box<Style>>,
    #[serde(default, deserialize_with = "de_resp_map")]
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
