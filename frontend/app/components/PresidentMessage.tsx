"use client";

import React, { useEffect, useState } from "react";
import { withBasePath } from "../utils/withBasePath";

type Person = {
  name: string;
  role?: string;
  photoUrl?: string;
};

type Dept = {
  slug: string;
  presidentMessage?: string;
  people?: Person[];
};

type ApiResp = {
  ok: boolean;
  departments: Dept[];
};

async function tryFetchJson<T>(urls: string[]): Promise<T> {
  let lastErr: unknown = null;

  for (const url of urls) {
    try {
      const res = await fetch(url, { cache: "no-store" });
      if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
      return (await res.json()) as T;
    } catch (e) {
      lastErr = e;
    }
  }

  throw lastErr instanceof Error ? lastErr : new Error(String(lastErr));
}

export function PresidentMessage() {
  const [msg, setMsg] = useState<string>("");
  const [presidentName, setPresidentName] = useState<string>("");
  const [presidentPhotoUrl, setPresidentPhotoUrl] = useState<string>("");

  useEffect(() => {
    let cancelled = false;

    (async () => {
      const candidates: string[] = [];

      // Same strategy used in [`AboutPage`](frontend/app/team/page.tsx)
      candidates.push(new URL("../data/scripts/list-teams.php", window.location.href).toString());
      candidates.push(new URL(withBasePath("/data/scripts/list-teams.php"), window.location.origin).toString());

      // basePath-ish fallback
      const p = window.location.pathname;
      const prefix = p.replace(/\/$/, "");
      if (prefix !== p) {
        candidates.push(new URL(`${prefix}/data/scripts/list-teams.php`, window.location.origin).toString());
      }

      const resp = await tryFetchJson<ApiResp>(candidates);
      const direcao = (resp.departments ?? []).find((d) => d.slug === "presidency/direcao");
      const text = (direcao?.presidentMessage ?? "").trim();

      const president = (direcao?.people ?? []).find((p) => (p.role ?? "") === "Presidente") ?? null;
      const presName = (president?.name ?? "").trim();
      const presPhoto = (president?.photoUrl ?? "").trim();

      if (!cancelled) {
        setMsg(text);
        setPresidentName(presName);
        setPresidentPhotoUrl(presPhoto);
      }
    })().catch(() => {
      // best-effort: keep empty
    });

    return () => {
      cancelled = true;
    };
  }, []);

  if (!msg || !presidentName) return null;

  const defaultAvatar = withBasePath("/team/default-person.svg");
  const photoSrc = presidentPhotoUrl ? withBasePath(presidentPhotoUrl) : defaultAvatar;
  const alt = presidentName ? `Foto de ${presidentName}` : "Foto da Presidente";

  return (
    <div style={{ background: "#E6F3E1", padding: "2vw" }}>
      <h1 style={{ marginLeft: "50%", color: "#56BA85" }}>Mensagem da Presidente</h1>
      <div className="message-container">
        <img className="message-image" src={photoSrc} alt={alt} loading="lazy" />
        <div className="message-text">
          <div style={{ fontWeight: 800, marginBottom: "0.4rem", color: "#000000" }}>{presidentName}</div>
          <p style={{ whiteSpace: "pre-wrap" }}>{msg}</p>
        </div>
      </div>
    </div>
  );
}