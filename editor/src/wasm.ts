import { generateCssFallback } from './cssFallback';
import type { MelDoc } from './types';

type CoreApi = { generate_css: (json: string) => string; validate: (json: string) => string };

let core: CoreApi | null = null;
let useWasm = false;

declare global {
  interface Window {
    MelintirData?: { postId: number; restUrl: string; nonce: string; wasmUrl: string; wasmJs: string };
  }
}

/** Load wasm-pack `--target web` output, fallback to JS if missing. */
export async function initWasm(): Promise<boolean> {
  const wasmJs = window.MelintirData?.wasmJs;
  if (!wasmJs) return false;
  try {
    // wasm-pack web target exposes a default init() from melintir-core.js
    const mod: any = await import(/* @vite-ignore */ wasmJs);
    // Object form (newer wasm-pack); falls back to legacy string form.
    try {
      await mod.default?.({ module_or_path: window.MelintirData?.wasmUrl });
    } catch {
      await mod.default?.(window.MelintirData?.wasmUrl);
    }
    core = mod as CoreApi;
    useWasm = true;
    return true;
  } catch {
    useWasm = false;
    return false;
  }
}

export function isWasm(): boolean {
  return useWasm;
}

export function generateCss(doc: MelDoc): { css: string; ms: number } {
  const t0 = performance.now();
  const json = JSON.stringify(doc);
  let css = '';
  if (core) {
    try {
      css = core.generate_css(json);
    } catch {
      css = generateCssFallback(doc);
    }
  } else {
    css = generateCssFallback(doc);
  }
  return { css, ms: performance.now() - t0 };
}
