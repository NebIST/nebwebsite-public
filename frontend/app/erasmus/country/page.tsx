"use client";

import React, { Suspense, useEffect, useMemo, useState } from "react";
import { useSearchParams } from "next/navigation";
import "../../globals.css";
import "../erasmus.css";
import { withBasePath } from "../../utils/withBasePath";

type Story = {
  id: string;
  studentName: string;
  studentPhotoUrl: string;
  story: string;
  summary?: string;
  updatedAt?: number;
};

type UniversityGroup = {
  id: string;
  name: string;
  country: string;
  photoUrl?: string;
  stories: Story[];
};

type CountryGroup = {
  name: string;
  universities: UniversityGroup[];
};

type ApiResp = {
  ok: boolean;
  countries: CountryGroup[];
  generatedAt?: number;
  error?: string;
};

type UniversityEntry = {
  key: string;
  country: string;
  universityId: string;
  universityName: string;
  universityPhotoUrl?: string;
  stories: Story[];
};

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
  throw lastErr ?? new Error("Failed to fetch");
}

function normCountry(value: string): string {
  return value.trim().toLowerCase();
}

function ErasmusCountryPageContent() {
  const searchParams = useSearchParams();
  const [data, setData] = useState<ApiResp | null>(null);
  const [error, setError] = useState<string>("");

  const requestedCountry = (searchParams.get("name") ?? "").trim();

  useEffect(() => {
    let cancelled = false;

    (async () => {
      setError("");

      const candidates: string[] = [];
      candidates.push(new URL("../../data/scripts/list-erasmus.php", window.location.href).toString());
      candidates.push(new URL(withBasePath("/data/scripts/list-erasmus.php"), window.location.origin).toString());

      const p = window.location.pathname;
      const prefix = p.replace(/\/erasmus\/country\/?$/, "");
      if (prefix !== p) {
        candidates.push(new URL(`${prefix}/data/scripts/list-erasmus.php`, window.location.origin).toString());
      }

      try {
        const resp = await tryFetchJson<ApiResp>(candidates);
        if (cancelled) return;

        if (!resp || resp.ok !== true || !Array.isArray(resp.countries)) {
          throw new Error("Invalid Erasmus payload");
        }

        setData(resp);
      } catch (e: unknown) {
        if (cancelled) return;
        setError(String((e as Error)?.message ?? e));
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const availableCountries = useMemo(() => {
    const names = (data?.countries ?? []).map((c) => c.name).filter(Boolean);
    return names.sort((a, b) => a.localeCompare(b));
  }, [data]);

  const selectedCountry = useMemo(() => {
    if (!data || requestedCountry === "") return null;
    const target = normCountry(requestedCountry);
    return (data.countries ?? []).find((country) => normCountry(country.name) === target) ?? null;
  }, [data, requestedCountry]);

  const universityEntries = useMemo(() => {
    const entries: UniversityEntry[] = [];
    if (!selectedCountry) return entries;

    for (const university of selectedCountry.universities ?? []) {
      const stories = university.stories ?? [];
      if (stories.length === 0) continue;

      entries.push({
        key: university.id || `${selectedCountry.name}-${university.name}-${entries.length}`,
        country: selectedCountry.name,
        universityId: university.id,
        universityName: university.name,
        universityPhotoUrl: university.photoUrl,
        stories,
      });
    }

    return entries;
  }, [selectedCountry]);

  const storyCount = useMemo(() => {
    return universityEntries.reduce((acc, uni) => acc + (uni.stories?.length ?? 0), 0);
  }, [universityEntries]);

  return (
    <main className="erasmusPage erasmusCountryPage" role="main" aria-label="Experiencias Erasmus por pais">
      <header className="erasmusHero countryHero">
        <a className="countryBackLink" href={withBasePath("/erasmus")}>
          Ver todas as experiencias
        </a>

        <p className="erasmusEyebrow">Country Spotlight</p>
        <h1 className="erasmusTitle">
          {selectedCountry
            ? `Erasmus em ${selectedCountry.name}`
            : requestedCountry
              ? `Pais nao encontrado: ${requestedCountry}`
              : "Escolhe um pais Erasmus"}
        </h1>
        <p className="erasmusSubtitle">
          Cada pais tem a sua propria pagina. Escolhe um pais abaixo para veres as universidades e historias
          publicadas.
        </p>

        {selectedCountry ? (
          <div className="erasmusStats" role="list" aria-label="Resumo do pais selecionado">
            <div className="erasmusStat" role="listitem">
              <span className="value">{selectedCountry.universities.length}</span>
              <span className="label">Universidades</span>
            </div>
            <div className="erasmusStat" role="listitem">
              <span className="value">{storyCount}</span>
              <span className="label">Historias</span>
            </div>
          </div>
        ) : null}
      </header>

      {!error && data && availableCountries.length > 0 ? (
        <section className="countryPagesPanel" aria-label="Paginas disponiveis por pais">
          <p className="countryPagesTitle">Seleciona um pais</p>
          <div className="countryPageLinks">
            {availableCountries.map((country) => {
              const isActive = normCountry(country) === normCountry(selectedCountry?.name ?? "");
              return (
                <a
                  key={country}
                  className={`countryPageLink${isActive ? " is-active" : ""}`}
                  href={withBasePath(`/erasmus/country?name=${encodeURIComponent(country)}`)}
                >
                  {country}
                </a>
              );
            })}
          </div>
        </section>
      ) : null}

      {error ? <div className="erasmusError">{error}</div> : null}

      {!error && data && requestedCountry === "" ? (
        <div className="erasmusEmpty">Escolhe um pais acima para abrir a pagina dedicada.</div>
      ) : null}

      {!error && data && requestedCountry !== "" && !selectedCountry ? (
        <div className="erasmusEmpty">Nao foi encontrado nenhum pais com esse nome.</div>
      ) : null}

      {!error && selectedCountry && universityEntries.length === 0 ? (
        <div className="erasmusEmpty">Este pais ainda nao tem historias Erasmus aprovadas.</div>
      ) : null}

      {!error && selectedCountry && universityEntries.length > 0 ? (
        <section className="storyPages" aria-label={`Historias Erasmus no pais ${selectedCountry.name}`}>
          {universityEntries.map((entry, index) => (
            <article key={entry.key} className="storyPage" aria-label={`Universidade ${entry.universityName}`}>
              <div className="storyPageInner">
                <header className="universityHero">
                  {entry.universityPhotoUrl ? (
                    <img
                      className="storyUniversityPhoto"
                      src={withBasePath(entry.universityPhotoUrl)}
                      alt={`Universidade ${entry.universityName}`}
                      loading="lazy"
                    />
                  ) : (
                    <div className="storyUniversityPlaceholder" aria-hidden="true">
                      🎓
                    </div>
                  )}

                  <div className="universityHeroText">
                    <p className="storyPageKicker">Universidade {index + 1} / {universityEntries.length}</p>
                    <h2 className="storyPageUniversity">{entry.universityName}</h2>
                    <p className="universityRegion">Regiao: {entry.country}</p>
                  </div>
                </header>

                <div className="storyPageNarrative">
                  {entry.stories.map((story, storyIndex) => (
                    <article
                      key={story.id || `${entry.universityId}-${story.studentName}`}
                      className={`storyCardReadable storyCardVariant${storyIndex % 3}`}
                    >
                      <div className="storyCardHeader">
                        <span className="storySpotlightNumber" aria-hidden="true">
                          {storyIndex + 1}
                        </span>

                        <img
                          className="storyPersonPhoto"
                          src={withBasePath(story.studentPhotoUrl)}
                          alt={`Foto de ${story.studentName}`}
                          loading="lazy"
                        />

                        <p className="storyPersonName">{story.studentName}</p>
                      </div>

                      <p className="storyPageText">{story.story}</p>
                    </article>
                  ))}
                </div>
              </div>
            </article>
          ))}
        </section>
      ) : null}
    </main>
  );
}

export default function ErasmusCountryPage() {
  return (
    <Suspense
      fallback={
        <main className="erasmusPage erasmusCountryPage" role="main" aria-label="Experiencias Erasmus por pais">
          <div className="erasmusEmpty">A carregar pagina do pais...</div>
        </main>
      }
    >
      <ErasmusCountryPageContent />
    </Suspense>
  );
}
