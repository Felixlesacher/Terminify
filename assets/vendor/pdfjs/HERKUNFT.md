# pdf.js 6.4.299 (Mozilla)

Zeichnet auf der Veranstaltungsseite die erste Seite eines PDF-Flyers. Die Dateien liegen im Plugin, es gibt keine Verbindung zu einem fremden Server.

- Quelle: https://github.com/mozilla/pdf.js/releases/tag/v6.4.299, Datei `pdfjs-6.4.299-legacy-dist.zip`
  (SHA-256 `c894bce53b2f4c3e13141e088c89cc051a1f7118ba4a2c02ec058798ae1303d1`)
- `pdf.js` ist `build/pdf.mjs`, `pdf.worker.js` ist `build/pdf.worker.mjs`, `LICENSE` ist `LICENSE` aus diesem Archiv.
  Die Dateien sind inhaltlich unverändert; nur die Endung ist `.js`, damit jeder Webserver sie als JavaScript ausliefert.
  Ihr letzter Kommentar verweist auf eine Quellkarte (`.map`), die nicht mitgeliefert wird; das betrifft nur die Entwicklerwerkzeuge des Browsers.
  SHA-256 `pdf.js`: `630de0bd0092cf2e9d46a23c68596b736699035e08cce4e9c85537c8eba0fc8e`,
  `pdf.worker.js`: `bb422f60804477de4a0b09091d8347a916a387755ed1cbad24ccaec1d09c78bf`.
- `wasm/` stammt aus `web/wasm/` und enthält nur die Bild- und Farbbausteine, die pdf.js bei Bedarf lädt:
  `jbig2.wasm` (JBIG2-Bilder, PDFium, BSD-Lizenz), `openjpeg.wasm` (JPEG-2000-Bilder, OpenJPEG, BSD-2-Clause)
  und `qcms_bg.wasm` (ICC-Farbprofile, qcms, MIT), dazu die Lizenzdateien `LICENSE_*`. Nicht übernommen: die
  JavaScript-Ausführung in PDFs (`quickjs-eval`) und die langsameren Ersatzdateien ohne WebAssembly.
  SHA-256 `wasm/jbig2.wasm`: `466f45c2a61a698152fb5400c27e56ff2ceb73dcb71fde3fc366a502308eaab0`,
  `wasm/openjpeg.wasm`: `95e5002597af0824004aa57b1900fe019715db389e37640b1e58395e42f00cc5`,
  `wasm/qcms_bg.wasm`: `663d86126d5f5fcb1c61490f94353e2a8375660b8c5498ab3ebab5a34b08800e`.
- `iccs/` stammt aus `web/iccs/`: das CMYK-Profil `CGATS001Compat-v2-micro.icc` (CC0) mit seiner `LICENSE`.
  SHA-256 `iccs/CGATS001Compat-v2-micro.icc`: `73e1ba37d2bad5bab2a964f40a9eed96209666efc067c3322626214bbef234a0`.
- Nicht übernommen: `web/cmaps/` (Zeichentabellen für nicht eingebettete chinesische, japanische und koreanische
  Schriften) und `web/standard_fonts/` (Ersatz für nicht eingebettete Standardschriften; pdf.js nimmt dann eine
  Systemschrift). Flyer mit eingebetteten Schriften, wie sie Canva, Word oder InDesign erzeugen, betrifft das nicht.
- Lizenz von pdf.js: Apache License 2.0 (siehe `LICENSE`). Sie ist mit der GPL ab Version 3 vereinbar; das Plugin
  steht unter „GPL-2.0-or-later“ und wird zusammen mit pdf.js unter GPL-3.0-or-later weitergegeben.
- Liegen Plugin-Dateien oder Mediathek auf einem anderen Server (CDN, ausgelagerte Uploads) ohne CORS-Freigabe,
  kann pdf.js den Flyer nicht laden; dann erscheint nur der Knopf „Flyer herunterladen“.
