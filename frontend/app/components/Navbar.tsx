"use client";

import React, { useEffect, useRef, useState } from "react";
import Link from "next/link";
import Image from "next/image";
import "./navbar.css";
import { withBasePath } from "../utils/withBasePath";

export default function Navbar() {
  const [open, setOpen] = useState(false);
  const navRef = useRef<HTMLElement | null>(null);
  const toggleRef = useRef<HTMLButtonElement | null>(null);

  const staticHref = (path: string) => withBasePath(path.endsWith("/") ? path : `${path}/`);

  /* Close on Escape */
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  /* Close when clicking/tapping outside (mobile-safe) */
  useEffect(() => {
    const onPointerDown = (e: PointerEvent) => {
      if (!navRef.current?.contains(e.target as Node)) {
        setOpen(false);
      }
    };

    document.addEventListener("pointerdown", onPointerDown);
    return () =>
      document.removeEventListener("pointerdown", onPointerDown);
  }, []);

  return (
    <nav
      ref={navRef}
      className={`navbar ${open ? "is-open" : ""}`}
      role="navigation"
      aria-label="Main site navigation"
    >
      <div className="navbar-container">
        <Link href="/" className="navbar-logo" aria-label="NEB home">
          <Image
            src={withBasePath("/logohorizontal.png")}
            alt="NEB Logo"
            width={150}
            height={45}
            priority
            style={{
              objectFit: "contain",
              height: "auto",
              maxHeight: "42px",
              display: "block",
            }}
          />
        </Link>

        <ul
          id="navbar-menu"
          className="navbar-menu"
          role="menu"
          aria-hidden={!open}
        >
          <li role="none"><a role="menuitem" href="https://bio-drive.onrender.com">Repositório</a></li>
          <li role="none"><a role="menuitem" href={staticHref("/summer")}>Summer Internship</a></li>
          <li role="none"><a role="menuitem" href={staticHref("/nebletter")}>NEBLETTER</a></li>
          <li role="none"><a role="menuitem" href={staticHref("/books")}>Livros e Sebentas</a></li>
          <li role="none"><a role="menuitem" href={staticHref("/merch")}>Merch</a></li>
          <li role="none"><a role="menuitem" href={staticHref("/activities")}>Atividades</a></li>
          <li role="none"><a role="menuitem" href={staticHref("/erasmus")}>Erasmus</a></li>
          <li role="none"><a role="menuitem" href={staticHref("/team")}>Sobre Nós</a></li>
        </ul>

        <div className="navbar-actions">
          <Link
            href="/private"
            className="navbar-admin"
            aria-label="Área de Admin"
            title="Área de Admin"
          >
            <svg
              className="navbar-adminIcon"
              viewBox="0 0 24 24"
              aria-hidden="true"
              focusable="false"
            >
              <path
                d="M12 12.2c2.62 0 4.75-2.13 4.75-4.75S14.62 2.7 12 2.7 7.25 4.83 7.25 7.45 9.38 12.2 12 12.2Zm0-1.8c-1.63 0-2.95-1.32-2.95-2.95S10.37 4.5 12 4.5s2.95 1.32 2.95 2.95S13.63 10.4 12 10.4Zm0 2.3c-4.1 0-7.3 2.15-7.3 4.95V20c0 .5.4.9.9.9h12.8c.5 0 .9-.4.9-.9v-2.35c0-2.8-3.2-4.95-7.3-4.95Zm-5.4 6.4v-1.45c0-1.48 2.17-3.15 5.4-3.15s5.4 1.67 5.4 3.15v1.45H6.6Z"
                fill="currentColor"
              />
            </svg>
          </Link>

          <button
            ref={toggleRef}
            className="navbar-toggle"
            aria-controls="navbar-menu"
            aria-expanded={open}
            aria-label={open ? "Close menu" : "Open menu"}
            onClick={(e) => {
              e.stopPropagation();
              setOpen((v) => !v);
            }}
          >
            <span className="sr-only">Menu</span>
            <span className="bar" />
            <span className="bar" />
            <span className="bar" />
          </button>
        </div>
      </div>
    </nav>
  );
}
