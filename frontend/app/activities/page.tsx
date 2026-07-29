"use client";

import React, { useEffect, useMemo, useState } from "react";
import "../globals.css";
import "./activities.css";
import { withBasePath } from "../utils/withBasePath";

type Activity = {
  name: string;
  // Legacy single photo field (kept for backward compatibility)
  photoFileName: string;
  // Preferred multi-photo field
  photoFileNames: string[];
  description?: string;
  active: boolean;
  link?: string;
  listPriority: number;
  whenToGoLive: number; // unix seconds (0 = now)
  timeToLive: number; // unix seconds (0 = never)
  created_at: number;
  updated_at: number;
};

type ActivityRaw = { [key: string]: unknown };

function isVisible(a: Activity, nowSec: number) {
  // Must be active
  if (!a.active) return false;

  // Respect whenToGoLive:
  // - if > 0, only show when now >= whenToGoLive
  // - if 0 (or negative), treat as "show immediately"
  if (Number.isFinite(a.whenToGoLive) && a.whenToGoLive > 0 && nowSec < a.whenToGoLive) return false;

  // Respect TTL (0 = never expires)
  if (Number.isFinite(a.timeToLive) && a.timeToLive > 0 && nowSec >= a.timeToLive) return false;

  return true;
}

function photoUrl(fileName: string) {
  return new URL(`../data/activities/${encodeURIComponent(fileName)}`, window.location.href).toString();
}

function toNum(v: unknown, fallback = 0): number {
  if (typeof v === "number" && Number.isFinite(v)) return v;
  if (typeof v === "string") {
    const n = Number(v);
    return Number.isFinite(n) ? n : fallback;
  }
  return fallback;
}

function toStr(v: unknown, fallback = ""): string {
  return typeof v === "string" ? v : fallback;
}

function toBool(v: unknown, fallback = false): boolean {
  return typeof v === "boolean" ? v : fallback;
}

function toStrArray(v: unknown): string[] {
  if (!Array.isArray(v)) return [];
  return v
    .filter((x): x is string => typeof x === "string")
    .map((s) => s.trim())
    .filter(Boolean);
}

