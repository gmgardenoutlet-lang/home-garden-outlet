# Catalogue fonts

These are the unchanged variable WOFF2 binaries previously served by Google Fonts:

- Manrope v20: `xn7gYHE41ni1AdIRggexSvfedN4.woff2` (Latin), `xn7gYHE41ni1AdIRggmxSvfedN62Zw.woff2` (Latin Extended).
- Cormorant Garamond v21: `co3bmX5slCNuHLi8bLeY9MK7whWMhyjYqXtKky2F7g.woff2` (Latin), `co3bmX5slCNuHLi8bLeY9MK7whWMhyjYp3tKky2F7i6C.woff2` (Latin Extended).

Sources: https://fonts.gstatic.com/s/manrope/v20/ and https://fonts.gstatic.com/s/cormorantgaramond/v21/ (append the respective filename).

Only the currently used normal weights are exposed by CSS: Manrope 400–800, Cormorant Garamond 600–700. Both subsets together include all Polish characters. No font outlines were changed. The included SIL Open Font License notices permit embedding and redistribution. Original notices: https://github.com/google/fonts/blob/main/ofl/manrope/OFL.txt and https://github.com/google/fonts/blob/main/ofl/cormorantgaramond/OFL.txt.

Only Latin files are preloaded; Latin Extended is discovered when Polish text needs it. `swap` retains visible text without JavaScript. The metric-adjusted Windows Arial/Georgia fallbacks are scoped to the four catalogue entry pages; other platforms fall back normally if those local faces are unavailable.
