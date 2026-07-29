import { useEffect, useState } from "react";
import { withBasePath } from "../utils/withBasePath";
import { shouldUsePdfThumbnails } from "../utils/pdfPreview";

type NebLetter = {
  name: string;
  url: string;
  year: string;
  month: string;
};

type PdfViewportLike = {
  width: number;
  height: number;
};

type PdfRenderParamsLike = {
  canvasContext: CanvasRenderingContext2D;
  viewport: PdfViewportLike;
  canvas: HTMLCanvasElement;
};

type PdfPageLike = {
  getViewport: (opts: { scale: number }) => PdfViewportLike;
  render: (params: PdfRenderParamsLike) => { promise: Promise<unknown> };
};

type PdfDocumentLike = {
  getPage: (pageNumber: number) => Promise<PdfPageLike>;
};

type PdfLoadingTaskLike = {
  promise: Promise<PdfDocumentLike>;
};

type PdfJsLike = {
  GlobalWorkerOptions: { workerSrc: string };
  getDocument: (src: string) => PdfLoadingTaskLike;
};

function formatPdfName(name: string) {
  // Example: "2025_june.pdf" => "June 2025"
  const match = name.match(/^(\d{4})_([a-zA-Z]+)\.pdf$/);
  if (match) {
    const [, year, month] = match;
    return `${month.charAt(0).toUpperCase() + month.slice(1).toLowerCase()} ${year}`;
  }
  return name;
}

function NebLetterGallery() {
  const [pdfs, setPdfs] = useState<NebLetter[]>([]);
  const [thumbnails, setThumbnails] = useState<{ [url: string]: string }>({});
  const [pdfjs, setPdfjs] = useState<PdfJsLike | null>(null);
  const [canRenderThumbnails, setCanRenderThumbnails] = useState<boolean | null>(null);

  useEffect(() => {
    let cancelled = false;

    if (!shouldUsePdfThumbnails()) {
      setCanRenderThumbnails(false);
      return;
    }

    setCanRenderThumbnails(true);

    (async () => {
      try {
        const mod: unknown = await import("pdfjs-dist/legacy/build/pdf");
        const api = mod as PdfJsLike;
        api.GlobalWorkerOptions.workerSrc = withBasePath("/pdf.worker.mjs");
        if (!cancelled) setPdfjs(api);
      } catch {
        if (!cancelled) setCanRenderThumbnails(false);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    fetch("data/scripts/list-pdfs.php?count=6")
      .then((res) => res.json())
      .then((data) => setPdfs(data));
  }, []);

  useEffect(() => {
    if (!canRenderThumbnails || !pdfjs) return;

    pdfs.forEach((pdf) => {
      if (thumbnails[pdf.url]) return; // already loaded
      const renderThumbnail = async () => {
        try {
          const loadingTask = pdfjs.getDocument(pdf.url);
          const pdfDoc = await loadingTask.promise;
          const page = await pdfDoc.getPage(1);
          const viewport = page.getViewport({ scale: 0.6 }); // increased scale for better resolution
          const canvas = document.createElement("canvas");
          canvas.width = viewport.width;
          canvas.height = viewport.height;
          const context = canvas.getContext("2d");
          await page.render({ canvasContext: context!, viewport, canvas }).promise;
          setThumbnails((prev) => ({
            ...prev,
            [pdf.url]: canvas.toDataURL(),
          }));
        } catch {
          // fallback: no thumbnail
        }
      };
      renderThumbnail();
    });
  }, [pdfs, thumbnails, canRenderThumbnails, pdfjs]);

  return (
    <div style={{ display: "flex", flexDirection: "column", alignItems: "center" }}>
      <div
        style={{
          display: "block",
          overflowX: "auto",
          whiteSpace: "nowrap",
          width: "100%",
          paddingBottom: 32,
        }}
      >
        {pdfs.map((pdf) => (
          <a
            key={pdf.url}
            href={pdf.url}
            target="_blank"
            rel="noopener noreferrer"
            style={{
              display: "inline-flex",
              flexDirection: "column",
              alignItems: "center",
              width: 320,
              background: "none",
              border: "none",
              boxShadow: "none",
              padding: 0,
              textDecoration: "none",
              color: "inherit",
              transition: "transform 0.2s",
              position: "relative",
              margin: "0 1vw",
              verticalAlign: "top",
            }}
            className="about-card"
          >
            <div
              style={{
                width: 300,
                height: 420,
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                borderRadius: 16,
                overflow: "hidden",
                boxShadow: "0 2px 8px rgba(0,0,0,0.06)",
                background: "none",
              }}
            >
              {thumbnails[pdf.url] ? (
                <img
                  src={thumbnails[pdf.url]}
                  alt={pdf.name}
                  style={{
                    width: "100%",
                    height: "100%",
                    objectFit: "contain",
                    display: "block",
                    borderRadius: 12,
                  }}
                />
                ) : canRenderThumbnails === false ? (
                <div
                  style={{
                    display: "flex",
                    flexDirection: "column",
                    alignItems: "center",
                    justifyContent: "center",
                    gap: 12,
                    padding: 24,
                    textAlign: "center",
                  }}
                >
                  <span style={{ color: "#56BA85", fontSize: 22, fontWeight: 700 }}>Abrir PDF</span>
                  <span style={{ color: "#666", fontSize: 16, lineHeight: 1.4 }}>
                    Pré-visualização indisponível neste dispositivo.
                  </span>
                </div>
                ) : (
                  <span style={{ color: "#aaa", fontSize: 22 }}>Loading...</span>
              )}
            </div>
            <div
              style={{
                marginTop: 18,
                borderRadius: 12,
                padding: "10px 24px",
                boxShadow: "0 2px 8px rgba(0,0,0,0.10)",
                fontSize: 18,
                textAlign: "center",
                color: "#ffffffff",
                fontWeight: 600,
                minWidth: 180,
              }}
            >
              {formatPdfName(pdf.name)}
            </div>
          </a>
        ))}
      </div>
    </div>
  );
}

export default NebLetterGallery;