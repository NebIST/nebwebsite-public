"use client";

import React, { useEffect, useMemo, useState } from "react";
import "../globals.css";
import "./erasmus.css";
import { withBasePath } from "../utils/withBasePath";

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

export default function ErasmusPage() {
  const [data, setData] = useState<ApiResp | null>(null);
  const [error, setError] = useState<string>("");
  const [query, setQuery] = useState<string>("");
  const [countryFilter, setCountryFilter] = useState<string>("all");
  const [universityFilter, setUniversityFilter] = useState<string>("all");

  useEffect(() => {
    let cancelled = false;

    (async () => {
      setError("");

      const candidates: string[] = [];
      candidates.push(new URL("../data/scripts/list-erasmus.php", window.location.href).toString());
      candidates.push(new URL(withBasePath("/data/scripts/list-erasmus.php"), window.location.origin).toString());

      const p = window.location.pathname;
      const prefix = p.replace(/\/erasmus\/?$/, "");
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

  const countryOptions = useMemo(() => {
    const names = (data?.countries ?? []).map((c) => c.name).filter(Boolean);
    return names.sort((a, b) => a.localeCompare(b));
  }, [data]);

  const universityOptions = useMemo(() => {
    const countries = data?.countries ?? [];
    const out: Array<{ id: string; name: string; country: string }> = [];

    for (const c of countries) {
      if (countryFilter !== "all" && c.name !== countryFilter) continue;
      for (const u of c.universities ?? []) {
        out.push({ id: u.id, name: u.name, country: c.name });
      }
    }

    out.sort((a, b) => {
      const nc = a.country.localeCompare(b.country);
      if (nc !== 0) return nc;
      return a.name.localeCompare(b.name);
    });

    return out;
  }, [data, countryFilter]);

  useEffect(() => {
    if (universityFilter === "all") return;
    if (!universityOptions.some((u) => u.id === universityFilter)) {
      setUniversityFilter("all");
    }
  }, [universityFilter, universityOptions]);

  const filteredCountries = useMemo(() => {
    const countries = data?.countries ?? [];
    const terms = query
      .trim()
      .toLowerCase()
      .split(/\s+/)
      .filter(Boolean);

    return countries
      .filter((c) => countryFilter === "all" || c.name === countryFilter)
      .map((country) => {
        const universities = (country.universities ?? [])
          .filter((u) => universityFilter === "all" || u.id === universityFilter)
          .map((uni) => {
            const stories = (uni.stories ?? []).filter((story) => {
              if (terms.length === 0) return true;
              const haystack = [
                country.name,
                uni.name,
                story.studentName,
                story.summary ?? "",
                story.story,
              ]
                .join(" ")
                .toLowerCase();

              return terms.every((t) => haystack.includes(t));
            });

            return { ...uni, stories };
          })
          .filter((u) => (u.stories ?? []).length > 0);

        return { ...country, universities };
      })
      .filter((c) => (c.universities ?? []).length > 0);
  }, [data, query, countryFilter, universityFilter]);

  const universityEntries = useMemo(() => {
    const entries: UniversityEntry[] = [];

    for (const country of filteredCountries) {
      for (const university of country.universities ?? []) {
        entries.push({
          key: university.id || `${country.name}-${university.name}-${entries.length}`,
          country: country.name,
          universityId: university.id,
          universityName: university.name,
          universityPhotoUrl: university.photoUrl,
          stories: university.stories ?? [],
        });
      }
    }

    return entries;
  }, [filteredCountries]);

  const stats = useMemo(() => {
    const countries = filteredCountries;
    const universityCount = countries.reduce((acc, c) => acc + (c.universities?.length ?? 0), 0);
    const storyCount = universityEntries.reduce((acc, uni) => acc + (uni.stories?.length ?? 0), 0);

    return {
      countries: countries.length,
      universities: universityCount,
      stories: storyCount,
    };
  }, [filteredCountries, universityEntries]);

  return (
    <main className="erasmusPage" role="main" aria-label="Experiências Erasmus">
      <header className="erasmusHero">
        <div className="erasmusHeroGlow" aria-hidden="true" />

        <p className="erasmusEyebrow">NEB Global Stories</p>
        <h1 className="erasmusTitle">Experiências Erasmus</h1>
        <p className="erasmusSubtitle">
          Histórias reais de estudantes de Engenharia Biológica que viveram Erasmus em diferentes universidades.
          Inspira-te, descobre destinos e prepara a tua aventura.
        </p>

        <div className="erasmusParticipate">
          <p>Já viveste uma experiência Erasmus? </p>
          <a className="erasmusParticipateLink" href={withBasePath("/private/erasmus")}>
            Partilha a tua história e ajuda outros estudantes!
          </a>
        </div>

        <div className="erasmusStats" role="list" aria-label="Resumo Erasmus">
          <div className="erasmusStat" role="listitem">
            <span className="value">{stats.countries}</span>
            <span className="label">Países</span>
          </div>
          <div className="erasmusStat" role="listitem">
            <span className="value">{stats.universities}</span>
            <span className="label">Universidades</span>
          </div>
          <div className="erasmusStat" role="listitem">
            <span className="value">{stats.stories}</span>
            <span className="label">Histórias</span>
          </div>
        </div>
      </header>

      {!error && data ? (
        <section className="erasmusFilters" aria-label="Filtros Erasmus">
          <label className="filterField">
            <span>Pesquisar</span>
            <input
              type="search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Nome de aluno, universidade, história..."
            />
          </label>

          <label className="filterField">
            <span>País</span>
            <select value={countryFilter} onChange={(e) => setCountryFilter(e.target.value)}>
              <option value="all">Todos</option>
              {countryOptions.map((country) => (
                <option key={country} value={country}>
                  {country}
                </option>
              ))}
            </select>
          </label>

          <label className="filterField">
            <span>Universidade</span>
            <select value={universityFilter} onChange={(e) => setUniversityFilter(e.target.value)}>
              <option value="all">Todas</option>
              {universityOptions.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.name} ({u.country})
                </option>
              ))}
            </select>
          </label>
        </section>
      ) : null}

      {!error && data ? (
        <div className="erasmusFilterMeta">
          A mostrar {stats.stories} história(s), {stats.universities} universidade(s), {stats.countries} país(es).
        </div>
      ) : null}

      {!error && data && countryOptions.length > 0 ? (
        <section className="countryPagesPanel" aria-label="Páginas por país">
          <p className="countryPagesTitle">Páginas por país</p>
          <div className="countryPageLinks">
            {countryOptions.map((country) => (
              <a
                key={country}
                className="countryPageLink"
                href={withBasePath(`/erasmus/country?name=${encodeURIComponent(country)}`)}
              >
                {country}
              </a>
            ))}
          </div>
        </section>
      ) : null}

      {error ? <div className="erasmusError">{error}</div> : null}

      {!error && data && universityEntries.length === 0 ? (
        <div className="erasmusEmpty">
          {data.countries.length === 0
            ? "Ainda não existem experiências Erasmus aprovadas."
            : "Sem resultados para os filtros atuais."}
        </div>
      ) : null}

      {!error && data && universityEntries.length > 0 ? (
        <section className="storyPages" aria-label="Histórias Erasmus por universidade">
          {universityEntries.map((entry, index) => (
            <article
              key={entry.key}
              className="storyPage"
              aria-label={`Universidade ${entry.universityName}`}
            >
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
                    <p className="universityRegion">Região: {entry.country}</p>
                  </div>
                </header>

                <div className="storyPageNarrative">
                  {entry.stories.map((story, storyIndex) => (
                    <article
                      key={story.id || `${entry.universityId}-${story.studentName}`}
                      className={`storyCardReadable storyCardVariant${storyIndex % 3}`}
                    >
                      <div className="storyCardHeader">
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
