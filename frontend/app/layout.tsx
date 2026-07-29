import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import "./globals.css";
import Navbar from "./components/Navbar";
import { Background } from "./components/Background";
import FootBar from "./components/FootBar";
import { withBasePath } from "./utils/withBasePath";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const viewport = {
  width: "device-width",
  initialScale: 1,
};

export const metadata: Metadata = {
  title: "NEBIST",
  description: "Núcleo de Engenharia Biológica Instituto Superior Técnico",
  icons: {
    icon: [
      { rel: "icon", url: withBasePath("/icon.svg"), type: "image/svg+xml", sizes: "any" },
      { rel: "icon", url: withBasePath("/icon.ico"), type: "image/x-icon" },
    ],
    shortcut: withBasePath("/icon.ico"),
  },
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="en">
      <body
        suppressHydrationWarning
        className={`${geistSans.variable} ${geistMono.variable} antialiased`}
      >
        <Navbar />
        <Background />
        {children}
        <FootBar />
      </body>
    </html>
  );
}