export default function Activities() {
  const [items, setItems] = useState<ActivityRaw[]>([]);
  const [error, setError] = useState<string>("");
  const [assetsBaseUrl, setAssetsBaseUrl] = useState<string>("");
  const [selected, setSelected] = useState<Activity | null>(null);
  const [selectedPhotoIndex, setSelectedPhotoIndex] = useState<number>(0);

  useEffect(() => {
    let cancelled = false;

    const candidates: string[] = [];
    candidates.push(new URL("../data/activities/activities.info.json", window.location.href).toString());
    candidates.push(new URL("/data/activities/activities.info.json", window.location.origin).toString());

    const p = window.location.pathname;
    const prefix = p.replace(/\/activities\/?$/, "");
    if (prefix !== p) {
      candidates.push(new URL(`${prefix}/data/activities/activities.info.json`, window.location.origin).toString());
    }

    async function tryFetchJson(urls: string[]) {
      let lastErr: unknown = null;
      for (const u of urls) {
        try {
          const res = await fetch(u, { cache: "no-store" });
          if (!res.ok) throw new Error(`HTTP ${res.status}`);
          const data: unknown = await res.json();
          return { url: u, data };
        } catch (e) {
          lastErr = e;
        }
      }
      throw lastErr ?? new Error("Unknown error");
    }

    tryFetchJson(candidates)
      .then((res) => {
        if (cancelled) return;

        if (!Array.isArray(res.data)) {
          throw new Error("Invalid JSON format (expected array).");
        }

        const rawArray = res.data.filter(
          (x): x is ActivityRaw => !!x && typeof x === "object" && !Array.isArray(x)
        );

        setAssetsBaseUrl(new URL("./", res.url).toString());
        setItems(rawArray);
        setError("");
      })
      .catch((e) => {
        if (cancelled) return;
        const tried = candidates.join(" | ");
        setError(`Failed to load activities (tried: ${tried}): ${String((e as Error)?.message ?? e)}`);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  // ESC to close + prevent background scroll
  useEffect(() => {
    if (!selected) return;

    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === "Escape") setSelected(null);
    };
    window.addEventListener("keydown", onKeyDown);

    return () => {
      document.body.style.overflow = prevOverflow;
      window.removeEventListener("keydown", onKeyDown);
    };
  }, [selected]);

  // Reset photo index when opening a different activity
  useEffect(() => {
    setSelectedPhotoIndex(0);
  }, [selected?.name, selected?.updated_at]);

  const visibleSorted = useMemo(() => {
    const nowSec = Math.floor(Date.now() / 1000);

    const normalized: Activity[] = items
      .map((raw): Activity => {
        const name = toStr(raw["name"]).trim();
        const legacyPhoto = toStr(raw["photoFileName"]).trim();
        const list = toStrArray(raw["photoFileNames"]);
        const photoFileNames = list.length > 0 ? list : legacyPhoto ? [legacyPhoto] : [];
        const photoFileName = legacyPhoto || photoFileNames[0] || "";

        const description = toStr(raw["description"]);
        const link = toStr(raw["link"]);

        return {
          name,
          photoFileName,
          photoFileNames,
          description,
          active: toBool(raw["active"], false),
          link,
          listPriority: toNum(raw["listPriority"], 0),
          whenToGoLive: toNum(raw["whenToGoLive"], 0),
          timeToLive: toNum(raw["timeToLive"], 0),
          created_at: toNum(raw["created_at"], 0),
          updated_at: toNum(raw["updated_at"], 0),
        };
      })
      .filter((a) => a.name !== "")
      .filter((a) => a.photoFileNames.length > 0);

    const visible = normalized.filter((a) => isVisible(a, nowSec));

    // Respect priority FIRST, then creation date
    visible.sort((a, b) => {
      const p = (b.listPriority ?? 0) - (a.listPriority ?? 0);
      if (p !== 0) return p;
      return (b.created_at ?? 0) - (a.created_at ?? 0);
    });

    return visible;
  }, [items]);

  const imageOnly = useMemo(() => visibleSorted.filter((a) => (a.description || "").trim() === ""), [visibleSorted]);
  const detailed = useMemo(() => visibleSorted.filter((a) => (a.description || "").trim() !== ""), [visibleSorted]);

  const srcForFile = (fileName: string) =>
    assetsBaseUrl ? new URL(encodeURIComponent(fileName), assetsBaseUrl).toString() : photoUrl(fileName);

  const primarySrcFor = (a: Activity) => srcForFile(a.photoFileNames[0] || a.photoFileName);

  return (
    <main className="activitiesPage" role="main" aria-label="Activities">
      <header className="activitiesHeader">
        <h1 className="activitiesTitle">Atividades</h1>
        <p className="activitiesSubtitle">
          Vê todas as atividades disponíveis do Núcleo de Engenharia Biológica!
          Aqui podes participar em Workshops, Quizzes, Atividades, Voluntariado e muito mais.
        </p>
      </header>

      <section className="schoolInfoSection" aria-labelledby="school-spotlight-title">
        <div className="schoolInfoGlow" aria-hidden="true" />

        <div className="schoolInfoInner">
          <div className="schoolInfoContent">
            <p className="schoolInfoEyebrow">Programa Escolas NEB</p>

            <h2 id="school-spotlight-title" className="schoolInfoTitle">
              Leva a Engenharia Biológica à tua escola!
            </h2>

            <p className="schoolInfoDescription">
              O Núcleo de Engenharia Biológica (NEB) promove sessões interativas e informativas em escolas,
              aproximando os estudantes do universo da Engenharia Biológica. Se é professor ou estudante de Engenharia Biológica, 
              descobre como podemos colaborar para inspirar os futuros Engenheiros.
            </p>

            <div className="schoolInfoActions">
              <a className="schoolInfoButton" href={withBasePath("/activities/schools")} rel="noopener noreferrer">
                Saber mais
              </a>
            </div>
          </div>

          <div className="schoolInfoImageWrap" aria-hidden="true">
            <img
              className="schoolInfoImage"
              src={withBasePath("/schools/bio_tree.webp")}
              alt=""
              loading="lazy"
            />
            <div className="schoolInfoImageFade" />
          </div>
        </div>
      </section>

      <section className="activitiesShowcase" aria-label="Atividades em destaque">
        <div className="activitiesShowcaseHeader">
          <p className="activitiesShowcaseEyebrow">Atividades NEB</p>
          <h2 className="activitiesShowcaseTitle">Explora as nossas atividades</h2>
        </div>

        {error ? <div className="activitiesError">{error}</div> : null}

        {!error && visibleSorted.length === 0 ? (
          <div className="activitiesEmpty">Não desanimes, vêm aí atividades em breve!</div>
        ) : (
          <>
            {imageOnly.length > 0 ? (
              <section className="activitiesMosaic" aria-label="Activities gallery">
                {imageOnly.map((a) => {
                  const hasLink = (a.link || "").trim().length > 0;

                  return (
                    <button
                      key={`img-${a.name}-${a.created_at}`}
                      className="activityTile"
                      type="button"
                      onClick={() => setSelected(a)}
                      aria-label={`Abrir atividade ${a.name}`}
                    >
                      <img className="tileImg" src={primarySrcFor(a)} alt={`Imagem da atividade ${a.name}`} loading="lazy" />
                      <div className="tileOverlay" aria-hidden="true">
                        <div className="tileTitle">{a.name}</div>
                        {hasLink ? <div className="tileHint">Saber mais</div> : null}
                      </div>
                    </button>
                  );
                })}
              </section>
            ) : null}

            {detailed.length > 0 ? (
              <section className="activitiesList" aria-label="Activities list">
                {detailed.map((a) => {
                  const hasLink = (a.link || "").trim().length > 0;

                  return (
                    <article key={`${a.name}-${a.created_at}`} className="activityCard hasDesc">
                      <div className="activityTop">
                        <h2 className="activityName">{a.name}</h2>

                        <div className="activityTopActions">
                          {hasLink ? (
                            <a className="activityLink" href={a.link} target="_blank" rel="noopener noreferrer">
                              Saber mais
                            </a>
                          ) : null}

                          <button className="activitySelect" type="button" onClick={() => setSelected(a)}>
                            Ver
                          </button>
                        </div>
                      </div>

                      <div className="activityBody">
                        <div className="activityDetails">
                          <p className="activityDescription">{a.description}</p>
                        </div>

                        <div className="activityMediaTight">
                          <img
                            className="activityImageTight"
                            src={primarySrcFor(a)}
                            alt={`Imagem da atividade ${a.name}`}
                            loading="lazy"
                          />
                        </div>
                      </div>
                    </article>
                  );
                })}
              </section>
            ) : null}
          </>
        )}
      </section>

      {selected ? (
        <div className="activityModal" role="dialog" aria-modal="true" aria-label={`Atividade ${selected.name}`}>
          <div className="activityModalBackdrop" onClick={() => setSelected(null)} />
          <div className="activityModalPanel">
            <div className="activityModalTop">
              <div className="activityModalTitle">{selected.name}</div>
              <button className="activityModalClose" type="button" onClick={() => setSelected(null)} aria-label="Fechar">
                Fechar
              </button>
            </div>

            <div className="activityModalBody">
              <div className="activityModalMedia">
                <img
                  className="activityModalImg"
                  src={srcForFile(selected.photoFileNames[selectedPhotoIndex] || selected.photoFileName)}
                  alt={`Imagem da atividade ${selected.name}`}
                />

                {selected.photoFileNames.length > 1 ? (
                  <div className="activityModalNav" aria-label="Navegação de fotos">
                    <button
                      type="button"
                      className="activityModalNavBtn"
                      onClick={() => setSelectedPhotoIndex((i) => Math.max(0, i - 1))}
                      disabled={selectedPhotoIndex <= 0}
                    >
                      Anterior
                    </button>

                    <div className="activityModalNavMeta">
                      {selectedPhotoIndex + 1} / {selected.photoFileNames.length}
                    </div>

                    <button
                      type="button"
                      className="activityModalNavBtn"
                      onClick={() =>
                        setSelectedPhotoIndex((i) => Math.min(selected.photoFileNames.length - 1, i + 1))
                      }
                      disabled={selectedPhotoIndex >= selected.photoFileNames.length - 1}
                    >
                      Seguinte
                    </button>
                  </div>
                ) : null}
              </div>

              <div className="activityModalInfo">
                {(selected.description || "").trim() ? (
                  <p className="activityModalDesc">{selected.description}</p>
                ) : (
                  <p className="activityModalDesc muted">Inscreve-te nesta atividade!</p>
                )}

                {(selected.link || "").trim() ? (
                  <a className="activityModalLink" href={selected.link} target="_blank" rel="noopener noreferrer">
                    Abrir link
                  </a>
                ) : null}
              </div>
            </div>
          </div>
        </div>
      ) : null}
    </main>
  );
}