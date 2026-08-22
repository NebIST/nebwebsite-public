"use client";
import "./globals.css";
import dynamic from "next/dynamic";
import Image from "next/image";
import { withBasePath } from "./utils/withBasePath";

const NebLetterGallery = dynamic(() => import("./components/NebLetter"), { ssr: false });
import { PresidentMessage } from "./components/PresidentMessage";

//Calculates Automatically how old NEB is
export default function RootLayout() {
  const startDate = new Date(2005, 8, 1);
  const now = new Date();
  let years = now.getFullYear() - startDate.getFullYear();
  if (now.getMonth() < startDate.getMonth() || (now.getMonth() === startDate.getMonth() && now.getDate() < startDate.getDate())) years -= 1;



  return (
    //Script for how long since birth year of NEB
    <>
      <main className="front-page">
        <div className="front-container">
            <Image className="Photo" src={withBasePath("/frontPage/sologocorvertical.png")} alt="Logo" width={300} height={420} />
          <span className="front-text">
            <h1>NEBIST</h1>
            <h3>Núcleo de Engenharia Biológica Instituto Superior Técnico</h3>
          </span>
        </div>
        <a href="#about" className="down-arrow" aria-label="Scroll down">
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"
                strokeLinecap="round" strokeLinejoin="round" xmlns="http://www.w3.org/2000/svg">
            <path d="M19 12l-7 7-7-7"></path>
          </svg>
        </a>
      </main>

      {/* About Part Front Page */}
      <div>
        <h1 id="about" className="about-title">Quem somos</h1>

        <div className="about-container">
          {/* Anos de presença*/}
          <div className="about-card" tabIndex={0}>
            <Image src={withBasePath("/frontPage/aboutImages/about_year.jpg")} alt="Setembro 2005" width={300} height={420}/>
            <div className="overlay top" aria-hidden="false">
              <h3>{years} Anos de História</h3>
              <p className="more">A acolher Estudantes de Engenharia Biológica como <b>família</b></p>
            </div>
          </div>
          {/* NebLetter */}
          <div className="about-card" tabIndex={0}>
            <Image src={withBasePath("/frontPage/aboutImages/about_letter.jpg")} alt="Setembro 2005" className="nebletter"width={300} height={420}/>
            <div className="overlay top" aria-hidden="false">
              <h3 style={{marginLeft: "2vw", marginTop: "3vw", textAlign: "center"}}>Centenas de NebLetters Feitos</h3>
              <p className="more" style={{marginTop: "10%"}}>Para manter a comunidade <b>sempre</b> atualizada de tudo</p>
            </div>
          </div>
          {/* Membros */}
          <div className="about-card" tabIndex={0}>
            <Image src={withBasePath("/frontPage/aboutImages/about_members.jpg")} alt="Setembro 2005" width={300} height={420}/>
            <div className="overlay top" aria-hidden="false">
              <h3 style={{textAlign: "end"}}>Mais de 70 Membros</h3>
              <p className="more" style={{marginTop: "55%"}}>A trabalhar para aprender, crescer e dar de tudo para dar a melhor experiência à comunidade</p>
            </div>
          </div>
          {/* Atividades */}
          <div className="about-card" tabIndex={0}>
            <Image src={withBasePath("/frontPage/aboutImages/about_activities.jpg")} alt="Setembro 2005" width={300} height={420}/>
            <div className="overlay top" aria-hidden="false">
              <h3>Diversas Atividades por Ano!</h3>
              <p className="more" style={{textAlign: "center", marginTop: "10%"}}>Promovendo a cultura do Técnico, programas de Voluntariado, WorkShops, Quizz Nights, e <b>muito mais!</b></p>
            </div>
          </div>
        </div>
      </div>
        {/* Mensagem da presidente */}
        <PresidentMessage />

      {/* NEB Letter Sample */}
      <div>
        <h1 style={{marginLeft: "7vw", marginTop: "5vw"}}>Últimos NebLetters!</h1>
        <NebLetterGallery/>
      </div>

      {/* Activities Section */}
      <div>

      </div>
    </>
  );
}

/* Some information and Considerations: 

1. The "Quem Somos" or About photos are recommended to have a specific ratio, being the ratio of the A4 paper. 
The size doesn't matter, it automatically scales, by clamping, to the right size




*/