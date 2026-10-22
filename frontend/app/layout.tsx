import type { Metadata, Viewport } from "next";
import "./globals.css";
import SyncBootstrap from "./sync-bootstrap";

export const metadata: Metadata = {
  title: "NEXORA Distribution OS",
  description: "Warehouse → Vehicle → Route → Customer → Sale → Collection → Settlement",
  applicationName: "NEXORA Distribution OS",
  manifest: "/manifest.webmanifest",
  icons: {
    icon: "/icons/nexora.svg",
    apple: "/icons/nexora.svg",
  },
};

export const viewport: Viewport = {
  themeColor: "#0b1730",
  width: "device-width",
  initialScale: 1,
  viewportFit: "cover",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="ar" dir="rtl">
      <body><SyncBootstrap />{children}</body>
    </html>
  );
}
