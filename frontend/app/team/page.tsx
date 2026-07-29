"use client";

import React, { useEffect, useMemo, useState } from "react";
import "../globals.css";
import "./team.css";
import { withBasePath } from "../utils/withBasePath";

type Person = {
  istid: string;
  name: string;
  role?: string;
  linkedinUrl?: string;
  cvUrl?: string;
  photoUrl?: string;
};

type Dept = {
  slug: string;
  name: string;
  description?: string;
  photoUrl?: string;
  presidentMessage?: string;
  people: Person[];
};

type ApiResp = {
  ok: boolean;
  organsComplete: boolean;
  departments: Dept[];
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

export default function AboutPage() {
  const [data, setData] = useState<ApiResp | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;

    (async () => {
      setError("");

      const candidates: string[] = [];
      candidates.push(new URL("../data/scripts/list-teams.php", window.location.href).toString());
      candidates.push(new URL(withBasePath("/data/scripts/list-teams.php"), window.location.origin).toString());

      const p = window.location.pathname;
      const prefix = p.replace(/\/team\/?$/, "");
      if (prefix !== p) {
        candidates.push(new URL(`${prefix}/data/scripts/list-teams.php`, window.location.origin).toString());
      }

      try {
        const resp = await tryFetchJson<ApiResp>(candidates);
        if (!cancelled) setData(resp);
      } catch (e: unknown) {
        if (!cancelled) setError(String((e as Error)?.message ?? e));
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const organsComplete = !!data?.organsComplete;
  const allDepts = data?.departments ?? [];

  const stats = useMemo(() => {
    const deptCount = allDepts.length;
    const memberCount = allDepts.reduce((acc, d) => acc + (d.people?.length ?? 0), 0);
    const withLinks = allDepts.reduce(
      (acc, d) => acc + (d.people ?? []).filter((p) => (p.linkedinUrl || "").trim() || (p.cvUrl || "").trim()).length,
      0
    );
    return { deptCount, memberCount, withLinks };
  }, [allDepts]);

  const organs = useMemo(() => {
    const pick = (slug: string) => allDepts.find((d) => d.slug === slug) ?? null;
    return {
      direcao: pick("presidency/direcao"),
      ag: pick("presidency/assembleia-geral"),
      cf: pick("presidency/conselho-fiscal"),
    };
  }, [allDepts]);

  const otherDepts = useMemo(() => {
    return allDepts.filter((d) => !d.slug.startsWith("presidency/"));
  }, [allDepts]);

  if (error) {
    return (
      <main className="teamPage" role="main" aria-label="Equipa">
        <div className="teamHero">
          <h1 className="teamTitle">Equipa</h1>
          <p className="teamSubtitle">Conhece as pessoas por detrás do NEB.</p>
        </div>
        <div className="teamError">{error}</div>
      </main>
    );
  }

  if (!data || !organsComplete) {
    return (
      <main className="teamPage" role="main" aria-label="Equipa">
        <div className="teamHero">
          <h1 className="teamTitle">Equipa</h1>
          <p className="teamSubtitle">Conhece as pessoas por detrás do NEB.</p>

          <div className="teamKpis" role="list" aria-label="Resumo da equipa">
            <div className="kpiCard" role="listitem">
              <div className="kpiLabel">Departamentos</div>
              <div className="kpiValue">—</div>
            </div>
            <div className="kpiCard" role="listitem">
              <div className="kpiLabel">Membros</div>
              <div className="kpiValue">—</div>
            </div>
            <div className="kpiCard" role="listitem">
              <div className="kpiLabel">Perfis com links</div>
              <div className="kpiValue">—</div>
            </div>
          </div>
        </div>

        <div className="teamEmpty">A equipa ainda está em construção</div>
      </main>
    );
  }

  const defaultAvatar = withBasePath("/team/default-person.svg");

  const PersonCard = ({ p }: { p: Person }) => {
    const img = p.photoUrl ? withBasePath(p.photoUrl) : defaultAvatar;
    const clickable = (p.linkedinUrl || "").trim().length > 0;

    const content = (
      <>
        <img className="personPhoto" src={img} alt={`Foto de ${p.name}`} loading="lazy" />
        <div className="personName">{p.name}</div>
        {p.role ? <div className="personRole">{p.role}</div> : null}
        <div className="personLinks">
          {p.cvUrl ? (
            <a className="pillLink" href={p.cvUrl} target="_blank" rel="noopener noreferrer">
              CV
            </a>
          ) : null}
          {p.linkedinUrl ? (
            <a className="pillLink" href={p.linkedinUrl} target="_blank" rel="noopener noreferrer">
              LinkedIn
            </a>
          ) : null}
        </div>
      </>
    );

    return clickable ? (
      <a
        className="personCard"
        href={p.linkedinUrl}
        target="_blank"
        rel="noopener noreferrer"
        aria-label={`Abrir LinkedIn de ${p.name}`}
      >
        {content}
      </a>
    ) : (
      <div className="personCard">{content}</div>
    );
  };

  const deptToneClass = (slug: string) => {
    if (slug === "presidency/direcao") return "dept--direcao";
    if (slug === "presidency/assembleia-geral") return "dept--ag";
    if (slug === "presidency/conselho-fiscal") return "dept--cf";
    if (slug.startsWith("presidency/")) return "dept--org";
    return "dept--dept";
  };

  const DeptSection = ({ d }: { d: Dept }) => (
    <section className={`dept ${deptToneClass(d.slug)}`} aria-label={d.name}>
      <div className="deptTop">
        {d.photoUrl ? (
          <img className="deptPhoto" src={withBasePath(d.photoUrl)} alt={`Foto do departamento ${d.name}`} />
        ) : null}

        <div className="deptTopText">
          <div className="deptTitleRow">
            <h2 className="deptTitle">{d.name}</h2>

            <div className="deptBadges" aria-label="Indicadores">
              <span className="deptBadge">{d.people.length} membro(s)</span>
              {d.slug.startsWith("presidency/") ? <span className="deptBadge deptBadge--org">Órgão</span> : null}
            </div>
          </div>

          {d.description ? <p className="deptDesc">{d.description}</p> : null}

          {d.slug === "presidency/direcao" && (d.presidentMessage || "").trim() ? (
            <div className="presMsg">
              <div className="presMsgTitle">Mensagem da Presidente</div>
              {(() => {
                const president = (d.people ?? []).find((p) => (p.role ?? "") === "Presidente") ?? null;
                if (!president) return null;

                const img = president.photoUrl ? withBasePath(president.photoUrl) : defaultAvatar;
                return (
                  <div style={{ display: "flex", alignItems: "center", gap: 10, marginTop: 8 }}>
                    <img
                      src={img}
                      alt={`Foto de ${president.name}`}
                      style={{ width: 64, height: 64, borderRadius: 12, objectFit: "cover" }}
                      loading="lazy"
                    />
                    <div>
                      <div style={{ fontWeight: 800 }}>{president.name}</div>
                      {president.role ? <div style={{ opacity: 0.9, fontSize: 13 }}>{president.role}</div> : null}
                    </div>
                  </div>
                );
              })()}
              <p className="presMsgBody" style={{ whiteSpace: "pre-wrap" }}>
                {d.presidentMessage}
              </p>
            </div>
          ) : null}
        </div>
      </div>

      <div className="peopleGrid" role="list">
        {d.people.map((p) => (
          <div key={`${d.slug}-${p.istid}`} role="listitem">
            <PersonCard p={p} />
          </div>
        ))}
      </div>
    </section>
  );

  return (
    <main className="teamPage" role="main" aria-label="Equipa">
      <div className="teamHero">
        <h1 className="teamTitle">Equipa</h1>
        <p className="teamSubtitle">Conhece as pessoas por detrás do NEB.</p>

        <div className="teamKpis" role="list" aria-label="Resumo da equipa">
          <div className="kpiCard" role="listitem">
            <div className="kpiLabel">Departamentos</div>
            <div className="kpiValue">{stats.deptCount}</div>
          </div>
          <div className="kpiCard" role="listitem">
            <div className="kpiLabel">Membros</div>
            <div className="kpiValue">{stats.memberCount}</div>
          </div>
          <div className="kpiCard" role="listitem">
            <div className="kpiLabel">Perfis com links</div>
            <div className="kpiValue">{stats.withLinks}</div>
          </div>
        </div>

        <div className="teamQuickLinks" role="navigation" aria-label="Atalhos">
          <a className="quickLink" href="#organs">
            Órgãos
          </a>
          <a className="quickLink" href="#departamentos">
            Departamentos
          </a>
        </div>
      </div>

      <section className="organs" id="organs" aria-label="Órgãos Sociais">
        <h2 className="blockTitle">Órgãos Sociais</h2>
        {organs.direcao ? <DeptSection d={organs.direcao} /> : null}
        {organs.ag ? <DeptSection d={organs.ag} /> : null}
        {organs.cf ? <DeptSection d={organs.cf} /> : null}
      </section>

      <section className="others" id="departamentos" aria-label="Departamentos">
        <h2 className="blockTitle">Departamentos</h2>

        {otherDepts.length > 0 ? (
          otherDepts.map((d) => <DeptSection key={d.slug} d={d} />)
        ) : (
          <div className="teamEmpty">Sem resultados.</div>
        )}
      </section>
    </main>
  );
}
