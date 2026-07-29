"use client";
import React from "react";
import dynamic from "next/dynamic";
import { loadFull } from "tsparticles";
import type { Engine, Container } from "tsparticles-engine";


const Particles = dynamic(
  () => import("react-tsparticles").then((mod) => mod.default),
  { ssr: false }
);

type Props = { children?: React.ReactNode };
type CustomEngine = Engine & { checkVersion?: () => void };

export function Background({ children }: Props) {
  // tweak this value (px). Use a small value like 0.5, 1, or 2 to adjust perceived blur.

const particlesInit = async (engine: CustomEngine) => {
  if (typeof engine.checkVersion !== "function") {
    engine.checkVersion = () => {};
  }
  await loadFull(engine);
};

const particlesLoaded = async (container?: Container) => {
  try {
    const canvas = container?.canvas?.element as HTMLCanvasElement | undefined;
    if (canvas && canvas.style) {
      const dpr = typeof window !== "undefined" ? window.devicePixelRatio || 1 : 1;
      const effectiveBlur = Math.max(0, 2 / dpr);
      canvas.style.filter = `blur(${effectiveBlur}px)`;
      (canvas.style as CSSStyleDeclaration & { webkitFilter?: string }).webkitFilter =
        `blur(${effectiveBlur}px)`;
      canvas.style.transform = "translateZ(0)";
    }
  } catch {
    // fail silently
  }
};

  const particlesOptions = {
    fullScreen: { enable: true, zIndex: -10 },
    background: { color: "#000000" },
    fpsLimit: 60,
    interactivity: {
      events: {
        onHover: { enable: true, mode: "grab" },
        onClick: { enable: true, mode: "push" },
        resize: true,
      },
      modes: {
        grab: { distance: 120, links: { opacity: 0.4 } },
        push: { quantity: 4 },
      },
    },
    particles: {
      number: { value: 60, density: { enable: true, area: 800 } },
      color: { value: ["#3DB5A2", "#9EC840", "#27B0B0"] },
      shape: { type: "circle" },
      opacity: { value: 0.4 },
      size: { value: { min: 2, max: 5 } },
      links: {
        enable: true,
        distance: 150,
        color: "#3DB5A2",
        opacity: 0.3,
        width: 1,
      },
      move: {
        enable: true,
        speed: 0.15,
        direction: "none" as const,
        outModes: { default: "bounce" as const },
      },
    },
  };

  return (
    <div className="fixed inset-0 overflow-hidden">

      <div id="neb-bg-wrapper" className="absolute inset-0 -z-10 pointer-events-none">
        <Particles
          id="neb-bg"
          init={particlesInit}
          loaded={particlesLoaded}
          options={particlesOptions}
          className="h-full w-full"
        />
      </div>

      {/* Blurry floating object on top of particles */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute left-1/2 top-16 z-50 -translate-x-1/2 transform">
        <div className="w-80 h-48 bg-white/10 backdrop-blur-xl rounded-2xl border border-white/20 shadow-2xl" />
      </div>
      <div className="relative z-0">{children}</div>
      <style jsx>{`
        #neb-bg-wrapper canvas {
          transform: translateZ(0);
        }
      `}</style>
    </div>
  );
}