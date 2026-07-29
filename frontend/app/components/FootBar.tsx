"use client";

import Link from "next/link";
import Image from "next/image";
import "./footbar.css";
import { withBasePath } from "../utils/withBasePath";

export default function FootBar() {
  return (
    <footer className="neb-footbar" role="contentinfo" aria-label="Site footer">
      <div className="neb-footbar-inner">
        <div className="neb-footbar-left">
          <Link href="/" aria-label="NEB home" className="neb-footbar-logo">
            <Image src={withBasePath("/logohorizontal.png")} alt="NEB logo" width={140} height={40} style={{ objectFit: "contain" , backgroundColor: "black", borderRadius: "5px"}} />
          </Link>
          <div className="neb-footbar-copy">© {new Date().getFullYear()} NEBIST</div>
        </div>


        <div className="neb-footbar-right" aria-hidden="false">
          <div className="neb-footbar-socials" aria-label="Social links">
            <a href="https://www.facebook.com/NEBIST/" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
              <Image src={withBasePath("/footbar/facebook.png")} alt="facebook" width={28} height={28} />
            </a>
            <a href="https://www.linkedin.com/company/nebist---n%C3%BAcleo-de-engenharia-biol%C3%B3gica-do-instituto-superior-t%C3%A9cnico" target="_blank" rel="noopener noreferrer" aria-label="LinkedIn">
              <Image src={withBasePath("/footbar/linkedin.png")} alt="linkedin" width={28} height={28} />
            </a>
            <a href="https://www.instagram.com/neb.ist/" target="_blank" rel="noopener noreferrer" aria-label="Instagram">
              <Image src={withBasePath("/footbar/instagram.png")} alt="instagram" width={28} height={28} />
            </a>
          </div>

          <div className="neb-footbar-partners" aria-hidden="true">
            <a href="https://tecnico.ulisboa.pt" target="_blank" rel="noopener noreferrer">
              <Image src={withBasePath("/footbar/ist.webp")} alt="IST" width={60} height={28} />
            </a>
            <a href="https://www.ulisboa.pt" target="_blank" rel="noopener noreferrer">
              <Image src={withBasePath("/footbar/ulisboa.webp")} alt="ULisboa" width={60} height={28} />
            </a>
          </div>
        </div>
      </div>
    </footer>
  );
}