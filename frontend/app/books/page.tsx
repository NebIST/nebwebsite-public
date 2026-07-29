"use client";

import React, { useEffect, useMemo, useState } from "react";
import "../globals.css";
import "./books.css";

type Category = {
  id: string;
  year: number; // 0..5
  name: string;
  created_at?: number;
};

type Book = {
  id: string;
  year: number; // 0..5 (year of class)
  categoryId: string;

  name: string;
  author?: string;
  edition?: string;
  scholarYear?: string;
  state: string;

  price_cents: number;
  photo: string;

  created_at?: number;
  updated_at?: number;
};

type Store = {
  categories: Category[];
  books: Book[];
};

type StoreRaw = Record<string, unknown>;

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === "object" && v !== null && !Array.isArray(v);
}

function toStr(v: unknown, fallback = ""): string {
  return typeof v === "string" ? v : fallback;
}

function toNum(v: unknown, fallback = 0): number {
  if (typeof v === "number" && Number.isFinite(v)) return v;
  if (typeof v === "string") {
    const n = Number(v);
    return Number.isFinite(n) ? n : fallback;
  }
  return fallback;
}

function yearLabel(y: number) {
  if (y === 0) return "Other";
  if (y >= 1 && y <= 5) return `${y}º ano`;
  return String(y);
}

function formatEuroFromCents(cents: number) {
  const v = (Number.isFinite(cents) ? cents : 0) / 100;
  return `${v.toFixed(2)} €`;
}

async function tryFetchJson<T>(urls: string[]): Promise<{ url: string; data: T }> {
  let lastErr: unknown = null;
  for (const u of urls) {
    try {
      const res = await fetch(u, { cache: "no-store" });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      return { url: u, data: (await res.json()) as T };
    } catch (e) {
      lastErr = e;
    }
  }
  throw lastErr ?? new Error("Failed to fetch JSON");
}

function normalizeStore(raw: unknown): Store {
  if (!isRecord(raw)) return { categories: [], books: [] };

  const catsRaw = Array.isArray(raw["categories"]) ? (raw["categories"] as unknown[]) : [];
  const booksRaw = Array.isArray(raw["books"]) ? (raw["books"] as unknown[]) : [];

  const categories: Category[] = catsRaw
    .filter(isRecord)
    .map((c): Category | null => {
      const id = toStr(c["id"]).trim();
      const name = toStr(c["name"]).trim();
      const year = toNum(c["year"], -1);
      if (!id || !name || year < 0 || year > 5) return null;
      return {
        id,
        name,
        year,
        created_at: toNum(c["created_at"], 0) || undefined,
      };
    })
    .filter((x): x is Category => x !== null);

  const books = booksRaw
    .filter(isRecord)
    .map((b) => {
      const id = toStr(b["id"]).trim();
      const name = toStr(b["name"]).trim();
      const photo = toStr(b["photo"]).trim();
      const categoryId = toStr(b["categoryId"]).trim();
      const year = toNum(b["year"], -1);
      const price_cents = toNum(b["price_cents"], 0);

      const state = toStr(b["state"]).trim();

      if (!id || !name || !photo || !categoryId || year < 0 || year > 5 || !state) return null;

      return {
        id,
        year,
        categoryId,
        name,
        author: toStr(b["author"]).trim() || undefined,
        edition: toStr(b["edition"]).trim() || undefined,
        scholarYear: toStr(b["scholarYear"]).trim() || undefined,
        state,
        price_cents: Math.max(0, Math.round(price_cents)),
        photo,
        created_at: toNum(b["created_at"], 0) || undefined,
        updated_at: toNum(b["updated_at"], 0) || undefined,
      } as Book;
    })
    .filter((x): x is Book => x !== null) as Book[];

  return { categories, books };
}

