import type { Metadata } from "next";
import "./globals.css";
import SyncBootstrap from "./sync-bootstrap";
import AppShell from "./app-shell";

export const metadata: Metadata = {
  title: "NEXORA Distribution OS",
  description: "Warehouse → Vehicle → Route → Customer → Sale → Collection → Settlement",
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="ar" dir="rtl">
      <body><SyncBootstrap /><AppShell>{children}</AppShell></body>
    </html>
  );
}
