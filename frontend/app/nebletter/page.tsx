"use client";

import React, { useEffect, useMemo, useState } from "react";
import "../globals.css";
import "./nebletter.css";
import { withBasePath } from "../utils/withBasePath";
import { shouldUsePdfThumbnails } from "../utils/pdfPreview";

type PdfItem = {
  name: string;
  url: string;
};

type ParsedPdf = PdfItem & {
  year: number;
  monthName: string;
  monthNum: number; // 1-12
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

const monthMap: Record<string, number> = {
  january: 1,
  february: 2,
  march: 3,
  april: 4,
  may: 5,
  june: 6,
  july: 7,
  august: 8,
  september: 9,
  october: 10,
  november: 11,
  december: 12,
};

function cap(s: string) {
  if (!s) return s;
  return s.charAt(0).toUpperCase() + s.slice(1).toLowerCase();
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function toPdfItem(value: unknown): PdfItem | null {
  if (!isRecord(value)) return null;

  const name = typeof value.name === "string" ? value.name : "";
  const url = typeof value.url === "string" ? value.url : "";
  if (!name || !url) return null;

  return { name, url };
}

function parsePdfName(name: string, url: string): ParsedPdf | null {
  // expected: YEAR_MONTH.pdf (e.g. 2025_june.pdf)
  const m = name.match(/^(\d{4})_([a-z]+)\.pdf$/i);
  if (!m) return null;

  const year = Number(m[1]);
  const monthName = String(m[2]).toLowerCase();
  const monthNum = monthMap[monthName] ?? 0;
  if (!Number.isFinite(year) || year < 2000 || year > 2100 || monthNum < 1 || monthNum > 12) return null;

  return { name, url, year, monthName, monthNum };
}

// Scholar year starts in September (9). Example: 2025_june => 2024/2025
function scholarYearStart(year: number, monthNum: number) {
  return monthNum >= 9 ? year : year - 1;
}

async function tryFetchJson<T>(urls: string[]): Promise<T> {
  let lastErr: unknown = null;
  for (const u of urls) {
    try {
      const res = await fetch(u, { cache: "no-store" });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      return (await res.json()) as T;
    } catch (e) {
      lastErr = e;
    }
  }
  throw lastErr ?? new Error("Failed to fetch JSON");
}

function errorMessage(e: unknown, fallback: string) {
  return e instanceof Error ? e.message : fallback;
}

export default function NebLetterPage() {
  const [items, setItems] = useState<ParsedPdf[]>([]);
  const [thumbs, setThumbs] = useState<Record<string, string>>({});
  const [error, setError] = useState<string>("");
  const [pdfjs, setPdfjs] = useState<PdfJsLike | null>(null);
  const [canRenderThumbnails, setCanRenderThumbnails] = useState<boolean | null>(null);

  // Load PDF.js only in the browser (prevents prerender/Node crashes like DOMMatrix missing)
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
      } catch (e: unknown) {
        if (!cancelled) setError(errorMessage(e, "Failed to initialize PDF renderer."));
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  // Fetch list of PDFs (basePath-safe, and safe from /nebletter relative resolution)
  useEffect(() => {
    let cancelled = false;

    (async () => {
      setError("");

      const candidates: string[] = [];
      candidates.push(new URL("../data/scripts/list-pdfs.php", window.location.href).toString());
      candidates.push(new URL("/data/scripts/list-pdfs.php", window.location.origin).toString());

      const p = window.location.pathname;
      const prefix = p.replace(/\/nebletter\/?$/, "");
      if (prefix !== p) {
        candidates.push(new URL(`${prefix}/data/scripts/list-pdfs.php`, window.location.origin).toString());
      }

      try {
        const raw = await tryFetchJson<unknown>(candidates);
        if (!Array.isArray(raw)) throw new Error("Invalid response from list-pdfs.php");

        const parsed: ParsedPdf[] = (raw as unknown[])
          .map(toPdfItem)
          .filter((v): v is PdfItem => v !== null)
          .map((p) => parsePdfName(p.name, p.url))
          .filter((v): v is ParsedPdf => v !== null)
          .sort((a, b) => (b.year - a.year) || (b.monthNum - a.monthNum));

        if (!cancelled) setItems(parsed);
      } catch (e: unknown) {
        if (!cancelled) setError(errorMessage(e, "Failed to load NebLetters"));
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  // Render thumbnails (cached per URL)
  useEffect(() => {
    let cancelled = false;

    if (!canRenderThumbnails || !pdfjs) return;

    items.forEach((pdf) => {
      if (thumbs[pdf.url]) return;

      (async () => {
        try {
          const loadingTask = pdfjs.getDocument(pdf.url);
          const pdfDoc = await loadingTask.promise;
          const page = await pdfDoc.getPage(1);
          const viewport = page.getViewport({ scale: 0.6 });

          const canvas = document.createElement("canvas");
          canvas.width = viewport.width;
          canvas.height = viewport.height;

          const ctx = canvas.getContext("2d");
          if (!ctx) return;

          await page.render({ canvasContext: ctx, viewport, canvas }).promise;

          const dataUrl = canvas.toDataURL("image/png");
          if (!cancelled) {
            setThumbs((prev) => ({ ...prev, [pdf.url]: dataUrl }));
          }
        } catch {
          // best-effort (no thumb)
        }
      })();
    });

    return () => {
      cancelled = true;
    };
  }, [items, thumbs, pdfjs, canRenderThumbnails]);

  const groups = useMemo(() => {
    const m = new Map<number, ParsedPdf[]>();
    for (const it of items) {
      const start = scholarYearStart(it.year, it.monthNum);
      const arr = m.get(start) ?? [];
      arr.push(it);
      m.set(start, arr);
    }

    return Array.from(m.entries())
      .map(([startYear, arr]) => ({
        startYear,
        endYear: startYear + 1,
        items: [...arr].sort((a, b) => (b.year - a.year) || (b.monthNum - a.monthNum)),
      }))
      .sort((a, b) => b.startYear - a.startYear);
  }, [items]);

  return (
    <main className="nebletterPage" role="main" aria-label="NebLetters">
      <header className="nebletterHeader">
        <h1 className="nebletterTitle">NebLetters</h1>

        <p className="nebletterSubtitle">
          A revista do NEB, feita para te manteres a par do que importa — com conteúdo leve, útil e com a nossa cara.
        </p>

        <ul className="nebletterBullets" aria-label="O que encontras na NebLetter">
          <li><strong>Entrevistas</strong> e histórias de quem inspira.</li>
          <li><strong>Eventos</strong> a não perder, oportunidades e novidades.</li>
          <li><strong>Rubricas</strong>, humor e o <strong>desafio do mês</strong> (sim, podes ver em grande!!).</li>
        </ul>

        <div className="nebletterCtas" role="group" aria-label="Ações NebLetter">
          <a
            href="https://eepurl.com/hqNj01"
            target="_blank"
            rel="noopener noreferrer"
            className="nebletterSubscribeLink"
            aria-label="Subscrever a NebLetter por email"
          >
            Subscreve por email
          </a>

          <a className="nebletterSecondaryLink" href="#arquivo" aria-label="Ir para o arquivo de NebLetters">
            Ver arquivo
          </a>
        </div>

        <p className="nebletterHint">
          Se não apanhaste a versão impressa, aqui tens tudo em digital.
        </p>
      </header>

      {error ? <div className="nebletterError">{error}</div> : null}

      {!error && groups.length === 0 ? (
        <div className="nebletterEmpty">Ainda não existem NebLetters publicados.</div>
      ) : (
        <div className="nebletterGroups" id="arquivo">
          {groups.map((g) => (
            <section
              key={g.startYear}
              className="sySection"
              aria-label={`Ano letivo ${g.startYear}/${g.endYear}`}
            >
              <h2 className="syTitle">
                {g.startYear}/{g.endYear}
              </h2>

              <div className="syGrid" role="list">
                {g.items.map((pdf) => {
                  const label = `${cap(pdf.monthName)} ${pdf.year}`;
                  const thumb = thumbs[pdf.url];

                  return (
                    <a
                      key={pdf.url}
                      href={pdf.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="pdfCard"
                      role="listitem"
                      aria-label={`Abrir ${label}`}
                    >
                      <div className="pdfThumb">
                        {thumb ? (
                          <img src={thumb} alt={pdf.name} loading="lazy" />
                        ) : canRenderThumbnails === false ? (
                          <div className="pdfThumbPlaceholder">Abrir PDF</div>
                        ) : (
                          <div className="pdfThumbPlaceholder">Loading…</div>
                        )}
                      </div>
                      <div className="pdfLabel">{label}</div>
                    </a>
                  );
                })}
              </div>
            </section>
          ))}
        </div>
      )}
    </main>
  );
}
