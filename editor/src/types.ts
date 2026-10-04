export type ElType = 'container' | 'widget';
export type WidgetType =
  | 'heading' | 'text' | 'image' | 'button'
  | 'video' | 'divider' | 'spacer' | 'icon-box' | 'tabs' | 'form' | 'loop'
  | 'accordion' | 'gallery' | 'counter' | 'testimonial' | 'nav' | 'products'
  | 'product-title' | 'product-price' | 'product-cart' | 'product-rating' | 'product-image' | 'product-excerpt' | 'menu-cart' | 'woo-cart' | 'woo-checkout'
  | 'countdown' | 'carousel' | 'price-table' | 'social' | 'star-rating' | 'pattern-ref';

export interface MelNode {
  id: string;
  elType: ElType;
  widgetType?: WidgetType;
  settings: Record<string, any>;
  style: {
    layout?: { direction?: 'row' | 'column'; gap?: number; justify?: string; align?: string; bg?: string; padding?: number; radius?: number; shadow?: string; borderWidth?: number; borderStyle?: string; borderColor?: string; gradient?: { from?: string; to?: string; angle?: number } };
    typo?: { size?: number; weight?: number; color?: string };
    hover?: any;
    tablet?: any;
    mobile?: any;
    responsive?: { tablet?: any; mobile?: any };
  };
  elements: MelNode[];
}

export interface MelDoc {
  version: string;
  root: MelNode;
  globals: { colors: Record<string, string>; fonts?: Record<string, string>; breakpoints: { tablet: number; mobile: number } };
}

export const uid = () => Math.random().toString(36).slice(2, 10);

export function blankDoc(): MelDoc {
  return {
    version: '0.1.0',
    root: {
      id: 'root', elType: 'container', settings: {},
      style: { layout: { direction: 'column', gap: 16 } },
      elements: [
        { id: uid(), elType: 'widget', widgetType: 'heading', settings: { text: 'Hello Melintir', tag: 'h1' }, style: { typo: { size: 48, weight: 800 } }, elements: [] },
        { id: uid(), elType: 'widget', widgetType: 'text', settings: { html: '<p>Container-only builder. Fast by default.</p>' }, style: {}, elements: [] },
        { id: uid(), elType: 'widget', widgetType: 'button', settings: { text: 'Get started', url: '#' }, style: {}, elements: [] },
      ],
    },
    globals: { colors: { primary: '#2563eb', text: '#0f172a' }, breakpoints: { tablet: 1024, mobile: 767 } },
  };
}
