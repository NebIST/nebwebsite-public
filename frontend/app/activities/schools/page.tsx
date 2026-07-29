"use client";

import React, { useState } from "react";
import "../../globals.css";
import "./schools.css";
import { withBasePath } from "../../utils/withBasePath";

type Tab = "teacher" | "student";

export default function Schools() {
    const [active, setActive] = useState<Tab>("teacher");

    return (
        <main className="schoolsPage" role="main" style={{ marginTop: "4vw" }}>

            {/* ─── Hero ──────────────────────────────────────── */}
            <section className="schoolsHero" aria-label="Programa Escolas NEB">
                <div className="schoolsHeroContent">
                    <p className="schoolsHeroEyebrow">Programa Escolas NEB</p>

                    <h1 className="schoolsHeroTitle">
                        A Engenharia Biológica<br />vai até ti 🌱
                    </h1>

                    <p className="schoolsHeroSub">
                        Queremos apresentar o mundo da Engenharia Biológica para os alunos do ensino secundário!
                        É simples: alunos de Engenharia Biológica visitam as suas antigas escolas e fazem uma
                        apresentação de 10 minutos para uma turma. Eles falam sobre as áreas do curso,
                        as oportunidades de carreira e como é ser estudante do Instituto Superior Técnico.
                        Esta iniciativa ajuda os alunos indecisos a tomarem a melhor decisão de sempre no curso
                        de Engenharia Biológica!
                        E acredite, estes 10 minutinhos podem fazer toda a diferença na decisão deles!
                    </p>

                    <div className="schoolsTabBar" role="tablist" aria-label="Seleciona o teu perfil">
                        <button
                            role="tab"
                            aria-selected={active === "teacher"}
                            className={`schoolsTab${active === "teacher" ? " active" : ""}`}
                            onClick={() => setActive("teacher")}
                        >
                            👩&#x200D;🏫 Sou Professor
                        </button>
                        <button
                            role="tab"
                            aria-selected={active === "student"}
                            className={`schoolsTab${active === "student" ? " active" : ""}`}
                            onClick={() => setActive("student")}
                        >
                            🧬 Sou Estudante
                        </button>
                    </div>
                </div>

                <div className="schoolsHeroImageWrap" aria-hidden="true">
                    <img src={withBasePath("/schools/bio_tree.webp")} alt="" className="schoolsHeroImg" loading="eager" />
                    <div className="schoolsHeroImgFade" />
                </div>
            </section>

            {/* ─── Teacher page ──────────────────────────────── */}
            {active === "teacher" && (
                <section className="teacherSection" aria-label="Para Professores">
                    <div className="teacherInner">

                        <div className="teacherText">
                            <p className="teacherEyebrow">&#x1F4DA; Para Professores</p>

                            <h2 className="teacherTitle">
                                Traga o futuro<br />para a sua sala
                            </h2>

                            <p className="teacherDesc">
                                Biológica nas escolas é uma iniciativa levada a cabo pelo Núcleo de Engenharia Biológica do Instituto Superior Técnico (NEBIST) e que tem como objetivo dar a conhecer melhor o curso de Engenharia Biológica aos alunos do ensino secundário em Portugal.
                                <br />
                                <br />
                                Desta forma, foi criada uma equipa de representantes, que irá às escolas inscritas apresentar, com auxílio de uma curta apresentação, os fundamentos do curso e partilhar a sua experiência pessoal acerca da vida académica.
                                <br />
                                <br />
                                Este projeto é uma ótima oportunidade tanto para os alunos, que deste modo ficam a conhecer o curso e a instituição subjacente, como para a escola.
                                <br />
                                <br />
                                Como estudantes do curso que já estiveram no lugar desses alunos, temos todo o gosto em dar o nosso contributo e conciliar uma manhã ou tarde para nos dirigir à sua escola no âmbito deste projeto.
                            </p>

                            <p className="teacherDesc" style={{ marginTop: 10 }}>
                                Cada sess&#xE3;o &#xE9; adaptada ao contexto da turma — basta partilhar o ano
                                escolar e os objetivos did&#xE1;ticos.
                            </p>

                            <a className="teacherCta" href="mailto:neb@tecnico.ulisboa.pt">
                                Solicitar Informações →
                            </a>
                            <a className="teacherCta" href="https://docs.google.com/forms/d/e/1FAIpQLScFFcpGVEI2P8StOe37by4OjHoheHbJ95rICYVSAw3Omcv5Lg/viewform">
                                Inscrever →
                            </a>
                        </div>

                        <div className="teacherCard" aria-label="O que oferecemos">
                            <img src={withBasePath("/schools/infocard.webp")} alt="Ilustração de um professor a apresentar um slide para uma turma" className="teacherCardImg" loading="lazy" />
                        </div>
                    </div>
                </section>
            )}

            {/* ─── Student page ──────────────────────────────── */}
            {active === "student" && (
                <section className="studentSection" aria-label="Para Estudantes">

                    <div className="studentHeroText">
                        <p className="studentEyebrow">&#x1F680; Para Estudantes de Engenharia Biológica</p>
                        <h2 className="studentTitle">Vem descobrir o que te espera!</h2>
                        <p className="studentSubtitle">
                            Uma pausa nas aulas normais para falar de ciência, a quem não conhece nada do teu curso. Inspira 
                            estudantes do secundário a fazer o que tu fazes!
                        </p>
                    </div>

                    <div className="studentPoints">

                        {/* Point 1 — text left, visual right */}
                        <div className="studentPoint">
                            <div className="studentPointText">
                                <span className="studentPointBadge studentBadge--green">Inspira</span>
                                <h3>O que &#xE9; a Engenharia Biol&#xF3;gica?</h3>
                                <p>
                                    Isto é o que vais explicar, o que é que um engenheiro biológico faz, de forma simples e direta. 
                                    Fala sobre as áreas de estudo, as oportunidades de carreira e o que é que mais te entusiasma no curso.
                                    A tua experiência pessoal é a melhor forma de mostrar o que é ser um estudante de Engenharia Biológica!
                                </p>
                            </div>
                            <div className="studentPointVisual studentVisual--green" aria-hidden="true">
                                <span className="studentEmoji">🧬</span>
                            </div>
                        </div>

                        {/* Point 2 — visual left, text right */}
                        <div className="studentPoint studentPoint--reversed">
                            <div className="studentPointVisual studentVisual--blue" aria-hidden="true">
                                <span className="studentEmoji">⁉️</span>
                            </div>
                            <div className="studentPointText">
                                <span className="studentPointBadge studentBadge--blue">Relaxa, estás a salvo!</span>
                                <h3>Não sei o que fazer nem o que dizer!</h3>
                                <p>
                                    O NEB tem toda uma preparação para te ajudar a preparar a tua sessão!
                                    A tua motivação e presença são o mais importante, o resto? Não te preocupes, fala
                                    da tua paixão pelo curso e do que mais gostas de fazer — o entusiasmo é contagiante!
                                </p>
                            </div>
                        </div>

                        {/* Point 3 — text left, visual right */}
                        <div className="studentPoint">
                            <div className="studentPointText">
                                <span className="studentPointBadge studentBadge--red">Tudo está contado</span>
                                <h3>E o tempo?</h3>
                                <p>
                                    Só precisas de ir uma vez à tua antiga escola.
                                    A sessão dura cerca de 10 minutos, mas o impacto que vais ter pode durar uma vida inteira!
                                </p>
                            </div>
                            <div className="studentPointVisual studentVisual--red" aria-hidden="true">
                                <span className="studentEmoji">⏰️</span>
                            </div>
                        </div>

                        {/* Point 4 — visual left, text right */}
                        <div className="studentPoint studentPoint--reversed">
                            <div className="studentPointVisual studentVisual--pink" aria-hidden="true">
                                <span className="studentEmoji">💪</span>
                            </div>
                            <div className="studentPointText">
                                <span className="studentPointBadge studentBadge--pink">Só tu!</span>
                                <h3>Sei ser um representante?</h3>
                                <p>
                                    Se frequentas o nosso curso, tens tempo livre e tens motivação para partilhar a tua experiência
                                    e falar com perdidos do secundário, então já tens qualificações a mais do que suficientes para seres um representante do NEB!
                                    O resto? É só descomplicar. Quando estiveres lá, apenas fala como se fosses tu o estudante do secundário!
                                </p>
                            </div>
                        </div>

                        <div className="studentInfoSection">
                            <h2 className="studentInfoSectionTitle">Sou estudante de Biológica! E agora?</h2>
                            <p className="studentInfoSectionText">
                                Vai haver formações para te preparares, ao decorrer do primeiro semestre, à qual a participação é obrigatória para poderes
                                representar o NEB nas escolas. Mas não te preocupes, não é nada de outro mundo, nem vais gastar tempo, é só para te ajudar a preparar a tua sessão e a tirar todas as dúvidas que possas ter.
                                <br /> <br /> Sê tu mesmo e representa o teu curso!
                            </p>
                            <a className="studentInfoCta" href="mailto:neb@tecnico.ulisboa.pt">
                                Contactar-nos
                            </a>
                        </div>
                    </div>
                </section>
            )}

        </main>
    );
}