export default function BooksPage() {
  const [store, setStore] = useState<Store>({ categories: [], books: [] });
  const [error, setError] = useState<string>("");
  const [baseDirUrl, setBaseDirUrl] = useState<string>(""); // resolved from fetched books.info.json

  // Filters
  const [year, setYear] = useState<number>(-1); // -1 = all
  const [categoryId, setCategoryId] = useState<string>(""); // "" = all
  const [query, setQuery] = useState<string>("");

  useEffect(() => {
    let cancelled = false;

    (async () => {
      setError("");

      const candidates: string[] = [];
      // page is /books => ../data/books/books.info.json
      candidates.push(new URL("../data/books/books.info.json", window.location.href).toString());
      candidates.push(new URL("/data/books/books.info.json", window.location.origin).toString());

      // basePath-safe fallback (same strategy as [`Activities`](../activities/page.tsx))
      const p = window.location.pathname;
      const prefix = p.replace(/\/books\/?$/, "");
      if (prefix !== p) {
        candidates.push(new URL(`${prefix}/data/books/books.info.json`, window.location.origin).toString());
      }

      try {
        const res = await tryFetchJson<unknown>(candidates);
        if (cancelled) return;

        const normalized = normalizeStore(res.data);
        setStore(normalized);

        // res.url is the JSON URL; baseDir becomes .../data/books/
        setBaseDirUrl(new URL("./", res.url).toString());
      } catch (e: unknown) {
        if (cancelled) return;
        const tried = candidates.join(" | ");
        setError(
          `Failed to load books (tried: ${tried}): ${String((e as Error)?.message ?? e)}`
        );
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const catById = useMemo(() => {
    const m = new Map<string, Category>();
    for (const c of store.categories) m.set(c.id, c);
    return m;
  }, [store.categories]);

  const categoriesForUi = useMemo(() => {
    const list = [...store.categories];
    list.sort((a, b) => (a.year - b.year) || a.name.localeCompare(b.name));
    return list;
  }, [store.categories]);

  const categoriesForSelectedYear = useMemo(() => {
    if (year === -1) return categoriesForUi;
    return categoriesForUi.filter((c) => c.year === year);
  }, [categoriesForUi, year]);

  // If user changes year and the selected category no longer matches, reset it.
  useEffect(() => {
    if (!categoryId) return;
    const c = catById.get(categoryId);
    if (!c) {
      setCategoryId("");
      return;
    }
    if (year !== -1 && c.year !== year) setCategoryId("");
  }, [year, categoryId, catById]);

  const booksSorted = useMemo(() => {
    const list = [...store.books];
    list.sort((a, b) => (b.updated_at ?? b.created_at ?? 0) - (a.updated_at ?? a.created_at ?? 0));
    return list;
  }, [store.books]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    const terms = q ? q.split(/\s+/g).filter(Boolean) : [];

    return booksSorted.filter((b) => {
      if (year !== -1 && b.year !== year) return false;
      if (categoryId && b.categoryId !== categoryId) return false;

      if (terms.length === 0) return true;

      const cat = catById.get(b.categoryId);
      const hay = [
        b.name,
        b.author ?? "",
        b.edition ?? "",
        b.scholarYear ?? "",
        b.state ?? "",
        cat?.name ?? "",
        yearLabel(b.year),
      ]
        .join(" ")
        .toLowerCase();

      return terms.every((t) => hay.includes(t));
    });
  }, [booksSorted, year, categoryId, query, catById]);

  const photoUrl = (fileName: string) => {
    if (baseDirUrl) return new URL(`photos/${encodeURIComponent(fileName)}`, baseDirUrl).toString();
    // fallback: absolute path (best-effort)
    return `/data/books/photos/${encodeURIComponent(fileName)}`;
  };

  return (
    <main className="booksPage" role="main" aria-label="Livros e Sebentas">
      <header className="booksHeader">
        <h1 className="booksTitle">Livros e Sebentas</h1>
        <p className="booksSubtitle">
          Procura por ano, cadeira, ou escreve o nome do livro para encontrar rapidamente.
        </p>
      </header>

      {/* Appealing space for your custom text (edit this block later) */}
      <section className="booksIntro" aria-label="Informação">
        <div className="booksIntroAccent" aria-hidden="true" />
        <div className="booksIntroContent">
          <h2 className="booksIntroTitle">LIVROS E SEBENTAS</h2>
          <p className="booksIntroText">
            Esta plataforma permite a compra e venda de livros e sebentas entre estudantes do Instituto
            Superior Técnico. O Núcleo de Estudantes de Bioengenharia (NEB) atua como uma ponte
            neste processo, facilitando a comunicação entre os interessados. Caso tenhas um livro ou sebenta a
            apanhar pó, esta é a oportunidade perfeita para lhe dar uma nova vida e ajudar um colega ao mesmo tempo!
            <br />
            Aproveita esta oportunidade de poderes participar numa iniciativa sustentável e económica,
            promovendo a reutilização de recursos dentro da nossa comunidade académica.
          </p>
          <div className="booksIntroActions">
            <p>
                Para comprar um livro, preenche este formulário e entraremos em contacto contigo o mais breve possível!
            </p>
            <a
              href="https://docs.google.com/forms/d/e/1FAIpQLScZLpE22BBBaHVdemjc_9SVJyXuKda763MXabsID-oPTaJXJw/viewform"
              target="_blank"
              rel="noopener noreferrer"
            >
              Comprar
            </a>
          </div>
          <div className="booksIntroActions">
            <p>
                Se pretendes vender um livro, preenche este formulário e entraremos em contacto contigo o mais breve possível!
            </p>
            <a
              href="https://docs.google.com/forms/d/e/1FAIpQLScUSGE0iCvGlKeXEUb2P2Eta9LKdreDVg10Ia_aoVZoptXZZA/viewform"
              target="_blank"
              rel="noopener noreferrer"
            >
              Vender
            </a>
          </div>
        </div>
      </section>

      <section className="booksControls" aria-label="Filtros e pesquisa">
        <div className="controlsGrid">
          <label className="control">
            <span className="controlLabel">Pesquisar</span>
            <input
              className="controlInput"
              type="search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Nome, autor, edição, descrição…"
              autoComplete="off"
            />
          </label>

          <label className="control">
            <span className="controlLabel">Ano</span>
            <select
              className="controlSelect"
              value={year}
              onChange={(e) => setYear(Number(e.target.value))}
            >
              <option value={-1}>Todos</option>
              <option value={0}>{yearLabel(0)}</option>
              <option value={1}>{yearLabel(1)}</option>
              <option value={2}>{yearLabel(2)}</option>
              <option value={3}>{yearLabel(3)}</option>
              <option value={4}>{yearLabel(4)}</option>
              <option value={5}>{yearLabel(5)}</option>
            </select>
          </label>

          <label className="control">
            <span className="controlLabel">Cadeira</span>
            <select
              className="controlSelect"
              value={categoryId}
              onChange={(e) => setCategoryId(e.target.value)}
            >
              <option value="">Todas</option>
              {categoriesForSelectedYear.map((c) => (
                <option key={c.id} value={c.id}>
                  {yearLabel(c.year)} — {c.name}
                </option>
              ))}
            </select>
          </label>

          <div className="controlMeta" aria-live="polite">
            {query.trim() || year !== -1 || categoryId ? (
              <>
                <div className="metaLine">
                  A mostrar <strong>{filtered.length}</strong> de{" "}
                  <strong>{booksSorted.length}</strong>
                </div>
                <button
                  type="button"
                  className="clearBtn"
                  onClick={() => {
                    setQuery("");
                    setYear(-1);
                    setCategoryId("");
                  }}
                >
                  Limpar filtros
                </button>
              </>
            ) : (
              <div className="metaLine">
                Total: <strong>{booksSorted.length}</strong>
              </div>
            )}
          </div>
        </div>
      </section>

      {error ? <div className="booksError">{error}</div> : null}

      {!error && filtered.length === 0 ? (
        <div className="booksEmpty">
          Sem resultados. Tenta pesquisar por outra palavra (ex: parte do nome do livro ou autor).
        </div>
      ) : (
        <section className="booksGrid" aria-label="Lista de livros">
          {filtered.map((b) => {
            const cat = catById.get(b.categoryId);
            const catLabel = cat ? `${yearLabel(cat.year)} — ${cat.name}` : "—";

            return (
              <article key={b.id} className="bookCard">
                <div className="bookMedia">
                  <img
                    className="bookImg"
                    src={photoUrl(b.photo)}
                    alt={`Foto do livro ${b.name}`}
                    loading="lazy"
                  />
                  <div className="bookPrice">{formatEuroFromCents(b.price_cents)}</div>
                </div>

                <div className="bookBody">
                  <h3 className="bookName">{b.name}</h3>

                  <div className="bookMeta">
                    <span className="pill">{yearLabel(b.year)}</span>
                    <span className="pill">{catLabel}</span>
                    {b.scholarYear ? <span className="pill">{b.scholarYear}</span> : null}
                  </div>

                  {(b.author || b.edition) ? (
                    <div className="bookMinor">
                      {b.author ? <span><strong>Autor:</strong> {b.author}</span> : null}
                      {b.edition ? <span><strong>Edição:</strong> {b.edition}</span> : null}
                    </div>
                  ) : null}

                  <p className="bookDesc">{b.state}</p>

                  <div className="bookFooter">
                    <span className="bookHint">
                      Para comprar: contacta o NEB no formulário acima.
                    </span>
                  </div>
                </div>
              </article>
            );
          })}
        </section>
      )}
    </main>
  );
}