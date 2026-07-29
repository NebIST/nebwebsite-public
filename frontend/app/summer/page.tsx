"use client";
import React, { useEffect, useRef } from "react";
import Image from "next/image";
import "../globals.css";
import "./summer.css";
import { withBasePath } from "../utils/withBasePath";

export default function Summer() {
    const pathA = useRef<SVGPathElement | null>(null);
    const pathB = useRef<SVGPathElement | null>(null);
    const raf = useRef<number | null>(null);
    const start = useRef<number | null>(null);

    useEffect(() => {
        const width = 800;
        const height = 300;
        const pad = 900;
        const effectiveWidth = width + pad * 2;
        const baselineA = 150;
        const baselineB = 110;
        const segments = 12; // slightly fewer points is fine

        function catmullRom2bezier(points: { x: number; y: number }[], tension = 0.5) {
            if (points.length < 2) return "";
            let d = `M ${points[0].x} ${points[0].y} `;
            const n = points.length;
            for (let i = 0; i < n - 1; i++) {
                const p0 = i === 0 ? points[0] : points[i - 1];
                const p1 = points[i];
                const p2 = points[i + 1];
                const p3 = i + 2 < n ? points[i + 2] : p2;

                const cp1x = p1.x + ((p2.x - p0.x) / 6) * tension;
                const cp1y = p1.y + ((p2.y - p0.y) / 6) * tension;
                const cp2x = p2.x - ((p3.x - p1.x) / 6) * tension;
                const cp2y = p2.y - ((p3.y - p1.y) / 6) * tension;

                d += `C ${cp1x} ${cp1y} ${cp2x} ${cp2y} ${p2.x} ${p2.y} `;
            }
            return d;
        }

        function buildPoints(baseline: number, time: number, amp: number, freq: number, phaseShift = 0) {
            const pts: { x: number; y: number }[] = [];
            // gentler baseline wobble
            const baselineWobble = Math.sin(time * 0.45 + phaseShift) * 10;
            for (let i = 0; i <= segments; i++) {
                const t = i / segments;
                const x = -pad + t * effectiveWidth;
                const y =
                    baseline +
                    baselineWobble +
                    Math.sin(t * Math.PI * freq + time * 1.0 + phaseShift) * amp +
                    Math.sin(t * Math.PI * (freq * 2.0) + time * 0.6 + phaseShift * 1.2) * (amp * 0.45) +
                    Math.sin((t + time * 0.18) * Math.PI * (freq * 3.2)) * (amp * 0.2);
                pts.push({ x, y });
            }
            return pts;
        }

        function setInitialPaths() {
            const t0 = 0;
            const ptsA0 = buildPoints(baselineA, t0 * 0.8, 100, 1.4, 0.2);
            let dA0 = catmullRom2bezier(ptsA0, 0.55);
            dA0 += `L ${width + pad} ${height} L ${-pad} ${height} Z`;
            if (pathA.current) {
                pathA.current.setAttribute("d", dA0);
                pathA.current.setAttribute("transform", `translate(0,0)`);
            }

            const ptsB0 = buildPoints(baselineB, t0 * 1.1, 60, 2.2, 1.0);
            let dB0 = catmullRom2bezier(ptsB0, 0.6);
            dB0 += `L ${width + pad} ${height} L ${-pad} ${height} Z`;
            if (pathB.current) {
                pathB.current.setAttribute("d", dB0);
                pathB.current.setAttribute("transform", `translate(0,0)`);
            }
        }

        function frame(now: number) {
            if (!start.current) start.current = now;
            const t = (now - start.current) / 1000; // seconds

            // slower overall time scaling for both waves
            const timeA = t * 0.8;
            const timeB = t * 1.0;

            // Path A (slower, larger)
            const ptsA = buildPoints(baselineA, timeA * 0.9, 100, 1.4, 0.2);
            let dA = catmullRom2bezier(ptsA, 0.55);
            dA += `L ${width + pad} ${height} L ${-pad} ${height} Z`;
            if (pathA.current) {
                pathA.current.setAttribute("d", dA);
                const dxA = Math.sin(timeA * 0.35) * -220; // reduced horizontal drift
                const dyA = Math.cos(timeA * 0.25) * 10;   // reduced vertical translate
                pathA.current.setAttribute("transform", `translate(${dxA},${dyA})`);
            }

            // Path B (faster, textured but still slower than before)
            const ptsB = buildPoints(baselineB, timeB * 1.1, 60, 2.2, 1.0);
            let dB = catmullRom2bezier(ptsB, 0.6);
            dB += `L ${width + pad} ${height} L ${-pad} ${height} Z`;
            if (pathB.current) {
                pathB.current.setAttribute("d", dB);
                const dxB = Math.sin(timeB * 0.7 + 1.2) * 320; // reduced horizontal motion
                const dyB = Math.sin(timeB * 0.5 + 0.7) * 12;   // reduced vertical translate
                pathB.current.setAttribute("transform", `translate(${dxB},${dyB})`);
            }

            raf.current = requestAnimationFrame(frame);
        }

        setInitialPaths();
        raf.current = requestAnimationFrame(frame);
        return () => {
            if (raf.current) cancelAnimationFrame(raf.current);
        };
    }, []);

    return (
        <>
            <div className="hero-wrapper">
                <svg
                    className="hero-svg"
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 800 300"
                    preserveAspectRatio="xMidYMid meet"
                    role="img"
                    aria-label="Animated waves over title"
                >
                    <defs>
                        <linearGradient id="waveGradient" x1="0%" y1="0%" x2="100%" y2="0%">
                            <stop offset="0%" stopColor="#4fc3f7">
                                <animate attributeName="stop-color" values="#4fc3f7;#1976d2;#81d4fa;#4fc3f7" dur="4s" repeatCount="indefinite" />
                            </stop>
                            <stop offset="100%" stopColor="#1976d2">
                                <animate attributeName="stop-color" values="#1976d2;#0b3b6b;#4fc3f7;#1976d2" dur="4.5s" repeatCount="indefinite" />
                            </stop>
                        </linearGradient>


                        <mask id="waveMask" maskUnits="userSpaceOnUse" maskContentUnits="userSpaceOnUse">
                            <rect x="-900" y="0" width="2600" height="300" fill="black" />

                            <path ref={pathA} fill="white" d="" />
                            <path ref={pathB} fill="white" d="" />
                        </mask>
                    </defs>


                    <text
                        x="50%"
                        y="55%"
                        textAnchor="middle"
                        dominantBaseline="middle"
                        className="hero-base-text"
                    >
                        Summer Internships
                    </text>

                    <text
                        x="50%"
                        y="55%"
                        textAnchor="middle"
                        dominantBaseline="middle"
                        className="hero-wave-text"
                    >
                        Summer Internships
                    </text>
                </svg>

                <div className="hero-callout">
                    <p className="hero-callout-text">
                        Vais ficar no sofá este verão? <strong>Há um estágio à tua espera!</strong>
                    </p>
                </div>
            </div>

            <main className="summer-main">
                <p className="intro-paragraph">
                    O <strong>IST Summer Internships</strong> é uma iniciativa dos Núcleos de Estudantes do Instituto Superior Técnico, em parceria com empresas, que oferece aos alunos experiências práticas durante o verão e facilita a transição para o mercado de trabalho.
                </p>

                <div className="block-obj">
                    <div className="block-column">
                        <h3>Objetivos</h3>
                        <p>O programa proporciona aos estudantes experiências práticas e competências complementares à formação académica, facilitando a integração no mercado de trabalho.</p>
                    </div>

                    <div className="block-column">
                        <h3>Creditação</h3>
                        <p>Após concluíres o estágio podes obter um certificado de participação e, mediante avaliação, solicitar creditação nas unidades Portefólio I e II (MEBiol), conforme os requisitos oficiais.</p>
                    </div>

                    <Image src={withBasePath("/summer/summerflier.png")} alt="Summer flier" className="block-obj-img" width={400} height={300} />
                </div>

                <section
                    className="summer-actions"
                    id="summer-more-info"
                    role="region"
                    aria-labelledby="summer-actions-title"
                >
                    <div className="summer-actions-box">
                        <div className="summer-accent-square" aria-hidden="true"></div>

                        <div className="summer-actions-content">
                            <h2 id="summer-actions-title" className="summer-actions-title">
                                Candidata‑te — encontra o teu estágio este verão
                            </h2>

                            <p className="summer-actions-desc">
                                Encontra e candidata‑te a ofertas de estágio na plataforma <strong>IST JobBanks</strong>. Entra com a tua conta Fénix para descobrires novas oportunidades para ti.
                            </p>

                            <div className="callout-actions" role="group" aria-label="Ações de estágio">
                                <a
                                    className="btn btn-primary"
                                    href="https://ist-csm.symplicity.com"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    aria-label="Abrir IST JobBanks — Ver oportunidades"
                                >
                                    Ver oportunidades
                                </a>

                                <a
                                    className="btn btn-secondary"
                                    href="https://careercenter.tecnico.ulisboa.pt/job-bank/"
                                    aria-label="Ir para mais informações sobre estágios"
                                >
                                    Mais informações
                                </a>
                            </div>
                        </div>
                    </div>
                </section>
            </main>
        </>
    );
}